<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A file in the company's own document library (not tied to a client or employee). */
#[Fillable(['name', 'folder', 'path', 'extension', 'mime', 'size', 'note', 'uploaded_by'])]
class CompanyDocument extends Model
{
    /**
     * Folders in their order: [slug => [label, icon]].
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function folders(): array
    {
        return DocumentFolder::orderBy('position')->orderBy('id')->get()
            ->mapWithKeys(fn ($f) => [$f->slug => ['label' => $f->name, 'icon' => $f->icon]])->all();
    }

    /** What can be uploaded, at most 20 MB each. */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'zip'];

    protected static function booted(): void
    {
        static::deleted(fn (CompanyDocument $d) => Storage::disk('local')->delete($d->path));
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** image, pdf, word, sheet, slides, text, zip or file. */
    public function kind(): string
    {
        return match ($this->extension) {
            'png', 'jpg', 'jpeg', 'webp', 'gif' => 'image',
            'pdf' => 'pdf',
            'doc', 'docx' => 'word',
            'xls', 'xlsx', 'csv' => 'sheet',
            'ppt', 'pptx' => 'slides',
            'txt' => 'text',
            'zip' => 'zip',
            default => 'file',
        };
    }

    /** Browsers can show these themselves. */
    public function previewable(): bool
    {
        return in_array($this->kind(), ['image', 'pdf', 'text'], true);
    }

    public function readableSize(): string
    {
        return static::humanSize($this->size);
    }

    /** 1536 -> "1.5 KB". */
    public static function humanSize(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return ($i === 0 ? (int) $bytes : rtrim(rtrim(number_format($bytes, 1), '0'), '.')).' '.$units[$i];
    }
}
