<?php

use App\Http\Controllers\AttendancePushController;
use Illuminate\Support\Facades\Route;

// The office PC helper sends attendance punches here (key checked in the controller).
Route::post('attendance/punches', AttendancePushController::class)
    ->middleware('throttle:60,1')
    ->name('api.attendance.punches');
