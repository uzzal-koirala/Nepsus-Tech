<?php

use App\Models\Client;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Clients for employee logins: who they are and the work, never the money. */
new #[Title('Clients')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Computed]
    public function clients()
    {
        $me = auth()->user()->employee?->id ?? 0;

        return Client::where('status', 'active')
            ->withCount([
                'tasks as open_tasks' => fn ($q) => $q->whereNull('completed_at'),
                'tasks as my_tasks' => fn ($q) => $q->whereNull('completed_at')->whereHas('assignees', fn ($a) => $a->whereKey($me)),
                'scripts', 'videos',
            ])
            ->orderByDesc('my_tasks')->orderBy('company')->orderBy('name')->get();
    }
}; ?>

@php
    $q = mb_strtolower(trim($search));
    $clients = $q === '' ? $this->clients : $this->clients->filter(fn ($c) => str_contains(mb_strtolower($c->displayName().' '.$c->name.' '.$c->phone), $q));
    $services = Client::SERVICE_TYPES;
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Clients') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Who we work for, with their tasks, scripts and footage. Yours come first.') }}</p>
        </div>
        <div class="w-full sm:w-72"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search clients')" /></div>
    </header>

    @if ($clients->isEmpty())
        <section class="flex flex-col items-center gap-3 rounded-xl bg-white px-6 py-16 text-center ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
            <span class="flex size-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.building-office-2 class="size-8" /></span>
            <p class="font-semibold text-zinc-900 dark:text-white">{{ $q !== '' ? __('Nothing matches') : __('No clients yet') }}</p>
        </section>
    @else
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3 2xl:grid-cols-4">
            @foreach ($clients as $c)
                <a href="{{ route('clients.show', $c) }}" wire:navigate wire:key="c-{{ $c->id }}" class="group flex min-w-0 flex-col rounded-xl bg-white p-4 shadow-[0_1px_2px_rgb(15_23_42/0.06)] sm:p-5 ring-1 ring-zinc-200/70 transition duration-200 hover:-translate-y-0.5 hover:shadow-[0_12px_32px_-12px_rgb(15_23_42/0.22)] dark:bg-zinc-950 dark:ring-white/10" data-test="client-{{ $c->id }}">
                    <div class="flex items-start gap-3">
                        @if ($c->avatarUrl())
                            <img src="{{ $c->avatarUrl() }}" alt="" class="size-12 shrink-0 rounded-xl object-cover">
                        @else
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-violet-600 text-sm font-bold text-white shadow-md shadow-brand-500/20">{{ $c->initials() }}</span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $c->displayName() }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ $c->contactName() }}</p>
                            @if ($s = $services[$c->service_type] ?? null)
                                <span class="mt-1.5 inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[0.6875rem] font-medium text-zinc-600 dark:bg-white/5 dark:text-zinc-300"><flux:icon :icon="$s['icon']" variant="micro" class="size-3" />{{ __($s['label']) }}</span>
                            @endif
                        </div>
                        @if ($c->my_tasks)
                            <span class="shrink-0 rounded-full bg-brand-500 px-2 py-0.5 text-[0.6875rem] font-semibold text-white shadow-sm shadow-brand-500/30">{{ trans_choice(':count for you|:count for you', $c->my_tasks) }}</span>
                        @endif
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                        @foreach ([[__('Open tasks'), $c->open_tasks], [__('Scripts'), $c->scripts_count], [__('Videos'), $c->videos_count]] as [$label, $n])
                            <div class="min-w-0 rounded-lg bg-zinc-50 px-1 py-2 dark:bg-white/[0.03]">
                                <p class="text-base font-bold text-zinc-900 tabular-nums dark:text-white">{{ $n }}</p>
                                <p class="truncate text-[0.6875rem] text-zinc-500">{{ $label }}</p>
                            </div>
                        @endforeach
                    </div>
                </a>
            @endforeach
        </section>
    @endif
</div>
