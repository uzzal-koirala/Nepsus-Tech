@props([
    'model',              // Livewire property, e.g. lead.source
    'label' => null,
    'options' => [],      // list of ['value', 'label', 'hint'?, 'icon'?, 'tile'?, 'initials'?]
    'placeholder' => null,
    'searchable' => false,
])

@php
    $options = collect($options)->map(fn ($o) => [
        'value' => (string) $o['value'],
        'label' => $o['label'],
        'hint' => $o['hint'] ?? null,
        'icon' => $o['icon'] ?? null,
        'tile' => $o['tile'] ?? 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300',
        'initials' => $o['initials'] ?? null,
    ])->values();
    $tile = 'flex shrink-0 items-center justify-center rounded-lg';
@endphp

{{-- A dropdown with an icon, a hint and a check for each option. Binds to the Livewire property in $model. --}}
<div {{ $attributes->class('relative grid gap-2') }}
    x-data="{
        open: false,
        up: false,
        q: '',
        toggle() {
            this.open = ! this.open;
            if (! this.open) return;
            // Open upwards when there is not enough room below.
            const r = this.$refs.trigger.getBoundingClientRect();
            this.up = window.innerHeight - r.bottom < 330 && r.top > window.innerHeight - r.bottom;
            this.$nextTick(() => this.$refs.search?.focus());
        },
        value: $wire.entangle('{{ $model }}'),
        options: @js($options),
        get shown() { const q = this.q.trim().toLowerCase(); return q ? this.options.filter(o => (o.label + ' ' + (o.hint || '')).toLowerCase().includes(q)) : this.options },
        pick(v) { this.value = v; this.open = false; this.q = ''; },
    }"
    @click.outside="open = false" @keydown.escape.stop="open = false">
    @if ($label)
        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ $label }}</span>
    @endif

    <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="listbox"
        class="flex h-10 w-full items-center gap-2.5 rounded-lg border border-zinc-200 bg-white px-2 text-start text-sm shadow-xs transition hover:border-zinc-300 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none dark:border-white/10 dark:bg-white/5"
        :class="open && 'border-brand-400 ring-2 ring-brand-500/20'">
        @foreach ($options as $o)
            <span x-show="value == @js($o['value'])" x-cloak class="flex min-w-0 items-center gap-2.5">
                <span class="{{ $tile }} {{ $o['tile'] }} size-7 text-[0.625rem] font-bold">
                    @if ($o['icon'])<flux:icon :icon="$o['icon']" variant="micro" class="size-4" />@else{{ $o['initials'] }}@endif
                </span>
                <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $o['label'] }}</span>
            </span>
        @endforeach
        <span x-show="! options.some(o => o.value == value)" class="truncate ps-1 text-zinc-400">{{ $placeholder ?? __('Choose') }}</span>
        <flux:icon.chevron-down variant="micro" class="ms-auto size-4 shrink-0 text-zinc-400 transition" ::class="open && 'rotate-180'" />
    </button>

    <div x-show="open" x-cloak role="listbox"
        x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1 scale-95" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-100" x-transition:leave-end="opacity-0 scale-95"
        :class="up ? 'bottom-full mb-1.5 origin-bottom' : 'top-full mt-1.5 origin-top'"
        class="absolute inset-x-0 z-40 min-w-60 overflow-hidden rounded-xl bg-white shadow-[0_24px_60px_-16px_rgb(15_23_42/0.4)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10">
        @if ($searchable)
            <div class="border-b border-zinc-100 p-2 dark:border-white/5">
                <div class="relative">
                    <flux:icon.magnifying-glass variant="micro" class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-zinc-400" />
                    <input x-ref="search" x-model="q" type="text" placeholder="{{ __('Search...') }}" class="h-9 w-full rounded-lg border-0 bg-zinc-50 ps-8 pe-3 text-sm text-zinc-900 placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 dark:bg-white/5 dark:text-white" @keydown.enter.prevent="shown[0] && pick(shown[0].value)">
                </div>
            </div>
        @endif
        <div class="flex max-h-72 flex-col gap-0.5 overflow-y-auto p-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($options as $o)
                <button type="button" role="option" x-show="shown.some(s => s.value == @js($o['value']))" @click="pick(@js($o['value']))" :aria-selected="value == @js($o['value'])"
                    class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-start transition hover:bg-zinc-50 dark:hover:bg-white/5"
                    :class="value == @js($o['value']) && 'bg-brand-50/80 hover:bg-brand-50 dark:bg-brand-500/10'">
                    <span class="{{ $tile }} {{ $o['tile'] }} size-9 text-xs font-bold">
                        @if ($o['icon'])<flux:icon :icon="$o['icon']" variant="mini" class="size-4.5" />@else{{ $o['initials'] }}@endif
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $o['label'] }}</span>
                        @if ($o['hint'])<span class="block truncate text-xs text-zinc-500">{{ $o['hint'] }}</span>@endif
                    </span>
                    <flux:icon.check-circle variant="mini" class="size-5 shrink-0 text-brand-600" x-show="value == @js($o['value'])" x-cloak />
                </button>
            @endforeach
            <p x-show="shown.length === 0" x-cloak class="px-3 py-6 text-center text-sm text-zinc-500">{{ __('Nothing found') }}</p>
        </div>
    </div>
    <flux:error :name="$model" />
</div>
