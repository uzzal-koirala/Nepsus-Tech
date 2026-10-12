<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A kind of work (Post, Reel, Design...) that the team can add or remove. */
#[Fillable(['slug', 'name', 'color', 'icon', 'position'])]
class WorkType extends Model
{
    /** Colours given to new types in turn (the view maps each to Tailwind classes). */
    public const COLORS = ['sky', 'fuchsia', 'amber', 'rose', 'orange', 'emerald', 'violet', 'teal', 'indigo', 'lime', 'pink', 'cyan'];

    public static function add(string $name): self
    {
        $base = Str::slug($name) ?: 'type';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return static::create([
            'slug' => Str::limit($slug, 40, ''),
            'name' => $name,
            'color' => self::COLORS[static::count() % count(self::COLORS)],
            'icon' => 'tag',
            'position' => (int) static::max('position') + 1,
        ]);
    }
}
