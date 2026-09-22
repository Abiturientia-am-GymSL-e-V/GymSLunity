<?php

use App\Http\Controllers\Configuration\ClubController;
use App\Http\Controllers\Configuration\ClubLogoController;
use App\Http\Controllers\Configuration\MemberFieldController;
use App\Http\Controllers\Configuration\UserController;
use App\Http\Controllers\Members\MemberCardController;
use App\Http\Controllers\Members\MemberController;
use App\Http\Controllers\Members\MemberExportController;
use App\Http\Controllers\Members\MemberIndexController;
use App\Http\Controllers\Members\PostalCodeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::get('branding/logo', [ClubLogoController::class, 'show'])->name('branding.logo');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('ueber-gymslunity', 'About')->name('about');
    Route::inertia('hallo-welt', 'HelloWorld')->name('hello-world');
    Route::inertia('beitraege', 'Payments')->middleware('can:view-payments')->name('payments');
    Route::get('ortsangaben', PostalCodeController::class)->middleware('throttle:60,1')->name('postal.lookup');
    Route::get('mitglieder', MemberIndexController::class)->name('members.index');
    Route::post('mitglieder/export', MemberExportController::class)->middleware('throttle:20,1')->name('members.export');
    Route::get('mitglieder/{member:member_number}/karteiblatt', MemberCardController::class)->name('members.card');
    Route::get('mitglieder/{member:member_number}', [MemberController::class, 'show'])->name('members.show');
    Route::patch('mitglieder/{member:member_number}', [MemberController::class, 'update'])->name('members.update');
    Route::get('mitglieder/{member:member_number}/dokumente/{kind}', [MemberController::class, 'document'])->name('members.document');
    Route::middleware('can:manage-configuration')->prefix('konfiguration')->name('configuration.')->group(function () {
        Route::redirect('/', '/konfiguration/verein');
        Route::get('verein', [ClubController::class, 'edit'])->name('club.edit');
        Route::patch('verein', [ClubController::class, 'update'])->name('club.update');
        Route::post('verein/logo', [ClubLogoController::class, 'store'])->name('club.logo.store');
        Route::delete('verein/logo', [ClubLogoController::class, 'destroy'])->name('club.logo.destroy');
        Route::get('mitgliedsfelder', [MemberFieldController::class, 'index'])->name('fields.index');
        Route::post('mitgliedsfelder', [MemberFieldController::class, 'store'])->name('fields.store');
        Route::patch('mitgliedsfelder/reihenfolge', [MemberFieldController::class, 'reorder'])->name('fields.reorder');
        Route::patch('mitgliedsfelder/{field}', [MemberFieldController::class, 'update'])->name('fields.update');
        Route::get('benutzer', [UserController::class, 'index'])->name('users.index');
        Route::post('benutzer', [UserController::class, 'store'])->name('users.store');
        Route::patch('benutzer/{user}', [UserController::class, 'update'])->name('users.update');
    });
});

require __DIR__.'/settings.php';
