<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'content', 'actions'])]
class AiMessage extends Model
{
    protected function casts(): array
    {
        return ['actions' => 'array'];
    }
}
