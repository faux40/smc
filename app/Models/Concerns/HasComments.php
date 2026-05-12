<?php

namespace App\Models\Concerns;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Drop on any tenant-scoped model to make it commentable.
 * Comments are one-to-many polymorphic (each comment points at one subject).
 */
trait HasComments
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
