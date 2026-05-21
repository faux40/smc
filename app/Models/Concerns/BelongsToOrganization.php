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

    /**
     * Org-scope route-model binding. `SetCurrentOrgId` binds `currentOrgId`
     * only after `SubstituteBindings` runs, so the global scope above no-ops
     * during binding and a cross-org id would otherwise resolve (caught later
     * by the policy as a 403). Rejecting a resolved model whose org doesn't
     * match the authenticated user makes a cross-org id 404 outright — defense
     * in depth that doesn't depend on every endpoint having a policy.
     *
     * We override the higher-level resolveRouteBinding (not ...Query) to avoid
     * a trait collision with HasUuids — and to keep its UUID-format validation
     * via the parent call. Recursion-safe: binding runs after `Authenticate`
     * (middleware priority over `SubstituteBindings`), so `auth()->user()` is
     * already resolved and cached.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $model = parent::resolveRouteBinding($value, $field);

        $orgId = auth()->user()?->org_id;
        if ($model !== null && $orgId !== null && $model->org_id !== $orgId) {
            return null;
        }

        return $model;
    }
}
