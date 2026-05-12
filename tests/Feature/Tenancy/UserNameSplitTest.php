<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserNameSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_split_name_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'f_name', 'm_name', 'l_name', 'prefix_name', 'suffix_name',
        ]));
        $this->assertFalse(Schema::hasColumn('users', 'name'));
    }

    public function test_name_accessor_composes_full_name(): void
    {
        $user = User::factory()->create([
            'f_name' => 'Ada',
            'm_name' => 'Augusta',
            'l_name' => 'Lovelace',
            'prefix_name' => null,
            'suffix_name' => null,
        ]);

        $this->assertSame('Ada Augusta Lovelace', $user->name);
    }

    public function test_name_accessor_includes_prefix_and_suffix_when_set(): void
    {
        $user = User::factory()->create([
            'f_name' => 'John',
            'm_name' => null,
            'l_name' => 'Smith',
            'prefix_name' => 'Dr.',
            'suffix_name' => 'III',
        ]);

        $this->assertSame('Dr. John Smith III', $user->name);
    }

    public function test_name_accessor_trims_empty_segments(): void
    {
        $user = User::factory()->create([
            'f_name' => 'Frank',
            'm_name' => null,
            'l_name' => 'Forklift',
            'prefix_name' => null,
            'suffix_name' => null,
        ]);

        $this->assertSame('Frank Forklift', $user->name);
    }

    public function test_name_appears_in_array_serialization(): void
    {
        $user = User::factory()->create([
            'f_name' => 'Ada',
            'l_name' => 'Lovelace',
        ]);

        $this->assertArrayHasKey('name', $user->toArray());
        $this->assertSame('Ada Lovelace', $user->toArray()['name']);
    }
}
