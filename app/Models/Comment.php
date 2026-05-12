<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'commentable_type',
        'commentable_id',
        'author_id',
        'body',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'commentable_type', 'commentable_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
