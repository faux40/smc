<?php

namespace Tests\Feature\Cards;

use App\Models\Organization;
use App\Models\User;
use App\Support\Cards\CardMergeKeys;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The built-in `${key}` catalogue a card designer copies from (custom-certs
 * C4e). Served from the same constant the merge reads, so the list on screen
 * cannot drift from the keys that actually resolve.
 */
class CardMergeKeysApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->org = Organization::factory()->create();
    }

    public function test_a_manager_reads_the_grouped_catalogue(): void
    {
        // Managers print the cards, so they need the vocabulary too — the same
        // audience as the template library itself.
        $manager = $this->user('Manager');

        $json = $this->actingAs($manager)
            ->getJson(route('card-merge-keys.index'))
            ->assertOk()
            ->json();

        $person = collect($json)->firstWhere('group', 'Person');

        $this->assertNotNull($person);
        $this->assertContains('first_name', array_column($person['keys'], 'key'));
    }

    public function test_each_key_carries_the_placeholder_to_paste(): void
    {
        $json = $this->actingAs($this->user('Admin'))
            ->getJson(route('card-merge-keys.index'))
            ->assertOk()
            ->json();

        $keys = collect($json)->flatMap(fn (array $g) => $g['keys'])->keyBy('key');

        // What the author types into the slide, rendered the same way a custom
        // field's placeholder is.
        $this->assertSame('${first_name}', $keys['first_name']['placeholder']);
    }

    public function test_it_lists_every_key_the_merge_knows(): void
    {
        // The anti-drift contract: a key added to the catalogue shows up here
        // without anyone remembering to update a second list.
        $json = $this->actingAs($this->user('Admin'))
            ->getJson(route('card-merge-keys.index'))
            ->assertOk()
            ->json();

        $listed = collect($json)->flatMap(fn (array $g) => array_column($g['keys'], 'key'))->all();

        $this->assertEqualsCanonicalizing(CardMergeKeys::all(), $listed);
    }

    public function test_a_self_view_user_is_refused(): void
    {
        $this->actingAs($this->user('SelfView'))
            ->getJson(route('card-merge-keys.index'))
            ->assertForbidden();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson(route('card-merge-keys.index'))->assertUnauthorized();
    }

    private function user(string $role): User
    {
        return User::factory()->for($this->org, 'organization')->withRole($role)->create();
    }
}
