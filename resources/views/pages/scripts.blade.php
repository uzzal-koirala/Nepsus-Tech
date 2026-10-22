<?php

use App\Models\Client;
use App\Models\Script;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Scripts')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $clientFilter = '';

    #[Computed]
    public function all()
    {
        return Script::with('client')->latest('updated_at')->get();
    }

    #[Computed]
    public function visible()
    {
        $q = mb_strtolower(trim($this->search));

        return $this->all
            ->when($this->status !== '', fn ($c) => $c->where('status', $this->status))
            ->when($this->clientFilter === 'none', fn ($c) => $c->whereNull('client_id'))
            ->when(ctype_digit($this->clientFilter), fn ($c) => $c->where('client_id', (int) $this->clientFilter))
            ->when($q !== '', fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower($s->title.' '.$s->body.' '.$s->client?->displayName()), $q)))
            ->values();
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    /** A new script opens straight in the editor. */
    public function create(): void
    {
        $script = Script::create(['title' => __('Untitled script'), 'type' => 'reel', 'status' => 'draft', 'user_id' => auth()->id(), 'client_id' => ctype_digit($this->clientFilter) ? (int) $this->clientFilter : null]);
        $this->redirectRoute('scripts.edit', $script, navigate: true);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $all = $this->all;
    $scripts = $this->visible;
    $counts = $all->countBy('status');
    $clientOptions = collect([['value' => '', 'label' => __('All clients'), 'icon' => 'building-office-2'], ['value' => 'none', 'label' => __('No client'), 'icon' => 'home']])
        ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Scripts') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Write scripts for reels, videos, ads and podcasts, and keep them with the client.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="new-script">{{ __('New script') }}</flux:button>
    </header>

    {{-- Status filter --}}
    <nav class="flex flex-wrap gap-2">
        @foreach (['' => ['label' => __('All'), 'dot' => 'bg-brand-500']] + Script::STATUSES as $key => $st)
            @php $on = $status === $key; @endphp
            <button type="button" wire:click="$set('status', '{{ $key }}')" @class([
                'inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-sm font-medium ring-1 transition',
                'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900 dark:ring-white' => $on,
                'bg-white text-zinc-700 ring-zinc-200 hover:ring-zinc-300 dark:bg-zinc-950 dark:text-zinc-200 dark:ring-white/10' => ! $on,
            ])>
                <span class="{{ $st['dot'] }} size-2 rounded-full"></span>{{ __($st['label']) }}
                <span @class(['text-xs tabular-nums', 'opacity-70' => $on, 'text-zinc-400' => ! $on])>{{ $key === '' ? $all->count() : ($counts[$key] ?? 0) }}</span>
            </button>
        @endforeach
    </nav>

    <div class="flex flex-wrap items-center gap-2">
        <div class="min-w-56 flex-1"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search scripts')" /></div>
        <x-rich-select model="clientFilter" live searchable :options="$clientOptions" class="w-60" />
    </div>

    @if ($scripts->isEmpty())
        <section class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
            <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.document-text class="size-8" /></span>
            <div>
                <p class="font-semibold text-zinc-900 dark:text-white">{{ $all->isEmpty() ? __('No scripts yet') : __('Nothing matches') }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ $all->isEmpty() ? __('Write the first one: hook, story and call to action.') : __('Try another filter or search.') }}</p>
            </div>
            @if ($all->isEmpty())<flux:button variant="primary" icon="plus" wire:click="create">{{ __('New script') }}</flux:button>@endif
        </section>
    @else
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($scripts as $s)
                @php $type = Script::TYPES[$s->type] ?? Script::TYPES['other']; $st = Script::STATUSES[$s->status] ?? Script::STATUSES['draft']; @endphp
                <a href="{{ route('scripts.edit', $s) }}" wire:navigate wire:key="script-{{ $s->id }}" class="group {{ $panel }} flex flex-col p-5 transition hover:-translate-y-0.5 hover:shadow-lg" data-test="script-{{ $s->id }}">
                    <div class="flex items-center gap-2">
                        <span class="flex size-9 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon :icon="$type['icon']" variant="mini" class="size-4.5" /></span>
                        <span class="text-xs font-medium text-zinc-500">{{ __($type['label']) }}</span>
                        <span class="{{ $st['tone'] }} ms-auto rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ __($st['label']) }}</span>
                    </div>
                    <h3 class="mt-4 line-clamp-2 text-base leading-snug font-semibold text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $s->title }}</h3>
                    <p class="mt-1.5 line-clamp-3 min-h-[3.75rem] text-sm leading-relaxed text-zinc-500">{{ Str::limit(trim((string) $s->body), 180) ?: __('Nothing written yet.') }}</p>
                    <div class="mt-4 flex items-center gap-3 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-white/5">
                        <span class="flex min-w-0 items-center gap-1.5 truncate"><flux:icon.building-office-2 variant="micro" class="size-3.5 shrink-0" />{{ $s->client?->displayName() ?? __('No client') }}</span>
                        <span class="ms-auto flex shrink-0 items-center gap-1 tabular-nums"><flux:icon.clock variant="micro" class="size-3.5" />{{ Script::speakingTime($s->body) }}</span>
                        <span class="shrink-0">{{ $s->updated_at->diffForHumans(short: true) }}</span>
                    </div>
                </a>
            @endforeach
        </section>
    @endif
</div>
