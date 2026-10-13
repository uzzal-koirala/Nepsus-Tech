<?php

use App\Models\Setting;
use App\Support\AttendancePunches;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('attendance:sync {--days=2 : How many days back to read}', function () {
    $result = AttendancePunches::syncFromDevice(max(1, (int) $this->option('days')));
    $this->info("{$result['punches']} punches read, {$result['marked']} days marked present, {$result['kept']} hand-marked days kept.");
    if ($result['unknown']) {
        $this->warn('Device IDs not linked to an employee: '.implode(', ', $result['unknown']));
    }
})->purpose('Read punches from the Hikvision attendance device and mark attendance');

// Runs while `php artisan schedule:work` is running on the office PC.
Schedule::command('attendance:sync')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => Setting::get('device_auto_sync') === '1');
