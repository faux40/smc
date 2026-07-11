<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document merge-field definition — the `${key}` vocabulary that doc
 * templates draw from. Two scopes share one table:
 *
 *  - system fields (`org_id` NULL): shipped with universal templates,
 *    visible to every org, managed via console/seeder until site-admin
 *    tooling exists;
 *  - org fields (`org_id` set): defined by that org's Admins.
 *
 * Deliberately does NOT use BelongsToOrganization: its global scope
 * (`org_id = currentOrgId`) would hide system rows. Queries scope
 * explicitly via {@see visibleTo()}, and {@see resolveRouteBinding()}
 * reproduces the trait's cross-org 404 guard (while still resolving
 * system rows — the policy decides what you may do with them).
 */
class MergeField extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const TYPES = ['text', 'multiline', 'date', 'list'];

    protected $fillable = [
        'org_id', 'key', 'label', 'type', 'field_group', 'help', 'seq', 'draft',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'draft' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(MergeValue::class);
    }

    public function isSystem(): bool
    {
        return $this->org_id === null;
    }

    /**
     * System fields + the given org's own fields — the set an org can see.
     */
    public function scopeVisibleTo($query, string $orgId)
    {
        return $query->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId));
    }

    /**
     * Mirror of BelongsToOrganization::resolveRouteBinding, adapted for the
     * nullable org_id: a foreign org's field 404s outright; system fields
     * (org_id NULL) resolve — whether the actor may edit them is the
     * policy's call, not the binding's.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $model = parent::resolveRouteBinding($value, $field);

        $orgId = auth()->user()?->org_id;
        if ($model !== null && $orgId !== null && $model->org_id !== null && $model->org_id !== $orgId) {
            return null;
        }

        return $model;
    }
}
