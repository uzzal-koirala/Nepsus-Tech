<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['amount', 'type', 'due_date', 'paid_at', 'method', 'reference', 'note', 'proof_path'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** Where a payment was received. */
    public const METHODS = ['Bank', 'eSewa', 'Khalti', 'Cash', 'Cheque', 'Other'];

    /** Options for x-rich-select: each method with its icon and colour. */
    public static function methodOptions(): array
    {
        $style = [
            'Bank' => ['building-library', 'Bank transfer', 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300'],
            'eSewa' => ['device-phone-mobile', 'Wallet', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300'],
            'Khalti' => ['device-phone-mobile', 'Wallet', 'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300'],
            'Cash' => ['banknotes', 'Notes and coins', 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300'],
            'Cheque' => ['document-text', 'Paper cheque', 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300'],
            'Other' => ['ellipsis-horizontal-circle', 'Anything else', 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300'],
        ];

        return array_map(fn ($m) => ['value' => $m, 'label' => __($m), 'hint' => __($style[$m][1]), 'icon' => $style[$m][0], 'tile' => $style[$m][2]], self::METHODS);
    }

    /** What a payment is for. */
    public const TYPES = [
        'advance' => ['label' => 'Advance payment', 'hint' => 'Paid before the work starts', 'icon' => 'forward'],
        'partial' => ['label' => 'Partial payment', 'hint' => 'Part of the amount due', 'icon' => 'chart-pie'],
        'installment' => ['label' => 'Installment', 'hint' => 'One of several planned parts', 'icon' => 'calendar-days'],
        'monthly' => ['label' => 'Monthly fee', 'hint' => 'Regular package payment', 'icon' => 'arrow-path'],
        'full' => ['label' => 'Full payment', 'hint' => 'The whole amount at once', 'icon' => 'check-badge'],
        'final' => ['label' => 'Final payment', 'hint' => 'The last part, work complete', 'icon' => 'flag'],
        'other' => ['label' => 'Other', 'hint' => 'Anything else', 'icon' => 'ellipsis-horizontal-circle'],
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Payment $payment) => $payment->proof_path && Storage::disk('local')->delete($payment->proof_path));
    }

    public function proofIsImage(): bool
    {
        return (bool) preg_match('/\.(png|jpe?g|webp|gif)$/i', (string) $this->proof_path);
    }

    /** The payment type as shown: built-in keys map to their label, anything else is the text that was typed. */
    public function typeLabel(): string
    {
        return __(self::TYPES[$this->type]['label'] ?? ($this->type ?: 'Payment'));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Show a reminder this many days before a payment is due (set in Settings). */
    public static function remindDays(): int
    {
        return (int) Setting::get('remind_days');
    }

    /**
     * Days until the due date: negative when late, null when paid or undated.
     */
    public function dueInDays(): ?int
    {
        return $this->paid_at || ! $this->due_date ? null : (int) today()->diffInDays($this->due_date, false);
    }

    /**
     * paid, overdue or pending.
     */
    public function status(): string
    {
        return match (true) {
            $this->paid_at !== null => 'paid',
            $this->due_date !== null && $this->due_date->isBefore(today()) => 'overdue',
            default => 'pending',
        };
    }
}
