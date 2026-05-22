<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SPA's Pinia stores authenticate state-mutating requests with the
 * `X-CSRF-TOKEN` header, which they read from a `<meta name="csrf-token">`
 * in the root document. Without that meta the token is never sent and every
 * store-driven POST/PATCH/DELETE 419s. Guard the meta's presence here so a
 * blade edit can't silently break every mutation again.
 */
class CsrfMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_root_document_exposes_a_csrf_token_meta(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->withRole('Owner')->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('name="csrf-token"', escape: false);
        // The content must be the live session token, not an empty string.
        $response->assertSee('content="'.csrf_token().'"', escape: false);
    }
}
