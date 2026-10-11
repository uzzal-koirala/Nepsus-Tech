<?php

namespace App\Support;

/**
 * Colour themes a package can use. Class names are written out in full so
 * Tailwind finds them (app.css has @source for this folder).
 */
final class PackageTheme
{
    public const THEMES = [
        'green' => [
            'label' => 'Green',
            'swatch' => 'bg-emerald-500',
            'chip' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
            'card' => 'from-emerald-50 ring-emerald-200/80 hover:ring-emerald-300 hover:shadow-[0_18px_40px_-24px_rgb(5_150_105/0.45)] dark:from-emerald-500/[0.07] dark:ring-emerald-500/20',
            'cover' => 'from-emerald-400 via-emerald-500 to-teal-600 text-white',
            'icon' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300',
            'pick' => 'bg-emerald-50 text-emerald-700 ring-2 ring-emerald-500 dark:bg-emerald-500/10 dark:text-emerald-300',
        ],
        'blue' => [
            'label' => 'Blue',
            'swatch' => 'bg-brand-500',
            'chip' => 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-600/20 dark:bg-brand-500/15 dark:text-brand-200',
            'card' => 'from-brand-50 ring-brand-200/80 hover:ring-brand-300 hover:shadow-[0_18px_40px_-24px_rgb(49_81_254/0.45)] dark:from-brand-500/[0.09] dark:ring-brand-500/25',
            'cover' => 'from-brand-400 via-brand-500 to-brand-700 text-white',
            'icon' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
            'pick' => 'bg-brand-50 text-brand-700 ring-2 ring-brand-500 dark:bg-brand-500/10 dark:text-brand-200',
        ],
        'gold' => [
            'label' => 'Gold',
            'swatch' => 'bg-linear-to-br from-amber-300 to-amber-500',
            'chip' => 'bg-linear-to-r from-amber-300 via-yellow-200 to-amber-300 text-amber-950 shadow-sm shadow-amber-500/30 ring-1 ring-inset ring-amber-500/40',
            'card' => 'from-amber-100/70 ring-amber-300 hover:ring-amber-400 hover:shadow-[0_18px_40px_-22px_rgb(217_119_6/0.55)] dark:from-amber-400/[0.1] dark:ring-amber-400/40',
            'cover' => 'from-amber-300 via-yellow-200 to-amber-500 text-amber-950',
            'icon' => 'bg-amber-100 text-amber-600 dark:bg-amber-400/15 dark:text-amber-300',
            'pick' => 'bg-amber-50 text-amber-700 ring-2 ring-amber-400 dark:bg-amber-500/10 dark:text-amber-300',
        ],
        'black' => [
            'label' => 'Black card',
            'swatch' => 'bg-linear-to-br from-zinc-800 to-slate-600',
            'chip' => 'bg-linear-to-r from-zinc-900 to-slate-700 text-cyan-100 shadow-sm shadow-zinc-900/30 ring-1 ring-inset ring-white/10 dark:from-white dark:to-slate-200 dark:text-zinc-900',
            'card' => 'from-slate-100 ring-slate-300 hover:ring-slate-400 hover:shadow-[0_18px_40px_-22px_rgb(15_23_42/0.5)] dark:from-white/[0.06] dark:ring-white/20',
            'cover' => 'from-slate-700 via-slate-600 to-slate-500 text-white',
            'icon' => 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200',
            'pick' => 'bg-slate-100 text-slate-800 ring-2 ring-slate-500 dark:bg-white/10 dark:text-slate-200',
        ],
        'rose' => [
            'label' => 'Rose',
            'swatch' => 'bg-rose-500',
            'chip' => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300',
            'card' => 'from-rose-50 ring-rose-200/80 hover:ring-rose-300 hover:shadow-[0_18px_40px_-24px_rgb(225_29_72/0.4)] dark:from-rose-500/[0.07] dark:ring-rose-500/20',
            'cover' => 'from-rose-400 via-rose-500 to-pink-600 text-white',
            'icon' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300',
            'pick' => 'bg-rose-50 text-rose-700 ring-2 ring-rose-500 dark:bg-rose-500/10 dark:text-rose-300',
        ],
        'violet' => [
            'label' => 'Violet',
            'swatch' => 'bg-violet-500',
            'chip' => 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300',
            'card' => 'from-violet-50 ring-violet-200/80 hover:ring-violet-300 hover:shadow-[0_18px_40px_-24px_rgb(124_58_237/0.4)] dark:from-violet-500/[0.07] dark:ring-violet-500/20',
            'cover' => 'from-violet-400 via-violet-500 to-purple-600 text-white',
            'icon' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300',
            'pick' => 'bg-violet-50 text-violet-700 ring-2 ring-violet-500 dark:bg-violet-500/10 dark:text-violet-300',
        ],
        'sky' => [
            'label' => 'Sky',
            'swatch' => 'bg-sky-500',
            'chip' => 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300',
            'card' => 'from-sky-50 ring-sky-200/80 hover:ring-sky-300 hover:shadow-[0_18px_40px_-24px_rgb(2_132_199/0.4)] dark:from-sky-500/[0.07] dark:ring-sky-500/20',
            'cover' => 'from-sky-400 via-sky-500 to-cyan-600 text-white',
            'icon' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300',
            'pick' => 'bg-sky-50 text-sky-700 ring-2 ring-sky-500 dark:bg-sky-500/10 dark:text-sky-300',
        ],
        'orange' => [
            'label' => 'Orange',
            'swatch' => 'bg-orange-500',
            'chip' => 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-300',
            'card' => 'from-orange-50 ring-orange-200/80 hover:ring-orange-300 hover:shadow-[0_18px_40px_-24px_rgb(234_88_12/0.4)] dark:from-orange-500/[0.07] dark:ring-orange-500/20',
            'cover' => 'from-orange-400 via-orange-500 to-red-500 text-white',
            'icon' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-300',
            'pick' => 'bg-orange-50 text-orange-700 ring-2 ring-orange-500 dark:bg-orange-500/10 dark:text-orange-300',
        ],
        'teal' => [
            'label' => 'Teal',
            'swatch' => 'bg-teal-500',
            'chip' => 'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-600/20 dark:bg-teal-500/10 dark:text-teal-300',
            'card' => 'from-teal-50 ring-teal-200/80 hover:ring-teal-300 hover:shadow-[0_18px_40px_-24px_rgb(13_148_136/0.4)] dark:from-teal-500/[0.07] dark:ring-teal-500/20',
            'cover' => 'from-teal-400 via-teal-500 to-emerald-600 text-white',
            'icon' => 'bg-teal-50 text-teal-600 dark:bg-teal-500/10 dark:text-teal-300',
            'pick' => 'bg-teal-50 text-teal-700 ring-2 ring-teal-500 dark:bg-teal-500/10 dark:text-teal-300',
        ],
    ];

    /** Icons a package can use (Flux/Heroicons names plus the Lucide ones in resources/views/flux/icon). */
    public const ICONS = [
        'sprout', 'star', 'crown', 'gem', 'rocket-launch', 'bolt', 'fire', 'sparkles',
        'trophy', 'heart', 'megaphone', 'globe-alt', 'camera', 'video-camera', 'paint-brush',
        'code-bracket', 'shopping-bag', 'briefcase', 'chart-bar', 'light-bulb', 'gift', 'cube',
    ];

    /**
     * @return array<string, string>
     */
    public static function get(?string $color): array
    {
        return self::THEMES[$color] ?? self::THEMES['blue'];
    }
}
