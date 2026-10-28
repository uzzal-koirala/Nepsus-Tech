<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link to raw footage (usually a Google Drive file or folder) for the editors. */
#[Fillable(['title', 'url', 'client_id', 'status', 'shot_on', 'edit_by', 'notes', 'user_id'])]
class Video extends Model
{
    /** In working order. */
    public const STATUSES = [
        'raw' => ['label' => 'Raw footage', 'short' => 'Raw', 'icon' => 'film', 'tone' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20', 'dot' => 'bg-sky-500'],
        'editing' => ['label' => 'Editing', 'short' => 'Editing', 'icon' => 'scissors', 'tone' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', 'dot' => 'bg-amber-500'],
        'done' => ['label' => 'Edited', 'short' => 'Edited', 'icon' => 'check-badge', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20', 'dot' => 'bg-emerald-500'],
    ];

    protected function casts(): array
    {
        return ['shot_on' => 'date', 'edit_by' => 'date'];
    }

    /**
     * The edit-by date as a tag: what to say and how loud. Null without a date.
     *
     * @return array{label: string, tone: string, icon: string, late: bool}|null
     */
    public function deadline(): ?array
    {
        if (! $this->edit_by) {
            return null;
        }
        if ($this->status === 'done') {
            return ['label' => __('Was due :date', ['date' => $this->edit_by->toNepali(false)]), 'tone' => 'bg-zinc-100 text-zinc-500 ring-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10', 'icon' => 'calendar', 'late' => false];
        }
        $days = (int) today()->diffInDays($this->edit_by, false);

        return match (true) {
            $days < 0 => ['label' => trans_choice('Edit :count day late|Edit :count days late', -$days), 'tone' => 'bg-rose-500 text-white ring-rose-500 shadow-sm shadow-rose-500/30', 'icon' => 'exclamation-triangle', 'late' => true],
            $days === 0 => ['label' => __('Edit by today'), 'tone' => 'bg-amber-400 text-amber-950 ring-amber-400 shadow-sm shadow-amber-400/30', 'icon' => 'clock', 'late' => false],
            $days === 1 => ['label' => __('Edit by tomorrow'), 'tone' => 'bg-amber-100 text-amber-800 ring-amber-300 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-500/30', 'icon' => 'clock', 'late' => false],
            default => ['label' => __('Edit by :date', ['date' => $this->edit_by->toNepali(false)]), 'tone' => 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-500/25', 'icon' => 'calendar-days', 'late' => false],
        };
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** "Google Drive", "Dropbox"… from the link, for the card badge. */
    public function source(): string
    {
        $host = (string) parse_url($this->url, PHP_URL_HOST);

        return match (true) {
            str_contains($host, 'drive.google') || str_contains($host, 'docs.google') => 'Google Drive',
            str_contains($host, 'dropbox') => 'Dropbox',
            str_contains($host, 'wetransfer') || str_contains($host, 'we.tl') => 'WeTransfer',
            str_contains($host, 'youtu') => 'YouTube',
            str_contains($host, 'onedrive') || str_contains($host, '1drv') => 'OneDrive',
            default => preg_replace('/^www\./', '', $host) ?: 'Link',
        };
    }

    public function isFolder(): bool
    {
        return str_contains($this->url, '/folders/');
    }
}
