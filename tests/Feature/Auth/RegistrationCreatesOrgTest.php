<?php

namespace Tests\Feature\Auth;

use App\Events\OrganizationCreated;
use App\Events\UserRegistered;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RegistrationCreatesOrgTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_register_creates_user_and_org_in_transaction(): void
    {
        $this->post(route('register'), [
            'f_name' => 'Ada',
            'l_name' => 'Lovelace',
            'org_name' => 'Acme Co',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $org = Organization::find($user->org_id);

        $this->assertNotNull($org);
        $this->assertSame('Acme Co', $org->name);
        $this->assertSame($user->id, $org->owner_user_id);
        $this->assertTrue($user->hasRole('Owner'));
    }

    public function test_register_seeds_the_standard_frequency_set(): void
    {
        $this->post(route('register'), [
            'f_name' => 'Ada',
            'l_name' => 'Lovelace',
            'org_name' => 'Acme Co',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $org = Organization::where('name', 'Acme Co')->firstOrFail();

        $names = \App\Models\StdFrequency::withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->pluck('name')
            ->all();

        $this->assertEqualsCanonicalizing(
            array_column(\App\Models\StdFrequency::STANDARD, 'name'),
            $names,
        );
        $this->assertContains('Every 5 Years', $names); // includes the new multi-year options
    }

    public function test_register_requires_org_name(): void
    {
        $this->from(route('register'))
            ->post(route('register'), [
                'f_name' => 'Ada',
                'l_name' => 'L',
                'email' => 'a@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('org_name');
    }

    public function test_register_broadcasts_organization_created_and_user_registered(): void
    {
        Event::fake([OrganizationCreated::class, UserRegistered::class]);

        $this->post(route('register'), [
            'f_name' => 'Ada',
            'l_name' => 'Lovelace',
            'org_name' => 'Acme',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        Event::assertDispatched(OrganizationCreated::class);
        Event::assertDispatched(UserRegistered::class);
    }
}
