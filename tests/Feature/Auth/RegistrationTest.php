<?php

namespace Tests\Feature\Auth;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
        $this->seed(RoleSeeder::class);
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'f_name' => 'Test',
            'l_name' => 'User',
            'org_name' => 'Test Org',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_is_rate_limited_per_ip()
    {
        // Deliberately invalid (missing password confirmation) so no
        // request ever succeeds/authenticates — that would trip the
        // `guest` middleware on later iterations and mask the throttle.
        $payload = [
            'f_name' => 'Flood',
            'l_name' => 'Bot',
            'org_name' => 'Flood Org',
            'email' => 'flood@example.com',
            'password' => 'password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post(route('register.store'), $payload);
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        $response = $this->post(route('register.store'), $payload);
        $response->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_registration_rate_limit_does_not_affect_other_ips()
    {
        $payload = [
            'f_name' => 'Flood',
            'l_name' => 'Bot',
            'org_name' => 'Flood Org',
            'email' => 'flood@example.com',
            'password' => 'password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register.store'), $payload);
        }

        $this->post(route('register.store'), $payload)->assertTooManyRequests();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(route('register.store'), $payload);

        $this->assertNotEquals(429, $response->getStatusCode());
    }
}
