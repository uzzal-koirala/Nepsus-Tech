<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'body'])]
class LeadActivity extends Model
{
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeMeta(): array
    {
        return Lead::ACTIVITY_TYPES[$this->type] ?? ['label' => 'Stage', 'icon' => 'arrows-right-left', 'tone' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300'];
    }
}
