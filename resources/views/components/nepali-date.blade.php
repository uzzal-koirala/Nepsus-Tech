@props([
    'label' => null,
    'name' => null,
    'placeholder' => 'Pick a date',
])

@php
    $model = $attributes->whereStartsWith('wire:model')->first();
    $field = $name ?? $model;
@endphp

<div
    x-data="nepaliDate"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    {{ $attributes->whereDoesntStartWith('wire:model')->class('relative grid gap-2') }}
    @keydown.escape.stop="open = false"
    @click.outside="open = false"
>
    @if ($label)
        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ $label }}</span>
    @endif

    <button
        type="button"
        @click="toggle()"
        class="flex h-10 w-full items-center gap-2.5 rounded-lg border border-zinc-200 bg-white px-3 text-start text-sm shadow-xs transition hover:border-zinc-300 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none dark:border-white/10 dark:bg-white/5"
        :aria-expanded="open"
    >
        <flux:icon.calendar-days variant="micro" class="size-4 shrink-0 text-zinc-400" />
        <span class="flex-1 truncate" :class="value ? 'text-zinc-900 dark:text-white' : 'text-zinc-400'" x-text="label || @js(__($placeholder))"></span>
        <span class="text-xs text-zinc-400 tabular-nums" x-text="adLabel"></span>
    </button>

    <div
        x-show="open"
        x-transition.origin.top.left
        x-cloak
        class="absolute top-full left-0 z-30 mt-1.5 w-72 rounded-xl bg-white p-3 shadow-[0_20px_50px_-20px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10"
    >
        <div class="mb-2 flex items-center justify-between gap-1">
            <button type="button" @click="shift(-1)" class="flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5" aria-label="{{ __('Previous month') }}">
                <flux:icon.chevron-left variant="micro" class="size-4" />
            </button>
            <div class="flex items-center gap-1">
                <select x-model.number="viewM" class="rounded-md border-0 bg-transparent py-1 ps-2 pe-7 text-sm font-semibold text-zinc-900 focus:ring-2 focus:ring-brand-500 dark:text-white" aria-label="{{ __('Month') }}">
                    <template x-for="(month, i) in months" :key="i">
                        <option :value="i" x-text="month" :selected="i === viewM"></option>
                    </template>
                </select>
                <select x-model.number="viewY" class="rounded-md border-0 bg-transparent py-1 ps-2 pe-7 text-sm font-semibold text-zinc-900 focus:ring-2 focus:ring-brand-500 dark:text-white" aria-label="{{ __('Year') }}">
                    <template x-for="year in years" :key="year">
                        <option :value="year" x-text="year" :selected="year === viewY"></option>
                    </template>
                </select>
            </div>
            <button type="button" @click="shift(1)" class="flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5" aria-label="{{ __('Next month') }}">
                <flux:icon.chevron-right variant="micro" class="size-4" />
            </button>
        </div>

        <div class="grid grid-cols-7 text-center text-[0.6875rem] font-medium text-zinc-400">
            <template x-for="(day, i) in weekdays" :key="i">
                <span class="py-1" :class="i === 6 && 'text-rose-400'" x-text="day"></span>
            </template>
        </div>

        <div class="grid grid-cols-7 gap-0.5">
            <template x-for="(day, i) in cells" :key="viewY + '-' + viewM + '-' + i">
                <div class="aspect-square">
                    <button
                        type="button"
                        x-show="day"
                        @click="pick(day)"
                        class="flex size-full items-center justify-center rounded-md text-sm transition"
                        :class="isSelected(day)
                            ? 'bg-brand-500 font-semibold text-white shadow-sm shadow-brand-500/40'
                            : (isToday(day) ? 'font-semibold text-brand-600 ring-1 ring-brand-300 dark:text-brand-300' : (i % 7 === 6 ? 'text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/5'))"
                        x-text="day || ''"
                    ></button>
                </div>
            </template>
        </div>

        <div class="mt-2 flex items-center justify-between border-t border-zinc-100 pt-2 dark:border-white/5">
            <button type="button" @click="pickToday()" class="rounded-md px-2 py-1 text-xs font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">{{ __('Today') }}</button>
            <button type="button" @click="clear()" class="rounded-md px-2 py-1 text-xs font-medium text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5">{{ __('Clear') }}</button>
        </div>
    </div>

    @if ($field)
        <flux:error :name="$field" />
    @endif
</div>
