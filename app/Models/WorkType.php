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

    /** Tailwind classes for each colour: dot, chip (list pill) and pick (selected). */
    public const PALETTE = [
        'sky' => ['dot' => 'bg-sky-500', 'chip' => 'bg-sky-50 text-sky-700 ring-sky-200/80 hover:bg-sky-100 dark:bg-sky-500/10 dark:text-sky-200 dark:ring-sky-500/20', 'pick' => 'bg-sky-50 text-sky-700 ring-sky-500 dark:bg-sky-500/10 dark:text-sky-200'],
        'fuchsia' => ['dot' => 'bg-fuchsia-500', 'chip' => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200/80 hover:bg-fuchsia-100 dark:bg-fuchsia-500/10 dark:text-fuchsia-200 dark:ring-fuchsia-500/20', 'pick' => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-500 dark:bg-fuchsia-500/10 dark:text-fuchsia-200'],
        'amber' => ['dot' => 'bg-amber-400', 'chip' => 'bg-amber-50 text-amber-700 ring-amber-200/80 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20', 'pick' => 'bg-amber-50 text-amber-700 ring-amber-400 dark:bg-amber-500/10 dark:text-amber-200'],
        'rose' => ['dot' => 'bg-rose-500', 'chip' => 'bg-rose-50 text-rose-700 ring-rose-200/80 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20', 'pick' => 'bg-rose-50 text-rose-700 ring-rose-500 dark:bg-rose-500/10 dark:text-rose-200'],
        'orange' => ['dot' => 'bg-orange-500', 'chip' => 'bg-orange-50 text-orange-700 ring-orange-200/80 hover:bg-orange-100 dark:bg-orange-500/10 dark:text-orange-200 dark:ring-orange-500/20', 'pick' => 'bg-orange-50 text-orange-700 ring-orange-500 dark:bg-orange-500/10 dark:text-orange-200'],
        'emerald' => ['dot' => 'bg-emerald-500', 'chip' => 'bg-emerald-50 text-emerald-700 ring-emerald-200/80 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20', 'pick' => 'bg-emerald-50 text-emerald-700 ring-emerald-500 dark:bg-emerald-500/10 dark:text-emerald-200'],
        'violet' => ['dot' => 'bg-violet-500', 'chip' => 'bg-violet-50 text-violet-700 ring-violet-200/80 hover:bg-violet-100 dark:bg-violet-500/10 dark:text-violet-200 dark:ring-violet-500/20', 'pick' => 'bg-violet-50 text-violet-700 ring-violet-500 dark:bg-violet-500/10 dark:text-violet-200'],
        'zinc' => ['dot' => 'bg-zinc-400', 'chip' => 'bg-zinc-50 text-zinc-700 ring-zinc-200/80 hover:bg-zinc-100 dark:bg-zinc-500/10 dark:text-zinc-200 dark:ring-zinc-500/20', 'pick' => 'bg-zinc-100 text-zinc-800 ring-zinc-500 dark:bg-white/10 dark:text-zinc-100'],
        'teal' => ['dot' => 'bg-teal-500', 'chip' => 'bg-teal-50 text-teal-700 ring-teal-200/80 hover:bg-teal-100 dark:bg-teal-500/10 dark:text-teal-200 dark:ring-teal-500/20', 'pick' => 'bg-teal-50 text-teal-700 ring-teal-500 dark:bg-teal-500/10 dark:text-teal-200'],
        'indigo' => ['dot' => 'bg-indigo-500', 'chip' => 'bg-indigo-50 text-indigo-700 ring-indigo-200/80 hover:bg-indigo-100 dark:bg-indigo-500/10 dark:text-indigo-200 dark:ring-indigo-500/20', 'pick' => 'bg-indigo-50 text-indigo-700 ring-indigo-500 dark:bg-indigo-500/10 dark:text-indigo-200'],
        'lime' => ['dot' => 'bg-lime-500', 'chip' => 'bg-lime-50 text-lime-700 ring-lime-200/80 hover:bg-lime-100 dark:bg-lime-500/10 dark:text-lime-200 dark:ring-lime-500/20', 'pick' => 'bg-lime-50 text-lime-700 ring-lime-500 dark:bg-lime-500/10 dark:text-lime-200'],
        'pink' => ['dot' => 'bg-pink-500', 'chip' => 'bg-pink-50 text-pink-700 ring-pink-200/80 hover:bg-pink-100 dark:bg-pink-500/10 dark:text-pink-200 dark:ring-pink-500/20', 'pick' => 'bg-pink-50 text-pink-700 ring-pink-500 dark:bg-pink-500/10 dark:text-pink-200'],
        'cyan' => ['dot' => 'bg-cyan-500', 'chip' => 'bg-cyan-50 text-cyan-700 ring-cyan-200/80 hover:bg-cyan-100 dark:bg-cyan-500/10 dark:text-cyan-200 dark:ring-cyan-500/20', 'pick' => 'bg-cyan-50 text-cyan-700 ring-cyan-500 dark:bg-cyan-500/10 dark:text-cyan-200'],
    ];

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
