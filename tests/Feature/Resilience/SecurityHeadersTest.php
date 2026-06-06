<?php

namespace Tests\Feature\Resilience;

use Tests\TestCase;

/**
 * Phase 16.4 — baseline security headers present on web responses.
 */
class SecurityHeadersTest extends TestCase
{
    private ?string $hotFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotFile = public_path('hot');
    }

    protected function tearDown(): void
    {
        // Remove any hot file written during a test so other tests are unaffected.
        if (file_exists($this->hotFile)) {
            @unlink($this->hotFile);
        }
        parent::tearDown();
    }


    public function test_baseline_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_is_absent_over_plain_http(): void
    {
        // Local/dev http must not get HSTS (would pin browsers to https).
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_csp_is_report_only_and_allows_configured_external_origins(): void
    {
        // External hosts are config-sourced (no hard-coded URLs) — set them so
        // the assertions don't depend on the local .env.
        config([
            'filesystems.disks.linode.endpoint' => 'https://objects.example.test',
            // Browser-facing reverb host (what the Echo client connects to),
            // which can differ from the server-side broadcast host.
            'broadcasting.connections.reverb.browser.host' => 'ws.example.test',
            'broadcasting.connections.reverb.browser.scheme' => 'https',
            'broadcasting.connections.reverb.browser.port' => 443,
        ]);

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($csp, 'Report-Only CSP header should be present');

        // Rollout is Report-Only first — not enforcing yet.
        $response->assertHeaderMissing('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        // Linode endpoint allowed for AttachmentViewer previews.
        $this->assertStringContainsString('https://objects.example.test', $csp);
        $this->assertMatchesRegularExpression('/frame-src[^;]*objects\.example\.test/', $csp);
        $this->assertMatchesRegularExpression('/img-src[^;]*objects\.example\.test/', $csp);
        // Reverb websocket allowed for realtime.
        $this->assertMatchesRegularExpression('/connect-src[^;]*wss:\/\/ws\.example\.test/', $csp);
        // Violations are reported somewhere.
        $this->assertStringContainsString('report-uri /api/csp-report', $csp);
    }

    public function test_csp_report_endpoint_accepts_violation_reports(): void
    {
        $this->postJson('/api/csp-report', [
            'csp-report' => [
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example',
            ],
        ])->assertNoContent();
    }

    /**
     * The Vite dev-server origin is read from public/hot, not hard-coded, so
     * Docker Vite (port 5175) and local Vite (5173) both produce correct CSP
     * without any config change.
     */
    public function test_csp_vite_origins_are_read_from_hot_file(): void
    {
        // Simulate Docker Vite writing its URL into public/hot.
        file_put_contents($this->hotFile, 'http://localhost:5175');

        $this->app->detectEnvironment(fn () => 'local');

        $csp = $this->get('/')->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($csp);

        // All three directives that load Vite assets must include the origin.
        $this->assertMatchesRegularExpression('/script-src[^;]*localhost:5175/', $csp);
        $this->assertMatchesRegularExpression('/style-src[^;]*localhost:5175/', $csp);
        $this->assertMatchesRegularExpression('/font-src[^;]*localhost:5175/', $csp);
        // HMR websocket upgrade also needs the origin.
        $this->assertMatchesRegularExpression('/connect-src[^;]*ws:\/\/localhost:5175/', $csp);
        // 127.0.0.1 alias included.
        $this->assertMatchesRegularExpression('/script-src[^;]*127\.0\.0\.1:5175/', $csp);

        // The hard-coded 5173 should NOT appear — it was replaced by hot-file detection.
        $this->assertStringNotContainsString('5173', $csp);
    }

    public function test_csp_vite_origins_absent_when_no_hot_file(): void
    {
        // Ensure hot file is absent (setUp/tearDown handles cleanup but be explicit).
        if (file_exists($this->hotFile)) {
            unlink($this->hotFile);
        }

        $csp = $this->get('/')->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($csp);

        // No dev-server port should appear at all.
        $this->assertStringNotContainsString('5173', $csp);
        $this->assertStringNotContainsString('5175', $csp);
    }
}
