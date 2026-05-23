<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SPA refreshes a stale CSRF token from this endpoint (see the axios
 * 419-retry interceptor). It must return the live session token so a retried
 * mutation passes VerifyCsrfToken.
 */
class CsrfTokenEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_current_session_token(): void
    {
        $response = $this->get('/csrf-token');

        $response->assertOk();
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        // Must be the live session token so a retried POST passes CSRF.
        $this->assertSame(csrf_token(), $token);
    }
}
