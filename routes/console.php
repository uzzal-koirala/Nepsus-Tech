<?php

use App\Mail\DailyReminders;
use App\Models\Setting;
use App\Support\AttendancePunches;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
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

Artisan::command('reminders:send', function () {
    if (! $to = DailyReminders::recipients()) {
        return $this->warn('No reminder email set (Settings → Preferences).');
    }
    if (! $sections = DailyReminders::gather()) {
        return $this->info('Nothing due today or tomorrow, no email sent.');
    }
    Mail::to($to)->send(new DailyReminders($sections));
    $this->info('Reminders sent to '.implode(', ', $to));
})->purpose('Email the morning reminders: shoots, tasks, payments and salaries due');

// Needs the hPanel cron job running `php artisan schedule:run` every minute.
Schedule::command('reminders:send')->dailyAt('07:00')->when(fn () => Setting::get('reminder_emails_on') === '1');

// Runs while `php artisan schedule:work` is running on the office PC.
Schedule::command('attendance:sync')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => Setting::get('device_auto_sync') === '1');
