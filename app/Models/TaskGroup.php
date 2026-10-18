<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A box of tasks under one title, e.g. "Dashain campaign". Deleting it deletes its tasks. */
#[Fillable(['name', 'client_id', 'color', 'position'])]
class TaskGroup extends Model
{
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
