<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user inbox endpoints (Phase 15.2). Every method scopes implicitly
 * to the authenticated user via the Notifiable trait — there's no
 * cross-user surface here. Cross-org leakage is impossible too: the
 * notifiable relation only resolves your own rows.
 */
class NotificationsController extends Controller
{
    /**
     * Latest 100 notifications for the actor (read + unread, newest
     * first). 100 is the page-1 cap; deeper history can land later via
     * a cursor. Each row carries the persisted data payload from
     * notifications.data so the inbox UI can render directly without
     * a second fetch.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'items' => $rows->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Mark one notification read. 404 (not 403) on cross-user attempts
     * since notifiable_id won't match — the row is effectively invisible.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notification = $user->notifications()->find($id);
        abort_if($notification === null, 404, 'Notification not found.');

        $notification->markAsRead();

        return response()->json([
            'id' => $notification->id,
            'read_at' => $notification->read_at?->toIso8601String(),
        ]);
    }

    /**
     * Bulk mark-as-read for everything the actor has unread.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'marked' => $count,
        ]);
    }
}
