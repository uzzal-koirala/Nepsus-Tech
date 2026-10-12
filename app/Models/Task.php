<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'type', 'due_date', 'completed_at'])]
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

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isOverdue(): bool
    {
        return $this->completed_at === null && $this->due_date !== null && $this->due_date->isBefore(today());
    }
}
