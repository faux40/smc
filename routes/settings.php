<?php

use App\Http\Controllers\Settings\OrganizationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/organization', [OrganizationController::class, 'edit'])->name('organization.edit');
    Route::patch('settings/organization', [OrganizationController::class, 'update'])->name('organization.update');
    Route::delete('settings/organization', [OrganizationController::class, 'destroy'])->name('organization.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    // Std frequencies admin — Inertia page renders the shell; data + mutations
    // flow through /api/std-frequencies via useStdFrequenciesStore. The
    // JSON controller gates writes via StdFrequencyPolicy (Owner/SA/Admin).
    Route::inertia('settings/frequencies', 'settings/Frequencies')->name('frequencies.edit');
});
