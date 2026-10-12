<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['type', 'name', 'path', 'size'])]
class EmployeeDocument extends Model
{
    public const TYPES = [
        'agreement' => ['label' => 'Agreement', 'icon' => 'document-check'],
        'id' => ['label' => 'ID / Citizenship', 'icon' => 'identification'],
        'cv' => ['label' => 'CV', 'icon' => 'document-text'],
        'certificate' => ['label' => 'Certificate', 'icon' => 'academic-cap'],
        'other' => ['label' => 'Other', 'icon' => 'paper-clip'],
    ];

    protected static function booted(): void
    {
        static::deleted(fn (EmployeeDocument $doc) => Storage::disk('local')->delete($doc->path));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
