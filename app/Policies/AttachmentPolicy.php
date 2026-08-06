<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Uploading, gated by what the parent is. Open to any org member for most
     * parents — a class's sign-in sheet, a user's own documents — because the
     * parent itself is theirs to work on. A `Training` is the exception: the
     * library is Owner/SA/Admin managed, so its supporting material (the deck,
     * handouts, test forms) follows the same bar. Reading stays open, since an
     * instructor needs the handouts without managing them.
     *
     * Same-org is checked by the caller, which has already resolved the parent.
     */
    public function create(User $actor, Model $parent): bool
    {
        if ($parent instanceof Training) {
            return $actor->hasAnyRole(self::ADMIN_ROLES);
        }

        return true;
    }

    public function update(User $actor, Attachment $att): bool
    {
        if ($actor->org_id !== $att->org_id) {
            return false;
        }

        $parent = $att->attachable;

        // After the parent class is completed ("closed"), editing requires an
        // elevated role; before close it's open to any org member (the same
        // bar as uploading).
        if ($parent instanceof TrainingClass && $parent->status === 'completed') {
            return $actor->hasAnyRole(self::ADMIN_ROLES);
        }

        // Metadata on a training's material follows the same bar as adding it,
        // so an ordinary member can't retitle a file they could never upload.
        if ($parent instanceof Training) {
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
