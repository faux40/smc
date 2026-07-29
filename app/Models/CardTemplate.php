<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded card design: ONE card, slide 1 the front and an optional slide
 * 2 the back. SMC imposes the sheet itself, so the slide dimensions are the
 * card size — read from the file at upload, never typed.
 *
 * Two scopes share the table like {@see DocTemplate} and {@see CardStock}:
 * system templates (`org_id` NULL) and an org's own. Kept in its own table
 * rather than a `kind` column on doc_templates because doc-template upload
 * auto-registers unknown `${keys}` as draft org merge fields, which is wrong
 * for cards — their keys come from the class/user catalogue and the
 * training's own custom fields.
 */
class CardTemplate extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'description',
        'original_filename', 'extension', 'path', 'size',
        'placeholders', 'fonts', 'unsupported_fonts',
        'slide_count', 'card_width', 'card_height',
        'version', 'prev_version_id', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'fonts' => 'array',
            'unsupported_fonts' => 'array',
            'size' => 'integer',
            'slide_count' => 'integer',
            'version' => 'integer',
            // Floats for the same reason as CardStock: these get compared
            // against the stock's cell size, not just displayed.
            'card_width' => 'float',
            'card_height' => 'float',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isSystem(): bool
    {
        return $this->org_id === null;
    }

    /** Two slides = front and back; one = single-sided. */
    public function hasBack(): bool
    {
        return $this->slide_count === 2;
    }

    /** System templates + the given org's own. */
    public function scopeVisibleTo($query, string $orgId)
    {
        return $query->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId));
    }

    /**
     * Mirror of BelongsToOrganization::resolveRouteBinding for the nullable
     * org_id: a foreign org's template 404s; system templates resolve and
     * the policy decides what may be done with them.
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
