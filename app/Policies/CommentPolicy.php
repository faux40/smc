<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

/**
 * Comments are conversational. Anyone in the org can post + read.
 * Editing is author-only (no one else can edit your words).
 * Deletion is author OR admin+ (lets admins clean up off-topic / abusive
 * content while still preserving own-comment autonomy).
 */
class CommentPolicy
{
    private const ADMIN_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function update(User $actor, Comment $comment): bool
    {
        return $actor->org_id === $comment->org_id
            && $actor->id === $comment->author_id;
    }

    public function delete(User $actor, Comment $comment): bool
    {
        if ($actor->org_id !== $comment->org_id) {
            return false;
        }

        return $actor->id === $comment->author_id
            || $actor->hasAnyRole(self::ADMIN_ROLES);
    }
}
