<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_forgot_password_is_rate_limited_per_ip()
    {
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $response = $this->post(route('password.email'), ['email' => 'nobody@example.com']);
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        $response = $this->post(route('password.email'), ['email' => 'nobody@example.com']);
        $response->assertTooManyRequests();
    }

    public function test_forgot_password_rate_limit_does_not_affect_other_ips()
    {
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('password.email'), ['email' => 'nobody@example.com']);
        }

        $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertTooManyRequests();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(route('password.email'), ['email' => 'nobody@example.com']);

        $this->assertNotEquals(429, $response->getStatusCode());
    }

    public function test_reset_password_is_rate_limited_per_ip()
    {
        $payload = [
            'token' => 'invalid-token',
            'email' => 'nobody@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        for ($i = 0; $i < 6; $i++) {
            $response = $this->post(route('password.update'), $payload);
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        $response = $this->post(route('password.update'), $payload);
        $response->assertTooManyRequests();
    }

    public function test_reset_password_rate_limit_does_not_affect_other_ips()
    {
        $payload = [
            'token' => 'invalid-token',
            'email' => 'nobody@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('password.update'), $payload);
        }

        $this->post(route('password.update'), $payload)->assertTooManyRequests();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(route('password.update'), $payload);

        $this->assertNotEquals(429, $response->getStatusCode());
    }
}
