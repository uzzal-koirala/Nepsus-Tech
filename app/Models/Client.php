<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'company', 'email', 'phone', 'status', 'notes',
    'package', 'price', 'billing_cycle', 'progress',
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
        ];
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
        if ($this->price === null) {
            return null;
        }

        $decimals = fmod((float) $this->price, 1.0) == 0.0 ? 0 : 2;

        return config('app.currency').' '.number_format((float) $this->price, $decimals);
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
