<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The printable geometry of a purchased card sheet — page size, the grid of
 * cells, and the spacing around them. Cards are merged one-per-template and
 * imposed onto this grid, so precision lives here rather than in the user's
 * template. Every measurement is in points (1/72in).
 *
 * Two scopes share one table, exactly like {@see MergeField}:
 *
 *  - system stocks (`org_id` NULL): the common purchased layouts, visible to
 *    every org, managed via console/seeder until site-admin tooling exists;
 *  - org stocks (`org_id` set): defined by that org's Admins.
 *
 * Deliberately does NOT use BelongsToOrganization: its global scope would
 * hide system rows. Queries scope explicitly via {@see scopeVisibleTo()}, and
 * {@see resolveRouteBinding()} reproduces the trait's cross-org 404 guard.
 */
class CardStock extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const DUPLEX_FLIPS = ['long_edge', 'short_edge'];

    protected $fillable = [
        'org_id', 'name',
        'page_width', 'page_height',
        'column_count', 'row_count',
        'card_width', 'card_height',
        'margin_top', 'margin_left',
        'gutter_x', 'gutter_y',
        'offset_x', 'offset_y',
        'duplex_flip', 'notes',
    ];

    /**
     * Floats, not `decimal:3` — the geometry helper does arithmetic on these
     * and Laravel's decimal cast returns strings.
     */
    protected function casts(): array
    {
        return [
            'page_width' => 'float',
            'page_height' => 'float',
            'card_width' => 'float',
            'card_height' => 'float',
            'margin_top' => 'float',
            'margin_left' => 'float',
            'gutter_x' => 'float',
            'gutter_y' => 'float',
            'offset_x' => 'float',
            'offset_y' => 'float',
            'column_count' => 'integer',
            'row_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function isSystem(): bool
    {
        return $this->org_id === null;
    }

    /**
     * System stocks + the given org's own — the set an org can see.
     */
    public function scopeVisibleTo($query, string $orgId)
    {
        return $query->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId));
    }

    /**
     * Mirror of BelongsToOrganization::resolveRouteBinding, adapted for the
     * nullable org_id: a foreign org's stock 404s outright; system stocks
     * resolve — whether the actor may edit one is the policy's call.
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
