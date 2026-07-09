<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 16.4 — baseline security response headers + Content-Security-Policy.
 *
 * F13 — the CSP ran Report-Only first (Content-Security-Policy-Report-Only)
 * while violations were collected at /api/csp-report; a static audit found
 * the policy complete (no inline scripts, no external CDNs/fonts/analytics,
 * no eval/worker/blob outside local dev), so it now ships as the enforcing
 * `Content-Security-Policy` header — the browser blocks non-conforming
 * resources instead of merely reporting them. Violations still POST to
 * /api/csp-report via `report-uri` so regressions are caught. External
 * origins (Linode object store, Reverb websocket) are sourced from config,
 * never hard-coded, so swapping providers is an env change. HSTS is only
 * emitted over HTTPS so local http dev is unaffected.
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
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

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
        $viteOrigins = $isLocal ? $this->viteDevOrigins() : [];
        $viteEval = $isLocal ? ["'unsafe-eval'"] : [];
        $viteWs = array_map(
            fn (string $o) => str_replace(['http://', 'https://'], ['ws://', 'wss://'], $o),
            $viteOrigins,
        );

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'script-src' => array_merge(["'self'"], $viteEval, $viteOrigins),
            // Vue binds dynamic inline style attributes (tag colours, layout),
            // and style injection is low-risk, so 'unsafe-inline' is pragmatic.
            'style-src' => array_merge(["'self'", "'unsafe-inline'"], $viteOrigins),
            'img-src' => array_merge(["'self'", 'data:'], $linode),
            'font-src' => array_merge(["'self'", 'data:'], $viteOrigins),
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
     * Read the actual Vite dev-server origin from public/hot (the file Vite
     * writes when its server starts). Returns both the primary origin and the
     * 127.0.0.1 equivalent so the browser can reach it either way.
     *
     * When there is no hot file (production / staging static assets) it returns
     * an empty array — no dev-server origins are needed.
     *
     * Handles both local Vite (port 5173) and Docker Vite (port 5175) without
     * any hard-coded port, because the port is read directly from the file.
     *
     * @return list<string>
     */
    private function viteDevOrigins(): array
    {
        $hotFile = public_path('hot');

        if (! file_exists($hotFile)) {
            return [];
        }

        $raw = trim((string) file_get_contents($hotFile));
        $parsed = parse_url($raw);
        $scheme = $parsed['scheme'] ?? 'http';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return [
            $scheme.'://localhost'.$port,
            $scheme.'://127.0.0.1'.$port,
        ];
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
