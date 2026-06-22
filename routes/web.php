<?php

use App\Http\Controllers\AttachmentsController;
use App\Http\Controllers\BulkTrainingAssignmentsController;
use App\Http\Controllers\ClassDocumentsController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\CommentsController;
use App\Http\Controllers\CompletionsController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\RequirementsController;
use App\Http\Controllers\RqmtElementsController;
use App\Http\Controllers\StdFrequenciesController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TrainingAssignmentsController;
use App\Http\Controllers\TrainingsController;
use App\Http\Controllers\UserPreferencesController;
use App\Http\Controllers\UsersController;
use App\Models\Requirement;
use App\Models\Training;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

// Phase 16.5 — detailed liveness for uptime monitors. Public + minimal;
// complements the framework's boots-only `/up`.
Route::get('health/detailed', [HealthController::class, 'detailed'])->name('health.detailed');

// CSP violation sink (Content-Security-Policy-Report-Only). Public + CSRF-exempt
// (browsers send no token) + throttled to bound log volume.
Route::post('api/csp-report', [CspReportController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('csp.report');

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

    // Compliance roll-ups (Manager+), pivoted by training / requirement. Shell
    // + per-tab paginated JSON; aggregation lives in ComplianceQuery.
    Route::get('compliance', [ComplianceController::class, 'index'])->name('compliance.page');
    Route::get('compliance/training/{training}', [ComplianceController::class, 'trainingDetail'])->name('compliance.training');
    Route::get('api/compliance/by-training', [ComplianceController::class, 'byTraining'])->name('compliance.by-training');
    Route::get('api/compliance/by-requirement', [ComplianceController::class, 'byRequirement'])->name('compliance.by-requirement');
    Route::get('api/compliance/not-required', [ComplianceController::class, 'notRequired'])->name('compliance.not-required');
    Route::get('api/compliance/not-required/{training}/users', [ComplianceController::class, 'notRequiredUsers'])->name('compliance.not-required-users');
    Route::get('api/compliance/by-training/{training}/users', [ComplianceController::class, 'trainingUsers'])->name('compliance.training-users');

    // Exportable PDF reports (T1, Manager+).
    Route::get('reports', [ReportsController::class, 'index'])->name('reports.page');
    Route::get('api/reports/completions', [ReportsController::class, 'completions'])->name('reports.completions');
    Route::get('api/reports/completions/export', [ReportsController::class, 'completionsExport'])->name('reports.completions-export');
    Route::get('api/reports/training/{training}/record', [ReportsController::class, 'trainingRecord'])->name('reports.training-record');
    Route::get('api/reports/user/{user}/record', [ReportsController::class, 'userRecord'])->name('reports.user-record');
    Route::get('api/compliance/by-requirement/{requirement}/users', [ComplianceController::class, 'requirementUsers'])->name('compliance.requirement-users');

    // Save the current user's UI preferences (table column visibility/order +
    // filter defaults). Self-only — shared back via the auth.user prop.
    Route::patch('api/me/preferences', [UserPreferencesController::class, 'update'])
        ->name('me.preferences.update');

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
    // K2 consolidated actionable-rows feed (replaced due-soon + training-due-soon + overdue-users).
    Route::get('api/dashboard/needs-action', [DashboardController::class, 'needsAction'])->name('dashboard.needs-action');
    Route::get('api/dashboard/recent-completions', [DashboardController::class, 'recentCompletions'])->name('dashboard.recent-completions');
    Route::get('api/dashboard/users-compliance', [DashboardController::class, 'usersCompliance'])->name('dashboard.users-compliance');

    Route::get('users', [UsersController::class, 'index'])->name('users.index');
    Route::post('users', [UsersController::class, 'store'])->name('users.store');
    Route::post('users/bulk', [UsersController::class, 'bulkStore'])->name('users.bulk');
    // Combine-users (de-dup) tool: preview the diff, then merge the duplicate
    // into the survivor. `users/merge` is declared before the users/{user}
    // bindings so the literal segment wins over route binding.
    Route::get('api/users/merge-preview', [UsersController::class, 'mergePreview'])->name('users.merge-preview');
    Route::post('users/merge', [UsersController::class, 'merge'])->name('users.merge');

    // Lean JSON user list for downstream picker UX (assignment +
    // completion form modals). Manager+ via inline role gate; UsersController
    // viewAny otherwise stays admin-only.
    Route::get('api/users', [UsersController::class, 'pickerList'])->name('users.picker');
    // Server-paged JSON list backing the users Index table ({data, meta}).
    // Declared before the users/{user} bindings so the literal path wins.
    Route::get('api/users/list', [UsersController::class, 'list'])->name('users.list');
    // Distinct org-scoped values for the user-form type-ahead. Declared before
    // the users/{user} bindings so the literal path wins over route binding.
    Route::get('api/users/field-options', [UsersController::class, 'fieldOptions'])->name('users.field-options');

    // Phase 13.3 user detail + compliance endpoint. The Inertia page
    // is gated to admin/manager (any user) or self (own user); the
    // JSON endpoint applies the same gate before computing.
    Route::get('users/{user}', [UsersController::class, 'show'])->name('users.show');
    // J3 TA-engine compliance payload (same viewDetail gate as the page).
    Route::get('api/users/{user}/training-compliance', [UsersController::class, 'trainingCompliance'])->name('users.training-compliance');
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
    Route::get('api/attachments/types', [AttachmentsController::class, 'types'])->name('attachments.types');
    Route::post('api/attachments', [AttachmentsController::class, 'store'])->name('attachments.store');
    Route::patch('api/attachments/{attachment}', [AttachmentsController::class, 'update'])->name('attachments.update');
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
    Route::get('api/trainings/list', [TrainingsController::class, 'list'])->name('trainings.list');
    Route::post('api/trainings', [TrainingsController::class, 'store'])->name('trainings.store');
    Route::patch('api/trainings/{training}', [TrainingsController::class, 'update'])->name('trainings.update');
    Route::delete('api/trainings/{training}', [TrainingsController::class, 'destroy'])->name('trainings.destroy');

    Route::inertia('trainings', 'trainings/Index')->name('trainings.page');

    // Training System (v16) — classes. Manager+ scheduling tool. JSON API
    // backs the Pinia store; Inertia pages are thin shells.
    Route::get('api/classes', [ClassesController::class, 'index'])->name('classes.index');
    Route::post('api/classes', [ClassesController::class, 'store'])->name('classes.store');
    Route::get('api/classes/{class}/certificates', [ClassDocumentsController::class, 'certificates'])->name('classes.certificates');
    Route::post('api/classes/{class}/certificates', [ClassDocumentsController::class, 'storeCertificates'])->name('classes.certificates.store');
    Route::get('api/classes/{class}/sign-in-sheet', [ClassDocumentsController::class, 'signInSheet'])->name('classes.sign-in-sheet');
    Route::post('api/classes/{class}/sign-in-sheet', [ClassDocumentsController::class, 'storeSignInSheet'])->name('classes.sign-in-sheet.store');
    Route::get('api/classes/{class}/summary', [ClassDocumentsController::class, 'summary'])->name('classes.summary');
    Route::post('api/classes/{class}/summary', [ClassDocumentsController::class, 'storeSummary'])->name('classes.summary.store');
    Route::get('api/classes/{class}', [ClassesController::class, 'show'])->name('classes.show');
    Route::patch('api/classes/{class}', [ClassesController::class, 'update'])->name('classes.update');
    Route::delete('api/classes/{class}', [ClassesController::class, 'destroy'])->name('classes.destroy');
    Route::post('api/classes/{class}/trainings', [ClassesController::class, 'attachTraining'])->name('classes.trainings.attach');
    Route::patch('api/classes/{class}/trainings/{classTraining}', [ClassesController::class, 'updateTraining'])->name('classes.trainings.update');
    Route::delete('api/classes/{class}/trainings/{classTraining}', [ClassesController::class, 'detachTraining'])->name('classes.trainings.detach');
    Route::post('api/classes/{class}/enrollments', [ClassesController::class, 'enroll'])->name('classes.enrollments.store');
    Route::post('api/classes/{class}/enrollments/bulk', [ClassesController::class, 'bulkEnrollment'])->name('classes.enrollments.bulk');
    Route::delete('api/classes/{class}/enrollments/{enrollment}', [ClassesController::class, 'unenroll'])->name('classes.enrollments.destroy');
    Route::post('api/classes/{class}/complete', [ClassesController::class, 'complete'])->name('classes.complete');
    Route::post('api/classes/{class}/reopen', [ClassesController::class, 'reopen'])->name('classes.reopen');

    Route::inertia('classes', 'classes/Index')->name('classes.page');
    Route::get('classes/{class}', [ClassesController::class, 'showPage'])->name('classes.show-page');

    // Requirements library — named groups of rqmt_elements (9.2 adds the
    // nested element API). Anyone can list; CRUD is Owner/SA/Admin.
    Route::get('api/requirements', [RequirementsController::class, 'index'])->name('requirements.index');
    // Static segment before the {requirement} param routes so it isn't treated as an id.
    Route::get('api/requirements/paged', [RequirementsController::class, 'paged'])->name('requirements.paged');
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

    // Training assignments — training-as-atom model.
    // store handles both direct (training_id) and requirement-exploded (requirement_id) sources.
    Route::get('api/training-assignments', [TrainingAssignmentsController::class, 'index'])->name('training-assignments.index');
    Route::get('api/training-assignments/by-user', [TrainingAssignmentsController::class, 'byUser'])->name('training-assignments.by-user');
    Route::post('api/training-assignments', [TrainingAssignmentsController::class, 'store'])->name('training-assignments.store');
    // Static segment must precede the parameterised route so 'by-requirement' is not treated as an ID.
    Route::delete('api/training-assignments/by-requirement', [TrainingAssignmentsController::class, 'destroyByRequirement'])->name('training-assignments.destroy-by-requirement');
    Route::delete('api/training-assignments/{trainingAssignment}/from-requirement', [TrainingAssignmentsController::class, 'breakFromRequirement'])->name('training-assignments.break-from-requirement');
    Route::delete('api/training-assignments/{trainingAssignment}', [TrainingAssignmentsController::class, 'destroy'])->name('training-assignments.destroy');
    Route::post('api/bulk-training-assignments', [BulkTrainingAssignmentsController::class, 'store'])->name('bulk-training-assignments.store');

    // Completions — flat API with optional ?user_id filter. Pivot to
    // rqmt_elements is sync()'d from the rqmt_element_ids array in the
    // request payload. Phase 13.2 widened the policy for Manager+;
    // self-create / self-view still land in 13.3.
    Route::get('api/completions', [CompletionsController::class, 'index'])->name('completions.index');
    Route::get('api/completions/{completion}/certificate', [ClassDocumentsController::class, 'completionCertificate'])->name('completions.certificate');
    Route::post('api/completions', [CompletionsController::class, 'store'])->name('completions.store');
    Route::patch('api/completions/{completion}', [CompletionsController::class, 'update'])->name('completions.update');
    Route::delete('api/completions/{completion}', [CompletionsController::class, 'destroy'])->name('completions.destroy');

    // Phase 13.2 admin pages for manual single-record entry. Lists +
    // create / edit modal; bulk assign lives in the assignments page modal.
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

    Route::get('trainings/{training}', function (Training $training) {
        abort_unless(auth()->user()->org_id === $training->org_id, 403);

        $training->loadMissing('stdFrequency:id,name');

        return Inertia::render('trainings/Show', [
            'training' => [
                'id' => $training->id,
                'name' => $training->name,
                'nickname' => $training->nickname,
                'description' => $training->description,
                'default_hours' => $training->default_hours,
                'initial_only' => $training->initial_only,
                'repeating' => $training->repeating,
                'std_freq_id' => $training->std_freq_id,
                'std_freq_name' => $training->stdFrequency?->name,
                'as_needed' => $training->as_needed,
                'cert_title' => $training->cert_title,
                'cert_text' => $training->cert_text,
                'lifespan_months' => $training->lifespan_months,
                'cert_code' => $training->cert_code,
                'default_trainer' => $training->default_trainer,
                'default_location' => $training->default_location,
                'default_address' => $training->default_address,
            ],
        ]);
    })->name('trainings.show');
});

require __DIR__.'/settings.php';
