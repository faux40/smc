<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasTags;
use Database\Factories\RequirementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requirement extends Model
{
    /** @use HasFactory<RequirementFactory> */
    use BelongsToOrganization, HasAttachments, HasComments, HasFactory, HasTags, HasUuids, SoftDeletes;

    protected $fillable = ['org_id', 'name', 'description'];

    public function elements(): HasMany
    {
        return $this->hasMany(RqmtElement::class, 'requirement_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'requirement_id');
    }

    /**
     * Count this requirement's elements by their timing type. Drives the
     * element-timing summary shown on assignment pills (a requirement can
     * mix repeating / initial-only / as-needed elements, so callers render
     * the breakdown rather than a single label). Reads the loaded
     * `elements` relation — eager-load it to avoid N+1.
     *
     * @return array{initial: int, repeating: int, as_needed: int, none: int}
     */
    public function elementTimingSummary(): array
    {
        $summary = ['initial' => 0, 'repeating' => 0, 'as_needed' => 0, 'none' => 0];

        foreach ($this->elements as $element) {
            if ($element->initial_only) {
                $summary['initial']++;
            } elseif ($element->repeating) {
                $summary['repeating']++;
            } elseif ($element->as_needed) {
                $summary['as_needed']++;
            } else {
                $summary['none']++;
            }
        }

        return $summary;
    }
}
