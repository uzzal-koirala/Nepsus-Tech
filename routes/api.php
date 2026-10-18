<?php

use App\Http\Controllers\AttendancePushController;
use App\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

// The office PC helper sends attendance punches here (key checked in the controller).
Route::middleware('throttle:60,1')->group(function () {
    Route::post('attendance/punches', [AttendancePushController::class, 'store'])->name('api.attendance.punches');
    Route::get('attendance/pending', [AttendancePushController::class, 'pending'])->name('api.attendance.pending');
});

// ChatGPT, Claude and other assistants connect here (Settings > AI connections). The secret link is checked in the controller.
Route::match(['get', 'post', 'delete'], 'mcp/{token}', McpController::class)->middleware('throttle:240,1')->name('mcp');
