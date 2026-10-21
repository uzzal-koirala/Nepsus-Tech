<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A folder on the Documents page. "other" is permanent: deleted folders move their files there. */
#[Fillable(['slug', 'name', 'icon', 'position'])]
class DocumentFolder extends Model
{
    public const FALLBACK = 'other';

    public static function add(string $name): self
    {
        $base = Str::slug($name) ?: 'folder';
        $slug = Str::limit($base, 24, '');
        for ($n = 2; static::where('slug', $slug)->exists(); $n++) {
            $slug = Str::limit($base, 20, '').'-'.$n;
        }

        // New folders go just before "Other".
        $position = (int) static::where('slug', '!=', self::FALLBACK)->max('position') + 1;
        static::where('slug', self::FALLBACK)->update(['position' => $position + 1]);

        return static::create(['slug' => $slug, 'name' => $name, 'icon' => 'folder', 'position' => $position]);
    }

    /** Moves the folder's files to "Other", then deletes it. */
    public function deleteMovingFiles(): int
    {
        $moved = CompanyDocument::where('folder', $this->slug)->update(['folder' => self::FALLBACK]);
        $this->delete();

        return $moved;
    }
}
