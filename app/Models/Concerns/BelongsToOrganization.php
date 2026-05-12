<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant scoping by `org_id`. Every model that lives inside an
 * organization uses this trait.
 *
 * Registers a named global scope `'organization'` that filters by
 * `app('currentOrgId')` from the container. The post-auth middleware
 * (`App\Http\Middleware\SetCurrentOrgId`) binds that value from the
 * authenticated user's `org_id`.
 *
 * When the binding is absent (e.g., auth resolution itself, the new-org
 * registration transaction, site-admin tools), the scope is a no-op so
 * cross-tenant work is still possible. Use `withoutGlobalScope('organization')`
 * for explicit unscoped queries.
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            if (! app()->bound('currentOrgId')) {
                return;
            }

            $orgId = app('currentOrgId');
            if ($orgId === null) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.org_id', $orgId);
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
