<?php

namespace Tests\Unit\Models;

use App\Models\Concerns\BelongsToOrganization;
use Tests\TestCase;

/**
 * Trait-existence smoke test. Behavior tests (cross-tenant filtering)
 * land in 3.2 when User adopts the trait — we can't meaningfully exercise
 * the global scope without a tenant-scoped model.
 */
class BelongsToOrganizationTraitTest extends TestCase
{
    public function test_trait_exists(): void
    {
        $this->assertTrue(trait_exists(BelongsToOrganization::class));
    }

    public function test_trait_defines_organization_relation_method(): void
    {
        $this->assertTrue(method_exists(BelongsToOrganization::class, 'organization'));
    }
}
