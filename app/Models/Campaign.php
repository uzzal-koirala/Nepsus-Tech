<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

#[Fillable(['name', 'channel', 'budget', 'spent', 'starts_on', 'ends_on', 'notes'])]
class Campaign extends Model
{
    public const CHANNELS = [
        'facebook_ads' => ['label' => 'Facebook ads', 'icon' => 'hand-thumb-up', 'color' => 'from-blue-500 to-blue-600'],
        'instagram_ads' => ['label' => 'Instagram ads', 'icon' => 'camera', 'color' => 'from-pink-500 to-fuchsia-600'],
        'tiktok_ads' => ['label' => 'TikTok ads', 'icon' => 'musical-note', 'color' => 'from-zinc-700 to-zinc-900'],
        'google_ads' => ['label' => 'Google ads', 'icon' => 'magnifying-glass', 'color' => 'from-amber-400 to-orange-500'],
        'seo' => ['label' => 'SEO / content', 'icon' => 'document-magnifying-glass', 'color' => 'from-emerald-500 to-teal-600'],
        'email' => ['label' => 'Email / SMS', 'icon' => 'envelope', 'color' => 'from-sky-500 to-cyan-600'],
        'event' => ['label' => 'Event / expo', 'icon' => 'calendar-days', 'color' => 'from-violet-500 to-purple-600'],
        'print' => ['label' => 'Flyers / print', 'icon' => 'newspaper', 'color' => 'from-rose-500 to-red-600'],
        'other' => ['label' => 'Other', 'icon' => 'megaphone', 'color' => 'from-brand-500 to-brand-600'],
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'spent' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', Rule::in(array_keys(self::CHANNELS))],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'spent' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function channelMeta(): array
    {
        return self::CHANNELS[$this->channel] ?? self::CHANNELS['other'];
    }

    /** running, scheduled or ended, from the dates. */
    public function status(): string
    {
        return match (true) {
            $this->starts_on->isFuture() => 'scheduled',
            $this->ends_on !== null && $this->ends_on->lt(today()) => 'ended',
            default => 'running',
        };
    }
}
