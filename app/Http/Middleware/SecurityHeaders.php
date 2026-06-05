<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 16.4 — baseline security response headers + Content-Security-Policy.
 *
 * The CSP ships as **Report-Only first** (Content-Security-Policy-Report-Only):
 * the browser reports violations to /api/csp-report but blocks nothing, so a
 * missed directive can't white-screen prod. Flip to the enforcing
 * `Content-Security-Policy` header once reports are clean. External origins
 * (Linode object store, Reverb websocket) are sourced from config, never
 * hard-coded, so swapping providers is an env change. HSTS is only emitted
 * over HTTPS so local http dev is unaffected.
 */
class SecurityHeaders
{
    private const REPORT_PATH = '/api/csp-report';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Content-Security-Policy-Report-Only', $this->contentSecurityPolicy());

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $linode = $this->originSources(config('filesystems.disks.linode.endpoint'));
        $reverb = $this->reverbWebSocketSources();

        // Local dev only: Vite's HMR client loads from the dev server and uses
        // eval; allow it so the dev console isn't flooded. Prod/staging/testing
        // get the stricter policy.
        $isLocal = app()->environment('local');
        $viteHttp = $isLocal ? ['http://localhost:5173', 'http://127.0.0.1:5173'] : [];
        $viteEval = $isLocal ? ["'unsafe-eval'"] : [];
        $viteWs = $isLocal ? ['ws://localhost:5173', 'ws://127.0.0.1:5173'] : [];

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'script-src' => array_merge(["'self'"], $viteEval, $viteHttp),
            // Vue binds dynamic inline style attributes (tag colours, layout),
            // and style injection is low-risk, so 'unsafe-inline' is pragmatic.
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => array_merge(["'self'", 'data:'], $linode),
            'font-src' => ["'self'", 'data:'],
            'connect-src' => array_merge(["'self'"], $reverb, $viteWs),
            'frame-src' => array_merge(["'self'"], $linode),
            'media-src' => array_merge(["'self'"], $linode),
            'worker-src' => ["'self'", 'blob:'],
        ];

        $policy = collect($directives)
            ->map(fn (array $sources, string $name): string => $name.' '.implode(' ', $sources))
            ->implode('; ');

        return $policy.'; report-uri '.self::REPORT_PATH;
    }

    /**
     * Turn a configured base URL (e.g. the Linode endpoint) into a CSP origin
     * source like `https://us-lax-1.linodeobjects.com`. Returns [] when unset.
     *
     * @return list<string>
     */
    private function originSources(?string $url): array
    {
        if (! is_string($url) || $url === '') {
            return [];
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! $scheme || ! $host) {
            return [];
        }

        $port = parse_url($url, PHP_URL_PORT);

        return [$scheme.'://'.$host.($port ? ':'.$port : '')];
    }

    /**
     * The wss:// origin(s) the browser opens to Reverb. Sourced from the
     * **browser-facing** config (the VITE_* build vars the Echo client uses),
     * not the server-side broadcast host — the two can differ. Includes the
     * explicit port only when it's non-default.
     *
     * @return list<string>
     */
    private function reverbWebSocketSources(): array
    {
        $options = config('broadcasting.connections.reverb.browser', []);
        $host = is_array($options) ? ($options['host'] ?? null) : null;

        if (! is_string($host) || $host === '') {
            return [];
        }

        $scheme = ($options['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $port = $options['port'] ?? null;

        $sources = [$scheme.'://'.$host];

        if ($port && ! in_array((int) $port, [80, 443], true)) {
            $sources[] = $scheme.'://'.$host.':'.$port;
        }

        return $sources;
    }
}
