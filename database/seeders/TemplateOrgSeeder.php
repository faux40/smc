<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Seeds the template Organization row at UUID 00000000-0000-0000-0000-000000000000.
 *
 * The new-user-creates-new-org transaction clones this row so org defaults
 * (std_frequencies, future template rows) can be seeded centrally. Idempotent
 * via `updateOrCreate` so re-running the seeder leaves the row intact.
 */
class TemplateOrgSeeder extends Seeder
{
    public const TEMPLATE_ORG_ID = '00000000-0000-0000-0000-000000000000';

    public function run(): void
    {
        Organization::withTrashed()->updateOrCreate(
            ['id' => self::TEMPLATE_ORG_ID],
            [
                'name' => 'Template Organization',
                'owner_user_id' => null,
                'deleted_at' => null,
            ],
        );
    }
}
