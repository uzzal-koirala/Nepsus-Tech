<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['title', 'description', 'type', 'status', 'priority', 'start_date', 'due_date', 'completed_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Work types from the database, keyed by slug: [slug => [label, icon, color]].
     *
     * @return array<string, array{label: string, icon: string, color: string}>
     */
    public static function types(): array
    {
        return WorkType::orderBy('position')->orderBy('id')->get()
            ->mapWithKeys(fn ($w) => [$w->slug => ['label' => $w->name, 'icon' => $w->icon, 'color' => $w->color]])
            ->all();
    }

    /** Shown for a task whose type was deleted. */
    public static function unknownType(?string $slug): array
    {
        return ['label' => $slug ? Str::headline($slug) : 'Task', 'icon' => 'tag', 'color' => 'zinc'];
    }

    /** Workflow stages, in board order. */
    public const STATUSES = [
        'todo' => ['label' => 'To do', 'icon' => 'stop-circle'],
        'in_progress' => ['label' => 'In progress', 'icon' => 'arrow-path'],
        'review' => ['label' => 'In review', 'icon' => 'eye'],
        'done' => ['label' => 'Done', 'icon' => 'check-circle'],
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    public const PRIORITY_STYLES = [
        'low' => ['icon' => 'arrow-down', 'tone' => 'bg-zinc-50 text-zinc-500 ring-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10'],
        'medium' => ['icon' => 'minus', 'tone' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20'],
        'high' => ['icon' => 'arrow-up', 'tone' => 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20'],
        'urgent' => ['icon' => 'fire', 'tone' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20'],
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'start_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class);
    }

    /** The stage shown in lists: completed work is always done. */
    public function stage(): string
    {
        if ($this->completed_at) {
            return 'done';
        }

        return array_key_exists($this->status, self::STATUSES) && $this->status !== 'done' ? $this->status : 'todo';
    }

    public function isOverdue(): bool
    {
        return $this->completed_at === null && $this->due_date !== null && $this->due_date->isBefore(today());
    }
}
