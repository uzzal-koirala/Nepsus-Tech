<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'company', 'email', 'phone', 'status', 'position', 'notes',
    'package_id', 'price', 'billing_cycle', 'progress',
    'contract_start', 'contract_end', 'agreement_signed_at',
    'facebook_url', 'instagram_url', 'tiktok_url', 'google_business_url',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public const STATUSES = ['active', 'inactive'];

    public const BILLING_CYCLES = ['monthly', 'yearly', 'one_time'];

    /** Social profile columns, keyed by icon name. */
    public const SOCIALS = [
        'facebook' => 'facebook_url',
        'instagram' => 'instagram_url',
        'tiktok' => 'tiktok_url',
        'google' => 'google_business_url',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'progress' => 'integer',
            'contract_start' => 'date',
            'contract_end' => 'date',
            'agreement_signed_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Adds next_due_date and next_due_amount: the earliest unpaid payment.
     */
    public function scopeWithNextPayment(Builder $query): void
    {
        $next = fn (string $column) => Payment::select($column)
            ->whereColumn('client_id', 'clients.id')
            ->whereNull('paid_at')
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->limit(1);

        $query->addSelect(['next_due_date' => $next('due_date'), 'next_due_amount' => $next('amount')]);
    }

    /**
     * Reminder for the next unpaid payment when it is late or due within Payment::REMIND_DAYS.
     * Needs the withNextPayment scope.
     *
     * @return array{tone: 'overdue'|'today'|'soon', days: int, date: CarbonInterface, amount: string}|null
     */
    public function paymentAlert(): ?array
    {
        if (! $this->next_due_date) {
            return null;
        }

        $date = Date::parse($this->next_due_date);
        $days = (int) today()->diffInDays($date, false);

        $tone = match (true) {
            $days < 0 => 'overdue',
            $days === 0 => 'today',
            $days <= Payment::REMIND_DAYS => 'soon',
            default => null,
        };

        return $tone ? ['tone' => $tone, 'days' => abs($days), 'date' => $date, 'amount' => self::money($this->next_due_amount)] : null;
    }

    /**
     * Get the client's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Price formatted in the app currency, e.g. "NPR 25,000".
     */
    public function formattedPrice(): ?string
    {
        return $this->price === null ? null : self::money($this->price);
    }

    /**
     * Format an amount in the app currency, e.g. "NPR 25,000".
     */
    public static function money(float|string $amount): string
    {
        $decimals = fmod((float) $amount, 1.0) == 0.0 ? 0 : 2;

        return config('app.currency').' '.number_format((float) $amount, $decimals);
    }

    /**
     * Linked social profiles as [icon => url], skipping empty ones.
     *
     * @return array<string, string>
     */
    public function socialLinks(): array
    {
        return collect(self::SOCIALS)
            ->map(fn ($column) => $this->{$column})
            ->filter()
            ->all();
    }
}
