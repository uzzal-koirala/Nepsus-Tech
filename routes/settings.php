<?php

use App\Models\Setting;
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
    Route::livewire('settings/cameras', 'pages::settings.cameras')->name('settings.cameras');

    // Zip with the office PC helper, already set up for this website and its secret key.
    Route::get('settings/attendance-device/helper', function () {
        $key = (string) Setting::get('device_push_token');
        abort_if($key === '', 404);

        $path = tempnam(sys_get_temp_dir(), 'helper');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach (['Install.bat', 'Uninstall.bat', 'setup.ps1', 'sync.ps1'] as $file) {
            // Windows wants CRLF line endings in .bat files.
            $zip->addFromString($file, str_replace(["\r\n", "\n"], ["\n", "\r\n"], file_get_contents(resource_path('attendance-helper/'.$file))));
        }
        $zip->addFromString('config.json', json_encode([
            'site' => rtrim(url('/'), '/'),
            'token' => $key,
            'deviceIp' => (string) Setting::get('device_host', '192.168.1.74'),
            'deviceUser' => (string) Setting::get('device_username', 'admin'),
            'devicePassword' => '',
            'days' => 2,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return response()->download($path, 'nepsus-attendance-helper.zip')->deleteFileAfterSend();
    })->name('settings.attendance-device.helper');
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
