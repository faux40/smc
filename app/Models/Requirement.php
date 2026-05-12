<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RequirementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requirement extends Model
{
    /** @use HasFactory<RequirementFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['org_id', 'name', 'description'];

    public function elements(): HasMany
    {
        return $this->hasMany(RqmtElement::class, 'requirement_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'requirement_id');
    }
}
