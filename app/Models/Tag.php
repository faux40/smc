<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['org_id', 'name', 'color'];
}
