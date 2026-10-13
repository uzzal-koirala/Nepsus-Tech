<?php

use App\Http\Controllers\AttendancePushController;
use Illuminate\Support\Facades\Route;

// The office PC helper sends attendance punches here (key checked in the controller).
Route::middleware('throttle:60,1')->group(function () {
    Route::post('attendance/punches', [AttendancePushController::class, 'store'])->name('api.attendance.punches');
    Route::get('attendance/pending', [AttendancePushController::class, 'pending'])->name('api.attendance.pending');
});

// Public key the CCTV gateway (MediaMTX) uses to check viewing tokens. Public by design: it can only verify.
Route::get('cctv/jwks', fn () => response()->json(\App\Support\CctvToken::jwks())->header('Cache-Control', 'public, max-age=300'))
    ->middleware('throttle:30,1')
    ->name('api.cctv.jwks');
