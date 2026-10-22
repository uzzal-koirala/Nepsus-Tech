<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A script for a reel, video, ad or podcast. */
#[Fillable(['title', 'client_id', 'type', 'status', 'shoot_date', 'body', 'notes', 'user_id'])]
class Script extends Model
{
    public const TYPES = [
        'reel' => ['label' => 'Reel', 'icon' => 'film'],
        'tiktok' => ['label' => 'TikTok', 'icon' => 'musical-note'],
        'youtube' => ['label' => 'YouTube', 'icon' => 'play-circle'],
        'ad' => ['label' => 'Ad', 'icon' => 'megaphone'],
        'podcast' => ['label' => 'Podcast', 'icon' => 'microphone'],
        'other' => ['label' => 'Other', 'icon' => 'document-text'],
    ];

    /** In working order. */
    public const STATUSES = [
        'idea' => ['label' => 'Idea', 'tone' => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10', 'dot' => 'bg-zinc-400'],
        'draft' => ['label' => 'Draft', 'tone' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20', 'dot' => 'bg-sky-500'],
        'review' => ['label' => 'In review', 'tone' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', 'dot' => 'bg-amber-500'],
        'approved' => ['label' => 'Approved', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20', 'dot' => 'bg-emerald-500'],
        'shot' => ['label' => 'Shot', 'tone' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20', 'dot' => 'bg-violet-500'],
    ];

    /** Speaking pace for the time estimate. */
    public const WORDS_PER_MINUTE = 150;

    protected function casts(): array
    {
        return ['shoot_date' => 'date'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Today and upcoming shoots first (soonest first), then undated by latest edit, then past shoots. */
    public function sortKey(): array
    {
        if ($this->shoot_date && $this->shoot_date->gte(today())) {
            return [0, $this->shoot_date->timestamp];
        }

        return $this->shoot_date ? [2, -$this->shoot_date->timestamp] : [1, -$this->updated_at->timestamp];
    }

    /** "Shoot today", "Shoot tomorrow", "Shoot in 3 days", "Shot 12 Ashoj" or null. */
    public function shootLabel(): ?string
    {
        if (! $this->shoot_date) {
            return null;
        }
        $days = (int) today()->diffInDays($this->shoot_date, false);

        return match (true) {
            $days === 0 => __('Shoot today'),
            $days === 1 => __('Shoot tomorrow'),
            $days > 1 && $days <= 7 => __('Shoot in :count days', ['count' => $days]),
            $days > 7 => __('Shoot :date', ['date' => $this->shoot_date->toNepali(false)]),
            default => __('Shoot was :date', ['date' => $this->shoot_date->toNepali(false)]),
        };
    }

    public static function wordCount(?string $text): int
    {
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', (string) $text);
    }

    /** About how long it takes to say, e.g. "45 sec" or "2 min 10 sec". */
    public static function speakingTime(?string $text): string
    {
        $seconds = (int) round(static::wordCount($text) / static::WORDS_PER_MINUTE * 60);

        return $seconds < 60 ? $seconds.' sec' : intdiv($seconds, 60).' min'.($seconds % 60 ? ' '.($seconds % 60).' sec' : '');
    }
}
