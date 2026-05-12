<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Attachments parallel comments: read is open to any org member, delete
 * is uploader OR admin+. There's no "edit" — to swap a file, delete the
 * existing record and upload a new one.
 */
class AttachmentPolicy
{
    private const ADMIN_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function view(User $actor, Attachment $att): bool
    {
        return $actor->org_id === $att->org_id;
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
