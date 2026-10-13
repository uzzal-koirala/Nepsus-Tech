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
     * BS months a salary can be recorded for: this one and the next 6, oldest first.
     *
     * @return list<string>
     */
    public static function periodChoices(): array
    {
        return array_reverse(NepaliDate::recentPeriods(7, NepaliDate::addMonths(today(), 6)));
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
