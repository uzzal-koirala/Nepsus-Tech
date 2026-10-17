<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'kind', 'date', 'time', 'client_id', 'note'])]
class CalendarEvent extends Model
{
    /** Events people add by hand. */
    public const KINDS = [
        'meeting' => ['label' => 'Meeting', 'hint' => 'In person or online', 'icon' => 'users'],
        'call' => ['label' => 'Call', 'hint' => 'Phone or video call', 'icon' => 'phone'],
        'shoot' => ['label' => 'Shoot', 'hint' => 'Photo or video shoot', 'icon' => 'video-camera'],
        'deadline' => ['label' => 'Deadline', 'hint' => 'Something must be ready', 'icon' => 'flag'],
        'reminder' => ['label' => 'Reminder', 'hint' => 'Anything to remember', 'icon' => 'bell'],
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
