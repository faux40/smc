<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Drop on any tenant-scoped model to give it attached files.
 * Attachments are one-to-many polymorphic.
 */
trait HasAttachments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
