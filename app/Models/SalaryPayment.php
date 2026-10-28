<?php

namespace App\Models;

use App\Support\NepaliDate;
use Closure;
use Database\Factories\SalaryPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

#[Fillable(['period', 'title', 'amount', 'paid_on', 'note'])]
class SalaryPayment extends Model
{
    /** @use HasFactory<SalaryPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    /**
     * Salaries count as an expense in the BS month they are for, not the day they were paid:
     * Kartik's salary paid on 27 Ashoj is a Kartik expense. Keeps the salaries for the months in [$from, $to].
     */
    public function scopeForMonthsIn($query, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to)
    {
        $periods = [];
        for ($p = NepaliDate::period($from), $last = NepaliDate::period($to); $p <= $last;) {
            $periods[] = $p;
            [$y, $m] = array_map('intval', explode('-', $p));
            $p = $m === 12 ? sprintf('%d-01', $y + 1) : sprintf('%d-%02d', $y, $m + 1);
        }

        return $query->whereIn('period', $periods);
    }

    /** "Kartik 2083" for the salary's month. */
    public function periodLabel(): string
    {
        [$y, $m] = array_map('intval', explode('-', (string) $this->period));

        return (NepaliDate::MONTHS[$m - 1] ?? '').' '.$y;
    }

    /**
     * BS months a salary can be recorded for: this one and the next 2, oldest first.
     *
     * @return list<string>
     */
    public static function periodChoices(): array
    {
        return array_reverse(NepaliDate::recentPeriods(3, NepaliDate::addMonths(today(), 2)));
    }

    /** First month from $from on (or this month) that is not fully paid. */
    public static function firstOpenPeriod(array $paid, ?string $from = null): string
    {
        $from ??= NepaliDate::period(today());

        foreach (self::periodChoices() as $p) {
            if ($p >= $from && ! in_array($p, $paid, true)) {
                return $p;
            }
        }

        return '';
    }

    /**
     * Validation for the pay salary form: a month can take more payments until it is fully paid,
     * and a payment can never be more than what is left for that month.
     */
    public static function payRules(Employee $employee, string $period): array
    {
        $left = in_array($period, self::periodChoices(), true) ? $employee->salaryLeftFor($period) : null;

        return [
            'payPeriod' => ['required', Rule::in(self::periodChoices()), function (string $attribute, mixed $value, Closure $fail) use ($left) {
                if ($left !== null && $left <= 0) {
                    $fail(__('Salary for this month is already fully paid.'));
                }
            }],
            'payAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999', function (string $attribute, mixed $value, Closure $fail) use ($left) {
                if ($left !== null && $left > 0 && (float) $value > $left + 0.001) {
                    $fail(__('Only :amount is left for this month.', ['amount' => Client::money($left)]));
                }
            }],
        ];
    }

    /** The title given when paying, or "Salary for Ashoj 2083". */
    public function label(): string
    {
        return $this->title ?: __('Salary for :month', ['month' => NepaliDate::periodLabel($this->period)]);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
