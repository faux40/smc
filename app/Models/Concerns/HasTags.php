<?php

namespace App\Models\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Drop on any tenant-scoped model to make it taggable.
 * Tags are many-to-many polymorphic via the `taggables` pivot.
 */
trait HasTags
{
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
