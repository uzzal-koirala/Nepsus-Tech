<?php

namespace App\Models;

use App\Support\NepaliDate;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'designation', 'department', 'email', 'phone', 'status', 'salary', 'salary_day', 'joined_on', 'contract_start', 'contract_end',
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

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
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

        return NepaliDate::toAd($year, $month, min($this->salary_day, NepaliDate::monthLength($year, $month)));
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
