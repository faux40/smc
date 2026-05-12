<?php

use App\Events\RealtimePing;
use App\Http\Controllers\AttachmentsController;
use App\Http\Controllers\CommentsController;
use App\Http\Controllers\StdFrequenciesController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TrainingsController;
use App\Http\Controllers\UsersController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('users', [UsersController::class, 'index'])->name('users.index');
    Route::post('users', [UsersController::class, 'store'])->name('users.store');
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
});

// Permanent realtime smoke canary. Dispatches a RealtimePing event on
// every POST — used by RealtimePingTest as a breakage detector across
// the whole substrate (HTTP → event dispatch → broadcast contract).
// Public on purpose so a dev can manually verify Echo end-to-end via
// the browser console without needing auth setup.
Route::post('/realtime/ping', function (Request $request) {
    event(new RealtimePing(message: (string) $request->input('message', 'ping')));

    return response()->json(['ok' => true]);
})->name('realtime.ping');

require __DIR__.'/settings.php';
