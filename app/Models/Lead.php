<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Fillable(['name', 'company', 'phone', 'email', 'source', 'service_type', 'value', 'stage', 'employee_id', 'next_follow_up', 'notes', 'lost_reason', 'client_id', 'closed_at', 'position'])]
class Lead extends Model
{
    /** Pipeline columns, in order. Won and Lost close the deal. */
    public const STAGES = [
        'new' => ['label' => 'New', 'icon' => 'sparkles', 'dot' => 'bg-sky-500', 'tone' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20', 'chance' => 10],
        'contacted' => ['label' => 'Contacted', 'icon' => 'chat-bubble-left-right', 'dot' => 'bg-violet-500', 'tone' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20', 'chance' => 25],
        'proposal' => ['label' => 'Proposal sent', 'icon' => 'document-text', 'dot' => 'bg-amber-500', 'tone' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', 'chance' => 50],
        'negotiation' => ['label' => 'Negotiation', 'icon' => 'scale', 'dot' => 'bg-orange-500', 'tone' => 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20', 'chance' => 75],
        'won' => ['label' => 'Won', 'icon' => 'trophy', 'dot' => 'bg-emerald-500', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20', 'chance' => 100],
        'lost' => ['label' => 'Lost', 'icon' => 'x-circle', 'dot' => 'bg-zinc-400', 'tone' => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10', 'chance' => 0],
    ];

    /** Where the lead came from. */
    public const SOURCES = [
        'facebook' => ['label' => 'Facebook', 'icon' => 'hand-thumb-up', 'color' => 'bg-blue-500'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'camera', 'color' => 'bg-pink-500'],
        'tiktok' => ['label' => 'TikTok', 'icon' => 'musical-note', 'color' => 'bg-zinc-800'],
        'google' => ['label' => 'Google', 'icon' => 'magnifying-glass', 'color' => 'bg-amber-500'],
        'website' => ['label' => 'Website', 'icon' => 'globe-alt', 'color' => 'bg-brand-500'],
        'referral' => ['label' => 'Referral', 'icon' => 'user-plus', 'color' => 'bg-emerald-500'],
        'walk_in' => ['label' => 'Walk-in', 'icon' => 'building-storefront', 'color' => 'bg-teal-500'],
        'cold_call' => ['label' => 'Cold call', 'icon' => 'phone-arrow-up-right', 'color' => 'bg-violet-500'],
        'other' => ['label' => 'Other', 'icon' => 'ellipsis-horizontal-circle', 'color' => 'bg-zinc-400'],
    ];

    public const ACTIVITY_TYPES = [
        'call' => ['label' => 'Call', 'icon' => 'phone', 'tone' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300'],
        'meeting' => ['label' => 'Meeting', 'icon' => 'users', 'tone' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300'],
        'message' => ['label' => 'Message', 'icon' => 'chat-bubble-left-ellipsis', 'tone' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300'],
        'email' => ['label' => 'Email', 'icon' => 'envelope', 'tone' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300'],
        'note' => ['label' => 'Note', 'icon' => 'pencil-square', 'tone' => 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300'],
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'next_follow_up' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:120'],
            'source' => ['required', Rule::in(array_keys(self::SOURCES))],
            'service_type' => ['nullable', Rule::in(array_keys(Client::SERVICE_TYPES))],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'stage' => ['required', Rule::in(array_keys(self::STAGES))],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'next_follow_up' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Still being worked on (not won or lost). */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('stage', ['won', 'lost']);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, ['won', 'lost'], true);
    }

    public function stageMeta(): array
    {
        return self::STAGES[$this->stage] ?? self::STAGES['new'];
    }

    public function sourceMeta(): array
    {
        return self::SOURCES[$this->source] ?? self::SOURCES['other'];
    }

    public function displayName(): string
    {
        return $this->company ?: $this->name;
    }

    public function initials(): string
    {
        return Str::upper(Str::substr(Str::initials($this->displayName(), true) ?: '?', 0, 2));
    }

    /** True when a follow-up date is today or already past (open leads only). */
    public function followUpDue(): bool
    {
        return $this->isOpen() && $this->next_follow_up !== null && $this->next_follow_up->lte(today());
    }

    /**
     * Moves the lead to a stage and records it on the timeline. Closing (won/lost) stamps the date.
     */
    public function moveTo(string $stage, ?string $lostReason = null): void
    {
        if ($stage === $this->stage || ! array_key_exists($stage, self::STAGES)) {
            return;
        }

        $from = $this->stageMeta()['label'];
        $closing = in_array($stage, ['won', 'lost'], true);

        $this->update([
            'stage' => $stage,
            'closed_at' => $closing ? now() : null,
            'lost_reason' => $stage === 'lost' ? $lostReason : null,
            'next_follow_up' => $closing ? null : $this->next_follow_up,
        ]);

        $this->activities()->create([
            'user_id' => auth()->id(),
            'type' => 'stage',
            'body' => __('Moved from :from to :to', ['from' => __($from), 'to' => __(self::STAGES[$stage]['label'])]).($stage === 'lost' && $lostReason ? ' · '.$lostReason : ''),
        ]);
    }
}
