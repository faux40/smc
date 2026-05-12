<?php

namespace Tests\Feature\Seeding;

use App\Models\Organization;
use Database\Seeders\TemplateOrgSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The template org is a sentinel row keyed at UUID 00000000-...-000.
 * New-user-creates-new-org transactions clone it as a starting point so
 * we have a single place to seed org-default rows (std_frequencies, etc.)
 * in later phases. Must be idempotent — multiple runs leave it intact.
 */
class TemplateOrgSeederTest extends TestCase
{
    use RefreshDatabase;

    public const TEMPLATE_ORG_ID = '00000000-0000-0000-0000-000000000000';

    public function test_seeder_creates_template_org_at_zero_uuid(): void
    {
        $this->seed(TemplateOrgSeeder::class);

        $template = Organization::withTrashed()->find(self::TEMPLATE_ORG_ID);

        $this->assertNotNull($template);
        $this->assertSame('Template Organization', $template->name);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(TemplateOrgSeeder::class);
        $this->seed(TemplateOrgSeeder::class);
        $this->seed(TemplateOrgSeeder::class);

        $this->assertSame(
            1,
            Organization::withTrashed()->where('id', self::TEMPLATE_ORG_ID)->count(),
        );
    }
}
