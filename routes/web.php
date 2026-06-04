<?php

use App\Events\RealtimePing;
use App\Http\Controllers\AssignmentsController;
use App\Http\Controllers\AttachmentsController;
use App\Http\Controllers\BulkAssignmentsController;
use App\Http\Controllers\ClassDocumentsController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\CommentsController;
use App\Http\Controllers\CompletionsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\RequirementsController;
use App\Http\Controllers\RqmtElementsController;
use App\Http\Controllers\StdFrequenciesController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TrainingsController;
use App\Http\Controllers\UsersController;
use App\Models\Requirement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

// Phase 16.5 — detailed liveness for uptime monitors. Public + minimal;
// complements the framework's boots-only `/up`.
Route::get('health/detailed', [HealthController::class, 'detailed'])->name('health.detailed');

// Current session CSRF token, for the SPA to refresh a stale one. The meta
// token is rendered once at page load and goes stale if the page is left open
// a while or the session token is regenerated (e.g. re-auth in another tab),
// which would 419 every store mutation; the axios 419-retry interceptor reads
// this to recover. GET, so it's not itself CSRF-checked; web group → session.
Route::get('csrf-token', fn () => response()->json(['token' => csrf_token()]))
    ->name('csrf-token');

// throttle:240,1 — a generous per-user/IP ceiling (4 req/s sustained) that
// reins in runaway clients/abuse without affecting normal SPA use. Login has
// its own stricter Fortify throttle. Tune as real usage data arrives (16.6).
Route::middleware(['auth', 'verified', 'throttle:240,1'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    // Phase 15.2 in-app inbox. Index returns the actor's last 100
    // notifications + unread count for the bell badge; mark-read flips
    // a single row; mark-all-read flips everything unread for the
    // actor. All implicitly scoped to the authenticated user via the
    // Notifiable relation.
    Route::get('api/notifications', [NotificationsController::class, 'index'])->name('notifications.index');
    Route::post('api/notifications/{id}/read', [NotificationsController::class, 'markRead'])->name('notifications.read');
    Route::post('api/notifications/read-all', [NotificationsController::class, 'markAllRead'])->name('notifications.read-all');

    Route::inertia('notifications', 'notifications/Index')->name('notifications.page');

    // Phase 14 dashboard widget endpoints. One per widget so a later
    // user-prefs phase can add / remove / re-order them without
    // backend surgery. Manager+ via inline role gate in the controller.
    Route::get('api/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    Route::get('api/dashboard/overdue-users', [DashboardController::class, 'overdueUsers'])->name('dashboard.overdue-users');
    Route::get('api/dashboard/due-soon', [DashboardController::class, 'dueSoon'])->name('dashboard.due-soon');
    Route::get('api/dashboard/recent-completions', [DashboardController::class, 'recentCompletions'])->name('dashboard.recent-completions');
    Route::get('api/dashboard/users-compliance', [DashboardController::class, 'usersCompliance'])->name('dashboard.users-compliance');

    Route::get('users', [UsersController::class, 'index'])->name('users.index');
    Route::post('users', [UsersController::class, 'store'])->name('users.store');

    // Lean JSON user list for downstream picker UX (assignment +
    // completion form modals). Manager+ via inline role gate; UsersController
    // viewAny otherwise stays admin-only.
    Route::get('api/users', [UsersController::class, 'pickerList'])->name('users.picker');

    // Phase 13.3 user detail + compliance endpoint. The Inertia page
    // is gated to admin/manager (any user) or self (own user); the
    // JSON endpoint applies the same gate before computing.
    Route::get('users/{user}', [UsersController::class, 'show'])->name('users.show');
    Route::get('api/users/{user}/compliance', [UsersController::class, 'compliance'])->name('users.compliance');
    Route::patch('users/{user}', [UsersController::class, 'update'])->name('users.update');
    Route::post('users/{user}/disable', [UsersController::class, 'disable'])->name('users.disable');
    Route::post('users/{user}/enable', [UsersController::class, 'enable'])->name('users.enable');
    Route::delete('users/{user}', [UsersController::class, 'destroy'])->name('users.destroy');

    // Polymorphic tag API — library CRUD + poly attach/detach. Consumed by
    // the reusable <TagsField> Vue component. Library CRUD is Owner/SA/Admin;
    // attach/detach is open to any auth'd org member.
    Route::get('api/tags', [TagsController::class, 'index'])->name('tags.index');
    Route::post('api/tags', [TagsController::class, 'store'])->name('tags.store');
    Route::patch('api/tags/{tag}', [TagsController::class, 'update'])->name('tags.update');
    Route::delete('api/tags/{tag}', [TagsController::class, 'destroy'])->name('tags.destroy');
    Route::post('api/tags/attach', [TagsController::class, 'attach'])->name('tags.attach');
    Route::post('api/tags/detach', [TagsController::class, 'detach'])->name('tags.detach');

    // Tags library admin page. Read open to any org member (the page
    // hides write UI for non-admins); write API enforces role via policy.
    Route::inertia('tags', 'tags/Index')->name('tags.page');

    // Tag-driven bulk assignment (Phase 13.1 flagship). preview returns
    // the user × requirement cross-product for a chosen tag plus the
    // already-assigned pairs so the matrix UI can pre-lock cells. store
    // takes a hand-picked pairs[] list and creates the missing
    // assignments in one transaction. Manager+ gated via AssignmentPolicy.
    Route::get('api/bulk-assignments/preview', [BulkAssignmentsController::class, 'preview'])->name('bulk-assignments.preview');
    Route::post('api/bulk-assignments', [BulkAssignmentsController::class, 'store'])->name('bulk-assignments.store');
    // Bulk de-assign (Admin+): remove (soft-delete) or end (set end_date) the
    // active assignment for each (user, requirement) pair.
    Route::post('api/bulk-assignments/detach', [BulkAssignmentsController::class, 'detach'])->name('bulk-assignments.detach');

    Route::inertia('workflows/bulk-assignment', 'workflows/BulkAssignment')->name('workflows.bulk-assignment');

    // Polymorphic comment API — consumed by <CommentsList>. Anyone in the
    // org can read/post; author-only edit; author OR admin+ delete.
    Route::get('api/comments', [CommentsController::class, 'index'])->name('comments.index');
    Route::post('api/comments', [CommentsController::class, 'store'])->name('comments.store');
    Route::patch('api/comments/{comment}', [CommentsController::class, 'update'])->name('comments.update');
    Route::delete('api/comments/{comment}', [CommentsController::class, 'destroy'])->name('comments.destroy');

    // Polymorphic attachment API — consumed by <AttachmentsList>. Any org
    // member can read/upload; uploader OR admin+ can delete. Download
    // 302-redirects to a signed temporary URL on the Linode disk.
    Route::get('api/attachments', [AttachmentsController::class, 'index'])->name('attachments.index');
    Route::post('api/attachments', [AttachmentsController::class, 'store'])->name('attachments.store');
    Route::delete('api/attachments/{attachment}', [AttachmentsController::class, 'destroy'])->name('attachments.destroy');
    Route::get('api/attachments/{attachment}/view', [AttachmentsController::class, 'view'])->name('attachments.view');
    Route::get('api/attachments/{attachment}/download', [AttachmentsController::class, 'download'])->name('attachments.download');

    // Std frequencies — per-org timing presets used by downstream forms.
    // Read open to any auth'd org member (everywhere needs the picker);
    // CRUD is Owner/SA/Admin.
    Route::get('api/std-frequencies', [StdFrequenciesController::class, 'index'])->name('std-frequencies.index');
    Route::post('api/std-frequencies', [StdFrequenciesController::class, 'store'])->name('std-frequencies.store');
    Route::patch('api/std-frequencies/{stdFrequency}', [StdFrequenciesController::class, 'update'])->name('std-frequencies.update');
    Route::delete('api/std-frequencies/{stdFrequency}', [StdFrequenciesController::class, 'destroy'])->name('std-frequencies.destroy');

    // Trainings library — first concrete module. Read open to any org
    // member (downstream rqmt_elements pickers need the list); CRUD is
    // Owner/SA/Admin. Inertia page lives at /trainings (Vue route).
    Route::get('api/trainings', [TrainingsController::class, 'index'])->name('trainings.index');
    Route::post('api/trainings', [TrainingsController::class, 'store'])->name('trainings.store');
    Route::patch('api/trainings/{training}', [TrainingsController::class, 'update'])->name('trainings.update');
    Route::delete('api/trainings/{training}', [TrainingsController::class, 'destroy'])->name('trainings.destroy');

    Route::inertia('trainings', 'trainings/Index')->name('trainings.page');

    // Training System (v16) — classes. Manager+ scheduling tool. JSON API
    // backs the Pinia store; Inertia pages are thin shells.
    Route::get('api/classes', [ClassesController::class, 'index'])->name('classes.index');
    Route::post('api/classes', [ClassesController::class, 'store'])->name('classes.store');
    Route::get('api/classes/{class}/certificates', [ClassDocumentsController::class, 'certificates'])->name('classes.certificates');
    Route::get('api/classes/{class}/sign-in-sheet', [ClassDocumentsController::class, 'signInSheet'])->name('classes.sign-in-sheet');
    Route::get('api/classes/{class}/summary', [ClassDocumentsController::class, 'summary'])->name('classes.summary');
    Route::get('api/classes/{class}', [ClassesController::class, 'show'])->name('classes.show');
    Route::patch('api/classes/{class}', [ClassesController::class, 'update'])->name('classes.update');
    Route::delete('api/classes/{class}', [ClassesController::class, 'destroy'])->name('classes.destroy');
    Route::post('api/classes/{class}/trainings', [ClassesController::class, 'attachTraining'])->name('classes.trainings.attach');
    Route::patch('api/classes/{class}/trainings/{classTraining}', [ClassesController::class, 'updateTraining'])->name('classes.trainings.update');
    Route::delete('api/classes/{class}/trainings/{classTraining}', [ClassesController::class, 'detachTraining'])->name('classes.trainings.detach');
    Route::post('api/classes/{class}/enrollments', [ClassesController::class, 'enroll'])->name('classes.enrollments.store');
    Route::delete('api/classes/{class}/enrollments/{enrollment}', [ClassesController::class, 'unenroll'])->name('classes.enrollments.destroy');
    Route::post('api/classes/{class}/complete', [ClassesController::class, 'complete'])->name('classes.complete');
    Route::post('api/classes/{class}/reopen', [ClassesController::class, 'reopen'])->name('classes.reopen');

    Route::inertia('classes', 'classes/Index')->name('classes.page');
    Route::get('classes/{class}', [ClassesController::class, 'showPage'])->name('classes.show-page');

    // Requirements library — named groups of rqmt_elements (9.2 adds the
    // nested element API). Anyone can list; CRUD is Owner/SA/Admin.
    Route::get('api/requirements', [RequirementsController::class, 'index'])->name('requirements.index');
    Route::post('api/requirements', [RequirementsController::class, 'store'])->name('requirements.store');
    Route::patch('api/requirements/{requirement}', [RequirementsController::class, 'update'])->name('requirements.update');
    Route::delete('api/requirements/{requirement}', [RequirementsController::class, 'destroy'])->name('requirements.destroy');

    Route::inertia('requirements', 'requirements/Index')->name('requirements.page');

    // rqmt_elements: nested under a requirement. Element create+list are
    // nested; update+delete take the element id directly (no parent
    // lookup needed). All gated by RqmtElementPolicy (Owner/SA/Admin
    // for writes; viewAny open).
    Route::get('api/requirements/{requirement}/elements', [RqmtElementsController::class, 'index'])->name('requirements.elements.index');
    Route::post('api/requirements/{requirement}/elements', [RqmtElementsController::class, 'store'])->name('requirements.elements.store');
    Route::patch('api/rqmt-elements/{rqmtElement}', [RqmtElementsController::class, 'update'])->name('rqmt-elements.update');
    Route::delete('api/rqmt-elements/{rqmtElement}', [RqmtElementsController::class, 'destroy'])->name('rqmt-elements.destroy');

    // Candidate elements for a given module identity (Phase 10.2 spec
    // hook): used by the manual Completion form to multi-select every
    // element in the org that points at the chosen module.
    Route::get('api/rqmt-elements/candidates', [RqmtElementsController::class, 'candidates'])->name('rqmt-elements.candidates');

    // Assignments — flat API with query filters (?user_id=…, ?requirement_id=…).
    // All gated Owner/SA/Admin in Phase 10; self-view added in 12.3.
    // No UI yet — store is consumed by upcoming Phase 11/12 pages.
    Route::get('api/assignments', [AssignmentsController::class, 'index'])->name('assignments.index');
    Route::post('api/assignments', [AssignmentsController::class, 'store'])->name('assignments.store');
    Route::patch('api/assignments/{assignment}', [AssignmentsController::class, 'update'])->name('assignments.update');
    Route::delete('api/assignments/{assignment}', [AssignmentsController::class, 'destroy'])->name('assignments.destroy');

    // Completions — flat API with optional ?user_id filter. Pivot to
    // rqmt_elements is sync()'d from the rqmt_element_ids array in the
    // request payload. Phase 13.2 widened the policy for Manager+;
    // self-create / self-view still land in 13.3.
    Route::get('api/completions', [CompletionsController::class, 'index'])->name('completions.index');
    Route::post('api/completions', [CompletionsController::class, 'store'])->name('completions.store');
    Route::patch('api/completions/{completion}', [CompletionsController::class, 'update'])->name('completions.update');
    Route::delete('api/completions/{completion}', [CompletionsController::class, 'destroy'])->name('completions.destroy');

    // Phase 13.2 admin pages for manual single-record entry. Lists +
    // create / edit modal; the bulk flow lives at /workflows/bulk-assignment.
    Route::inertia('assignments', 'assignments/Index')->name('assignments.page');
    Route::inertia('completions', 'completions/Index')->name('completions.page');

    Route::get('requirements/{requirement}', function (Requirement $requirement) {
        abort_unless(auth()->user()->org_id === $requirement->org_id, 403);

        return Inertia::render('requirements/Show', [
            'requirement' => [
                'id' => $requirement->id,
                'name' => $requirement->name,
                'description' => $requirement->description,
            ],
        ]);
    })->name('requirements.show');
});

// Permanent realtime smoke canary. Dispatches a RealtimePing event on
// every POST — used by RealtimePingTest as a breakage detector across
// the whole substrate (HTTP → event dispatch → broadcast contract).
// Public on purpose so a dev can manually verify Echo end-to-end via
// the browser console without needing auth setup.
Route::post('/realtime/ping', function (Request $request) {
    Log::info('DIAG realtime.ping route entered', [
        'broadcast_conn' => config('broadcasting.default'),
        'queue_conn' => config('queue.default'),
    ]);
    event(new RealtimePing(message: (string) $request->input('message', 'ping')));
    Log::info('DIAG realtime.ping event() returned');

    return response()->json(['ok' => true]);
})->name('realtime.ping');

require __DIR__.'/settings.php';
