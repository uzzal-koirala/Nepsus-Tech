<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Fillable(['employee_id', 'date', 'status', 'check_in', 'check_out', 'note', 'source'])]
class Attendance extends Model
{
    public const STATUSES = [
        'present' => ['label' => 'Present', 'short' => 'P', 'icon' => 'check-circle'],
        'half_day' => ['label' => 'Half day', 'short' => 'H', 'icon' => 'clock'],
        'leave' => ['label' => 'Leave', 'short' => 'L', 'icon' => 'sun'],
        'absent' => ['label' => 'Absent', 'short' => 'A', 'icon' => 'x-circle'],
    ];

    /** Stored as a plain Y-m-d date so "one mark per day" lookups match on every database. */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->startOfDay(),
            set: fn ($value) => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * Deletes a day's attendance for good: the day is remembered so a later
     * device sync does not bring it back.
     */
    public static function forgetDay(int $employeeId, string $date): void
    {
        $date = Carbon::parse($date)->toDateString();
        static::where('employee_id', $employeeId)->whereDate('date', $date)->delete();
        DB::table('attendance_exclusions')->insertOrIgnore(['employee_id' => $employeeId, 'date' => $date, 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function isForgotten(int $employeeId, string $date): bool
    {
        return DB::table('attendance_exclusions')->where('employee_id', $employeeId)->whereDate('date', $date)->exists();
    }

    /** "09:05" => "9:05 AM", "18:02" => "6:02 PM". */
    public static function clock(?string $time): ?string
    {
        return $time ? Carbon::createFromFormat('H:i', $time)->format('g:i A') : null;
    }

    /** Time between check-in and check-out, e.g. "8h 57m"; null without both. */
    public function worked(): ?string
    {
        if (! $this->check_in || ! $this->check_out) {
            return null;
        }
        $minutes = \App\Support\AttendancePunches::minutesBetween($this->check_in, $this->check_out);

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
