<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One org's value for a merge field, per variation. `location` /
 * `department` empty strings mean "org-wide default"; labeled rows
 * override the default when generating a document for that variation
 * (resolution ladder lives in App\Support\MergeData\MergeValueResolver).
 *
 * No SoftDeletes on purpose: clearing an override is a hard delete —
 * the (org, field, location, department) unique index would otherwise
 * collide with re-setting the value.
 */
class MergeValue extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'org_id', 'merge_field_id', 'location', 'department', 'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(MergeField::class, 'merge_field_id');
    }
}
