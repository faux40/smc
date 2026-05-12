<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's `org_id` as the container value
 * `currentOrgId`. Consumed by the `BelongsToOrganization` global scope
 * to filter tenant-scoped queries.
 *
 * Runs in the `web` middleware group, after authentication resolves.
 * No-ops when the request has no authenticated user — the scope itself
 * is a no-op in that case.
 */
class SetCurrentOrgId
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && isset($user->org_id)) {
            app()->instance('currentOrgId', $user->org_id);
        }

        return $next($request);
    }
}
