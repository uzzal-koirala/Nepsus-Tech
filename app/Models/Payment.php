<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['amount', 'due_date', 'paid_at', 'method', 'reference', 'note'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const METHODS = ['Bank transfer', 'Cash', 'eSewa', 'Khalti', 'Cheque', 'Card'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Show a reminder this many days before a payment is due. */
    public const REMIND_DAYS = 7;

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
