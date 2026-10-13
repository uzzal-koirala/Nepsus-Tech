<?php

use App\Http\Controllers\AttendancePushController;
use Illuminate\Support\Facades\Route;

// The office PC helper sends attendance punches here (key checked in the controller).
Route::middleware('throttle:60,1')->group(function () {
    Route::post('attendance/punches', [AttendancePushController::class, 'store'])->name('api.attendance.punches');
    Route::get('attendance/pending', [AttendancePushController::class, 'pending'])->name('api.attendance.pending');
});
