<?php

use App\Events\RealtimePing;
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
