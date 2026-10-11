<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'company', 'email', 'phone', 'status', 'notes',
    'package', 'price', 'billing_cycle', 'progress',
    'contract_start', 'contract_end', 'agreement_signed_at',
    'facebook_url', 'instagram_url', 'tiktok_url', 'google_business_url',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public const STATUSES = ['active', 'lead', 'inactive'];

    public const BILLING_CYCLES = ['monthly', 'yearly', 'one_time'];

    /** Package tiers, lowest to highest, with their icon. */
    public const PACKAGES = [
        'basic' => ['label' => 'Basic', 'icon' => 'sprout'],
        'standard' => ['label' => 'Standard', 'icon' => 'star'],
        'premium' => ['label' => 'Premium', 'icon' => 'crown'],
        'enterprise' => ['label' => 'Enterprise', 'icon' => 'gem'],
    ];

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
