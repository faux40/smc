<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A DOCX/ODT master document template with `${key}` merge placeholders.
 * Two scopes share the table (same pattern as MergeField): system
 * templates (`org_id` NULL — the universal library, console-managed)
 * and org-uploaded templates. Replacing a template soft-deletes the old
 * row and chains the new one via `prev_version_id`; files are kept so
 * generation history stays reproducible.
 *
 * Not BelongsToOrganization for the same reason as MergeField: the
 * global scope would hide system rows. {@see scopeVisibleTo()} +
 * the binding override below reproduce the tenancy guarantees.
 */
class DocTemplate extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const EXTENSIONS = ['docx', 'odt'];

    protected $fillable = [
        'org_id', 'name', 'description', 'original_filename', 'extension',
        'path', 'size', 'placeholders', 'version', 'prev_version_id', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'size' => 'integer',
            'version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prev_version_id');
    }

    public function isSystem(): bool
    {
        return $this->org_id === null;
    }

    /** System templates + the given org's own. */
    public function scopeVisibleTo($query, string $orgId)
    {
        return $query->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId));
    }

    /** Mirror of BelongsToOrganization::resolveRouteBinding for nullable org_id. */
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
