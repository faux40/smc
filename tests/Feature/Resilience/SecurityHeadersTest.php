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
}
