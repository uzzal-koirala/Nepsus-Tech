<?php

namespace App\Models;

use App\Support\NepaliDate;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'avatar_path', 'device_user_id', 'designation', 'department', 'email', 'phone', 'status', 'salary', 'salary_day', 'work_start', 'work_end', 'late_after', 'joined_on', 'contract_start', 'contract_end',
    'date_of_birth', 'gender', 'blood_group', 'marital_status', 'father_name', 'mother_name', 'spouse_name',
    'permanent_address', 'current_address', 'district', 'province', 'emergency_name', 'emergency_relation', 'emergency_phone',
    'citizenship_no', 'pan_no', 'bank_name', 'bank_account_name', 'bank_account_no',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /** @var array<string, array{label: string, icon: string}>|null */
    private static ?array $departmentCache = null;

    /**
     * Departments keyed by slug: [slug => [label, icon]]. Managed from the employee form.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function departments(): array
    {
        return self::$departmentCache ??= Department::orderBy('position')->orderBy('id')->get()
            ->mapWithKeys(fn ($d) => [$d->slug => ['label' => $d->name, 'icon' => $d->icon]])
            ->all();
    }

    /** The departments a person can be put in for now; each has its own dashboard and pages. Older ones still show on people who have them. */
    public const CHOOSABLE_DEPARTMENTS = ['content-creator', 'video-editor'];

    /** @return array<string, array{label: string, icon: string}> */
    public static function choosableDepartments(): array
    {
        return array_intersect_key(self::departments(), array_flip(self::CHOOSABLE_DEPARTMENTS));
    }

    public static function forgetDepartments(): void
    {
        self::$departmentCache = null;
    }

    /** Label and icon of this employee's department (also for a deleted one). */
    public function departmentMeta(): array
    {
        return self::departments()[$this->department] ?? ['label' => Str::headline((string) $this->department), 'icon' => 'rectangle-group'];
    }

    public const STATUSES = ['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive'];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'contract_start' => 'date',
            'contract_end' => 'date',
            'date_of_birth' => 'date',
            'salary' => 'decimal:2',
        ];
    }

    /** Their login to this system, if they have one. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** "10:00 AM – 6:00 PM", or null when no hours are set. */
    public function workHours(): ?string
    {
        return $this->work_start && $this->work_end ? Attendance::clock($this->work_start).' – '.Attendance::clock($this->work_end) : null;
    }

    /** The first check-in time that counts as late, e.g. "10:16 AM". */
    public function lateFrom(): ?string
    {
        return $this->work_start ? Attendance::clock(\Illuminate\Support\Carbon::createFromFormat('H:i', $this->work_start)->addMinutes((int) $this->late_after + 1)->format('H:i')) : null;
    }

    /** Minutes late for a check-in ("09:20"), or null when on time (start + grace) or no start time is set. */
    public function minutesLate(?string $checkIn): ?int
    {
        if (! $checkIn || ! $this->work_start) {
            return null;
        }
        $late = \App\Support\AttendancePunches::minutesBetween($this->work_start, $checkIn);

        return $late > (int) $this->late_after ? $late : null;
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }

    /**
     * Adds paid_in_period: salary paid for the given BS month (defaults to the current one).
     */
    public function scopeWithSalaryPaid(Builder $query, ?string $period = null): void
    {
        $period ??= NepaliDate::period(today());

        $query->withSum(['salaryPayments as paid_in_period' => fn ($q) => $q->where('period', $period)], 'amount');
    }

    /**
     * Salary progress for the period loaded by withSalaryPaid.
     *
     * @return array{paid: float, salary: float, left: float, percent: int}|null
     */
    /**
     * Salary paid for each BS month.
     *
     * @return array<string, float>
     */
    public function paidByPeriod(): array
    {
        return $this->salaryPayments()->selectRaw('period, sum(amount) as total')->groupBy('period')
            ->pluck('total', 'period')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * BS months whose salary is paid in full.
     *
     * @return list<string>
     */
    public function fullyPaidPeriods(): array
    {
        $salary = (float) $this->salary;

        return $salary > 0 ? array_keys(array_filter($this->paidByPeriod(), fn ($paid) => $paid >= $salary)) : [];
    }

    /** What is still owed for a BS month; null when no salary is set. */
    public function salaryLeftFor(string $period): ?float
    {
        return (float) $this->salary > 0 ? max(0.0, (float) $this->salary - ($this->paidByPeriod()[$period] ?? 0)) : null;
    }

    public function salaryProgress(): ?array
    {
        if (! $this->salary || (float) $this->salary <= 0) {
            return null;
        }

        $salary = (float) $this->salary;
        $paid = (float) ($this->paid_in_period ?? 0);

        return [
            'paid' => $paid,
            'salary' => $salary,
            'left' => max(0.0, $salary - $paid),
            'percent' => (int) min(100, round($paid / $salary * 100)),
        ];
    }

    /** Next salary day on the Nepali calendar (today counts), or null when no salary day is set. */
    public function nextSalaryDate(): ?\Illuminate\Support\Carbon
    {
        if (! $this->salary_day || ! $bs = NepaliDate::fromAd(today())) {
            return null;
        }

        [$year, $month, $day] = $bs;
        if ($day > min($this->salary_day, NepaliDate::monthLength($year, $month))) {
            [$year, $month] = $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
        }
        $next = NepaliDate::toAd($year, $month, min($this->salary_day, NepaliDate::monthLength($year, $month)));
        $first = $this->firstSalaryDate();

        // Never before the first salary: someone who joins on 1 Mangsir is first paid in Poush.
        return $first && $first->gt($next) ? $first : $next;
    }

    /**
     * The first salary: the salary day of the month after they start (joined, else the agreement start).
     * Joined 1 Mangsir with salary day 3 -> 3 Poush. Null without a salary day or a start date.
     */
    public function firstSalaryDate(): ?\Illuminate\Support\Carbon
    {
        $start = $this->joined_on ?? $this->contract_start;
        if (! $this->salary_day || ! $start || ! $bs = NepaliDate::fromAd($start)) {
            return null;
        }
        [$year, $month] = $bs[1] === 12 ? [$bs[0] + 1, 1] : [$bs[0], $bs[1] + 1];

        return NepaliDate::toAd($year, $month, min($this->salary_day, NepaliDate::monthLength($year, $month)));
    }

    protected static function booted(): void
    {
        static::deleted(function (Employee $employee) {
            $employee->avatar_path && Storage::disk('public')->delete($employee->avatar_path);
            // A removed employee can no longer sign in (never your own account).
            if ($employee->user_id && $employee->user_id !== auth()->id()) {
                $employee->user?->delete();
            }
        });
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Time with the company, e.g. "2y 3m", "5m" or "New".
     */
    public function tenure(): ?string
    {
        if (! $this->joined_on || $this->joined_on->isFuture()) {
            return $this->joined_on ? __('Starts soon') : null;
        }

        $months = (int) $this->joined_on->diffInMonths(today());
        [$years, $months] = [intdiv($months, 12), $months % 12];

        return match (true) {
            $years > 0 && $months > 0 => "{$years}y {$months}m",
            $years > 0 => "{$years}y",
            $months > 0 => "{$months}m",
            default => __('New'),
        };
    }

    /**
     * Phone number in wa.me form (Nepal country code added to 10-digit mobiles).
     */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 10 && str_starts_with($digits, '9') ? '977'.$digits : $digits;
    }
}
