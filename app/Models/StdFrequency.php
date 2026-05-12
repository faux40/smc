<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\StdFrequencyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StdFrequency extends Model
{
    /** @use HasFactory<StdFrequencyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['org_id', 'name', 'repeat_days'];

    protected $casts = [
        'repeat_days' => 'integer',
    ];
}
