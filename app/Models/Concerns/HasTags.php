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
        $relation = $this->morphToMany(Tag::class, 'taggable')->withPivot('org_id');

        // withPivotValue both filters reads and stamps `org_id` onto the row on
        // attach, so no call site can forget it (`taggables.org_id` is NOT NULL).
        //
        // Applied only when this instance actually has an org: eager loading
        // builds the relation from an empty model — Builder::getRelation() calls
        // newInstance() — where org_id is null, and constraining on null there
        // would silently match nothing. Reads from that path stay covered by
        // Tag's `organization` global scope, which is what they relied on before.
        return $this->org_id === null
            ? $relation
            : $relation->withPivotValue('org_id', $this->org_id);
    }
}
