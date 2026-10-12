<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'description', 'type', 'status', 'priority', 'start_date', 'due_date', 'completed_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /** Kinds of work, shown colour-coded on the client's work calendar. */
    public const TYPES = [
        'post' => ['label' => 'Post', 'icon' => 'photo'],
        'reel' => ['label' => 'Reel', 'icon' => 'film'],
        'story' => ['label' => 'Story', 'icon' => 'bolt'],
        'video' => ['label' => 'Video', 'icon' => 'video-camera'],
        'shoot' => ['label' => 'Photoshoot', 'icon' => 'camera'],
        'design' => ['label' => 'Design', 'icon' => 'paint-brush'],
        'meeting' => ['label' => 'Meeting', 'icon' => 'users'],
        'task' => ['label' => 'Task', 'icon' => 'check-circle'],
    ];

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
