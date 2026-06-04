<?php

namespace Tests\Feature\Resilience;

use Tests\TestCase;

/**
 * Phase 16.4 — baseline security headers present on web responses.
 */
class SecurityHeadersTest extends TestCase
{
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
            'broadcasting.connections.reverb.options.host' => 'ws.example.test',
            'broadcasting.connections.reverb.options.scheme' => 'https',
            'broadcasting.connections.reverb.options.port' => 443,
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
}
