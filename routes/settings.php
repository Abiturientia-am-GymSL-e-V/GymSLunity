<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/signature', [ProfileController::class, 'storeSignature'])->name('profile.signature.store');
    Route::get('settings/profile/signature', [ProfileController::class, 'signature'])->name('profile.signature.show');
    Route::delete('settings/profile/signature', [ProfileController::class, 'destroySignature'])->name('profile.signature.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');
    Route::get('settings/security/setup', [SecurityController::class, 'setup'])
        ->middleware(RequirePassword::class)
        ->name('security.setup');
    Route::delete('settings/security/sessions/others', [SecurityController::class, 'destroyOtherSessions'])
        ->middleware([RequirePassword::class, 'throttle:sensitive', 'audit:session_revoked'])
        ->name('security.sessions.destroy-others');
    Route::delete('settings/security/sessions/{session}', [SecurityController::class, 'destroySession'])
        ->middleware([RequirePassword::class, 'throttle:sensitive', 'audit:session_revoked'])
        ->where('session', '[A-Za-z0-9_-]+')
        ->name('security.sessions.destroy');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
