<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/business');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/business', 'pages::settings.business')->name('settings.business');
    Route::livewire('settings/preferences', 'pages::settings.preferences')->name('settings.preferences');
    Route::livewire('settings/work-types', 'pages::settings.work-types')->name('settings.work-types');
    Route::livewire('settings/attendance-device', 'pages::settings.attendance-device')->name('settings.attendance-device');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
