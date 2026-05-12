<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Auth callback for the `org.{orgId}` private channel.
 *
 * Granted when: actor is same-org AND has a password (no-login users
 * have no business on a private broadcast channel — they're tracked
 * for compliance, not interactive).
 *
 * Class-based so the join() method is directly unit-testable. Hitting
 * /broadcasting/auth in tests short-circuits under the null broadcaster.
 */
class OrgChannel
{
    public function join(User $user, string $orgId): bool
    {
        return $user->org_id === $orgId && $user->password !== null;
    }
}
