<?php

namespace App\Models;

use App\Support\NepaliDate;
use Carbon\CarbonInterface;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'company', 'avatar_path', 'email', 'phone', 'status', 'position', 'notes',
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

    protected static function booted(): void
    {
        static::deleted(fn (Client $client) => $client->avatar_path && Storage::disk('public')->delete($client->avatar_path));
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
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
     * Last day of the client's current billing month (or year), counted on the Nepali
     * calendar from the contract start (or the date they were added). A client who
     * started on 15 Bhadra has months ending on the 14th. Null for one-time or inactive.
     */
    public function periodEnd(): ?CarbonInterface
    {
        $anchor = $this->contract_start ?? $this->created_at;

        if ($this->status !== 'active' || ! in_array($this->billing_cycle, ['monthly', 'yearly']) || ! $anchor || ! $start = NepaliDate::fromAd($anchor)) {
            return null;
        }

        [, $anchorMonth, $day] = $start;
        [$year, $month] = NepaliDate::fromAd(today());
        $step = $this->billing_cycle === 'yearly' ? 12 : 1;

        // Begin a step before today's month, then walk forward to the first renewal after today.
        if ($step === 12) {
            [$year, $month] = [$year - 1, $anchorMonth];
        } else {
            [$year, $month] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
        }

        for ($i = 0; $i < 4; $i++) {
            $renewal = NepaliDate::toAd($year, $month, min($day, NepaliDate::monthLength($year, $month)));

            if ($renewal->greaterThan(today()) && $renewal->greaterThan($anchor)) {
                return $renewal->subDay();
            }

            $month += $step;
            [$year, $month] = [$year + intdiv($month - 1, 12), ($month - 1) % 12 + 1];
        }

        return null;
    }

    /**
     * Length of the agreement in BS months, when the end date is a whole number of months after the start.
     */
    public function agreementMonths(): ?int
    {
        if (! $this->contract_start || ! $this->contract_end) {
            return null;
        }

        for ($months = 1; $months <= 120; $months++) {
            $end = NepaliDate::periodEndAfter($this->contract_start, $months);

            if ($end->isSameDay($this->contract_end)) {
                return $months;
            }

            if ($end->greaterThan($this->contract_end)) {
                return null;
            }
        }

        return null;
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
