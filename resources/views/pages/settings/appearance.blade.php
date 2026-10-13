<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div x-data class="grid gap-4 sm:grid-cols-3">
            @foreach (['light' => ['Light', 'sun', 'bg-white', 'bg-zinc-100'], 'dark' => ['Dark', 'moon', 'bg-zinc-900', 'bg-zinc-700'], 'system' => ['System', 'computer-desktop', 'bg-linear-to-r from-white from-50% to-zinc-900 to-50%', 'bg-zinc-300']] as $mode => [$name, $icon, $screen, $bar])
                <button type="button" @click="$flux.appearance = '{{ $mode }}'" class="group overflow-hidden rounded-2xl text-start ring-1 transition" :class="$flux.appearance === '{{ $mode }}' ? 'ring-2 ring-brand-500 shadow-lg shadow-brand-500/15' : 'ring-zinc-200 hover:ring-zinc-300 dark:ring-white/10'">
                    <div class="{{ $screen }} relative h-28 border-b border-zinc-200/70 p-3 dark:border-white/10">
                        <div class="flex h-full gap-2">
                            <div class="{{ $bar }} w-1/4 rounded-md opacity-80"></div>
                            <div class="flex flex-1 flex-col gap-1.5">
                                <div class="h-2.5 w-3/4 rounded bg-brand-500"></div>
                                <div class="{{ $bar }} h-2 w-full rounded opacity-70"></div>
                                <div class="{{ $bar }} h-2 w-2/3 rounded opacity-70"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 bg-white px-4 py-3 dark:bg-zinc-950">
                        <flux:icon :icon="$icon" variant="mini" class="size-4 text-zinc-500" />
                        <span class="flex-1 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __($name) }}</span>
                        <span class="flex size-5 items-center justify-center rounded-full border-[1.5px] transition" :class="$flux.appearance === '{{ $mode }}' ? 'border-brand-500 bg-brand-500 text-white' : 'border-zinc-300 text-transparent dark:border-zinc-600'"><flux:icon.check variant="micro" class="size-3" /></span>
                    </div>
                </button>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
