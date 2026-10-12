<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['date', 'status', 'note'])]
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
