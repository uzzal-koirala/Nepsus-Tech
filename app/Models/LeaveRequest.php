<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Leave an employee asks for. Approving it marks each working day in the range as leave. */
#[Fillable(['employee_id', 'from_date', 'to_date', 'reason', 'status', 'reply', 'decided_by', 'decided_at'])]
class LeaveRequest extends Model
{
    public const STATUSES = [
        'pending' => ['label' => 'Waiting', 'icon' => 'clock', 'tone' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20'],
        'approved' => ['label' => 'Approved', 'icon' => 'check-circle', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20'],
        'rejected' => ['label' => 'Not approved', 'icon' => 'x-circle', 'tone' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20'],
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return list<Carbon> The working days asked for (the weekly holiday is skipped). */
    public function workingDays(): array
    {
        $days = [];
        for ($d = $this->from_date->copy(); $d->lte($this->to_date); $d = $d->addDay()) {
            if (! $d->isHoliday()) {
                $days[] = $d->copy();
            }
        }

        return $days;
    }

    public static function pendingCount(): int
    {
        return static::where('status', 'pending')->count();
    }

    /** Approves it and marks each working day as leave, with the reason as the note. */
    public function approve(User $by, ?string $reply = null): void
    {
        DB::transaction(function () use ($by, $reply) {
            foreach ($this->workingDays() as $day) {
                Attendance::updateOrCreate(
                    ['employee_id' => $this->employee_id, 'date' => $day->toDateString()],
                    ['status' => 'leave', 'note' => $this->reason, 'source' => 'manual'],
                );
            }
            $this->update(['status' => 'approved', 'reply' => $reply ?: null, 'decided_by' => $by->id, 'decided_at' => now()]);
        });
    }

    public function reject(User $by, ?string $reply = null): void
    {
        $this->update(['status' => 'rejected', 'reply' => $reply ?: null, 'decided_by' => $by->id, 'decided_at' => now()]);
    }
}
