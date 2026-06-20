<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\TrainingClass;
use App\Models\User;

/**
 * Attachments parallel comments: read is open to any org member, delete
 * is uploader OR admin+. The file bytes are never editable (swap = delete +
 * re-upload), but the Type/Description metadata is: open like uploading,
 * except once the parent (e.g. a class) is closed only elevated roles may edit.
 */
class AttachmentPolicy
{
    private const ADMIN_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function view(User $actor, Attachment $att): bool
    {
        return $actor->org_id === $att->org_id;
    }

    public function update(User $actor, Attachment $att): bool
    {
        if ($actor->org_id !== $att->org_id) {
            return false;
        }

        // After the parent class is completed ("closed"), editing requires an
        // elevated role; before close it's open to any org member (the same
        // bar as uploading).
        $parent = $att->attachable;

        if ($parent instanceof TrainingClass && $parent->status === 'completed') {
            return $actor->hasAnyRole(self::ADMIN_ROLES);
        }

        return true;
    }

    public function delete(User $actor, Attachment $att): bool
    {
        if ($actor->org_id !== $att->org_id) {
            return false;
        }

        return $actor->id === $att->uploaded_by_user_id
            || $actor->hasAnyRole(self::ADMIN_ROLES);
    }
}
