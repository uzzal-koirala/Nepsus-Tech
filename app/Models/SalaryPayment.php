<?php

namespace App\Models;

use App\Support\NepaliDate;
use Database\Factories\SalaryPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['period', 'amount', 'paid_on', 'note'])]
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
     * BS months a salary can be recorded for: 6 back, this one and 6 ahead, oldest first.
     *
     * @return list<string>
     */
    public static function periodChoices(): array
    {
        return array_reverse(NepaliDate::recentPeriods(13, NepaliDate::addMonths(today(), 6)));
    }

    /** First month from $from on (or this month) that has no payment yet. */
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
