<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['type', 'name', 'path', 'size'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    public const TYPES = ['agreement' => 'Agreement', 'invoice' => 'Invoice', 'proposal' => 'Proposal', 'other' => 'Other'];

    protected static function booted(): void
    {
        static::deleted(fn (Document $document) => Storage::disk('local')->delete($document->path));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
