@props(['percent' => 0, 'color' => 'text-brand-500'])

{{-- Circular progress, 0–100, with the percentage in the middle (or the slot). --}}
<div {{ $attributes->merge(['class' => 'relative size-16 shrink-0']) }} role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
    <svg viewBox="0 0 36 36" class="size-full -rotate-90">
        <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" class="stroke-zinc-100 dark:stroke-white/10" />
        <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" stroke-linecap="round" stroke="currentColor" pathLength="100"
            stroke-dasharray="{{ max(0, min(100, $percent)) }} 100" class="{{ $color }} transition-[stroke-dasharray] duration-700" />
    </svg>
    <span class="absolute inset-0 flex items-center justify-center text-xs font-semibold text-zinc-800 tabular-nums dark:text-zinc-100">
        {{ $slot->isEmpty() ? $percent.'%' : $slot }}
    </span>
</div>
