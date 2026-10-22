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
    'name', 'company', 'avatar_path', 'email', 'phone', 'status', 'service_type', 'position', 'notes',
    'package_id', 'price', 'billing_months', 'video_count', 'payment_plan', 'installments', 'progress',
    'contract_start', 'contract_end', 'agreement_signed_at',
    'facebook_url', 'instagram_url', 'tiktok_url', 'google_business_url',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public const STATUSES = ['active', 'inactive'];

    /**
     * Kinds of work a client buys. Social media is billed every N months;
     * websites and software are one-time projects; videos are charged per video.
     */
    public const SERVICE_TYPES = [
        'social_media' => ['label' => 'Social media', 'icon' => 'megaphone', 'hint' => 'Monthly management'],
        'website' => ['label' => 'Website', 'icon' => 'globe-alt', 'hint' => 'One-time project'],
        'software' => ['label' => 'Software', 'icon' => 'code-bracket', 'hint' => 'One-time project'],
        'video' => ['label' => 'Video', 'icon' => 'video-camera', 'hint' => 'Charged per video'],
    ];

    /** Billing choices for packages; clients store a number of months instead. */
    public const BILLING_CYCLES = ['monthly', 'yearly', 'one_time'];

    /** Package billing cycle => client billing months (null = one-time). */
    public static function monthsForCycle(?string $cycle): ?int
    {
        return ['monthly' => 1, 'yearly' => 12][$cycle] ?? null;
    }

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
            'billing_months' => 'integer',
            'video_count' => 'integer',
            'installments' => 'integer',
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

    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class);
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
     * Reminder for the next unpaid payment when it is late or due within Payment::remindDays().
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
            $days <= Payment::remindDays() => 'soon',
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

        if ($this->status !== 'active' || ! $this->billing_months || ! $anchor || ! $start = NepaliDate::fromAd($anchor)) {
            return null;
        }

        // Renewals fall on the start date plus whole billing periods; the current period ends the day before the next one.
        [$startYear, $startMonth] = $start;
        [$year, $month] = NepaliDate::fromAd(today());
        $elapsed = ($year * 12 + $month) - ($startYear * 12 + $startMonth);
        $step = $this->billing_months;

        for ($k = max(1, intdiv(max(0, $elapsed), $step)); $k <= intdiv(max(0, $elapsed), $step) + 3; $k++) {
            $renewal = NepaliDate::addMonths($anchor, $k * $step);

            if ($renewal->greaterThan(today())) {
                return $renewal->subDay();
            }
        }

        return null;
    }

    public function isRecurring(): bool
    {
        return $this->service_type === 'social_media' && $this->billing_months !== null;
    }

    /**
     * What the client pays in total for one-off work: price x videos for video clients, otherwise the price.
     */
    public function totalValue(): ?float
    {
        if ($this->price === null) {
            return null;
        }

        return $this->service_type === 'video' ? (float) $this->price * max(1, (int) $this->video_count) : (float) $this->price;
    }

    /**
     * Short suffix after a price: "/mo", "/yr", "/3 mo", "/video" or " one-time".
     */
    public function billingSuffix(): string
    {
        if ($this->service_type === 'video') {
            return __('/video');
        }

        return match ($this->billing_months) {
            null => ' · '.__('one-time'),
            1 => __('/mo'),
            12 => __('/yr'),
            default => '/'.$this->billing_months.' '.__('mo'),
        };
    }

    /**
     * "Billed every month", "Billed every 3 months", "One-time payment".
     */
    public function billingText(): string
    {
        if ($this->service_type === 'video') {
            return trans_choice(':count video, paid per video|:count videos, paid per video', max(1, (int) $this->video_count));
        }

        if (in_array($this->service_type, ['website', 'software'])) {
            return __('One-time project');
        }

        return match ($this->billing_months) {
            null => __('One-time payment'),
            1 => __('Billed every month'),
            12 => __('Billed every year'),
            default => __('Billed every :count months', ['count' => $this->billing_months]),
        };
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
     * Main name on cards and lists: the business when there is one, otherwise the person.
     */
    public function displayName(): string
    {
        return $this->company ?: $this->name;
    }

    /**
     * Second line under the business name: the contact person.
     */
    public function contactName(): string
    {
        return $this->company ? $this->name : __('Individual client');
    }

    /**
     * Get the client's initials (of the business name when there is one).
     */
    public function initials(): string
    {
        $initials = Str::initials($this->displayName(), true);

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
