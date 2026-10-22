<?php

use App\Models\Client;
use App\Models\Script;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Scripts')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    /** New script popup: who it is for. */
    public string $newFor = 'client';

    public string $newClient = '';

    public string $newTitle = '';

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
            ->when($q !== '', fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower($s->title.' '.$s->body.' '.$s->client?->displayName()), $q)))
            ->values();
    }

    /**
     * One box per client, then "Other scripts". Inside a box, today's and upcoming shoots come
     * first (soonest first); boxes with the soonest shoot come first too.
     */
    #[Computed]
    public function boxes()
    {
        return $this->visible->groupBy(fn ($s) => $s->client_id ?? 0)
            ->map(fn ($group) => $group->sortBy(fn ($s) => $s->sortKey())->values())
            ->sortBy(fn ($group, $clientId) => [$clientId === 0 ? 1 : 0, ...$group->first()->sortKey()])
            ->map(fn ($group, $clientId) => ['client' => $clientId ? $group->first()->client : null, 'scripts' => $group]);
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    public function openNew(): void
    {
        $this->reset('newClient', 'newTitle');
        $this->newFor = 'client';
        $this->resetErrorBag();
        Flux::modal('new-script')->show();
    }

    public function create(): void
    {
        $data = $this->validate([
            'newFor' => ['required', Rule::in(['client', 'other'])],
            'newClient' => [$this->newFor === 'client' ? 'required' : 'nullable', Rule::exists('clients', 'id')],
            'newTitle' => ['nullable', 'string', 'max:200'],
        ], ['newClient.required' => __('Choose the client this script is for.')]);

        $this->start($data['newFor'] === 'client' ? (int) $data['newClient'] : null, $data['newTitle']);
    }

    /** "+ Add script" inside a box: straight to the editor. */
    public function addTo(int $clientId = 0): void
    {
        abort_if($clientId && ! Client::whereKey($clientId)->exists(), 404);
        $this->start($clientId ?: null);
    }

    private function start(?int $clientId, ?string $title = null): void
    {
        $script = Script::create([
            'title' => trim((string) $title) ?: __('Untitled script'),
            'client_id' => $clientId, 'type' => 'reel', 'status' => 'draft', 'user_id' => auth()->id(),
        ]);
        $this->redirectRoute('scripts.edit', $script, navigate: true);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $all = $this->all;
    $boxes = $this->boxes;
    $counts = $all->countBy('status');
    $filtered = $search !== '' || $status !== '';
    $clientOptions = $this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->company ? $c->name : $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']);
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Scripts') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Scripts for reels, videos, ads and podcasts, kept with each client.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openNew" class="shadow-lg shadow-brand-500/25" data-test="new-script">{{ __('New script') }}</flux:button>
    </header>

    <div class="flex flex-wrap items-center gap-2">
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
        <div class="ms-auto w-full sm:w-72"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search scripts')" /></div>
    </div>

    @if ($boxes->isEmpty())
        <section class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
            <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.document-text class="size-8" /></span>
            <div>
                <p class="font-semibold text-zinc-900 dark:text-white">{{ $filtered ? __('Nothing matches') : __('No scripts yet') }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ $filtered ? __('Try another filter or search.') : __('Start one for a client, or for your own content.') }}</p>
            </div>
            @unless ($filtered)<flux:button variant="primary" icon="plus" wire:click="openNew">{{ __('New script') }}</flux:button>@endunless
        </section>
    @else
        <section class="grid items-start gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($boxes as $key => $box)
                @php $c = $box['client']; $list = $box['scripts']; @endphp
                <article wire:key="box-{{ $key }}" x-data="{ all: false }" class="{{ $panel }} flex flex-col overflow-hidden" data-test="script-box-{{ $key }}">
                    {{-- Who --}}
                    <div class="flex items-center gap-3 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                        @if ($c?->avatarUrl())
                            <img src="{{ $c->avatarUrl() }}" alt="" class="size-11 shrink-0 rounded-xl object-cover">
                        @elseif ($c)
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-violet-600 text-sm font-bold text-white shadow-md shadow-brand-500/20">{{ $c->initials() }}</span>
                        @else
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-300"><flux:icon.sparkles variant="mini" class="size-5" /></span>
                        @endif
                        <div class="min-w-0 flex-1">
                            @if ($c)
                                <a href="{{ route('clients.show', $c) }}" wire:navigate class="block truncate font-semibold text-zinc-900 hover:text-brand-600 dark:text-white">{{ $c->displayName() }}</a>
                            @else
                                <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ __('Other scripts') }}</p>
                            @endif
                            <p class="flex items-center gap-1.5 text-xs text-zinc-500">
                                {{ trans_choice(':count script|:count scripts', $list->count()) }}
                                @foreach ($list->countBy('status') as $st => $n)
                                    <span class="{{ Script::STATUSES[$st]['dot'] ?? 'bg-zinc-400' }} size-1.5 rounded-full" title="{{ $n }} {{ __(Script::STATUSES[$st]['label'] ?? $st) }}"></span>
                                @endforeach
                            </p>
                        </div>
                    </div>

                    {{-- Its scripts --}}
                    <ul class="flex flex-col p-2">
                        @foreach ($list as $i => $s)
                            @php $type = Script::TYPES[$s->type] ?? Script::TYPES['other']; $st = Script::STATUSES[$s->status] ?? Script::STATUSES['draft']; @endphp
                            <li wire:key="s-{{ $s->id }}" @if ($i >= 5) x-show="all" x-cloak @endif>
                                <a href="{{ route('scripts.edit', $s) }}" wire:navigate class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]" data-test="script-{{ $s->id }}">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300" title="{{ __($type['label']) }}"><flux:icon :icon="$type['icon']" variant="micro" class="size-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $s->title }}</span>
                                        <span class="flex items-center gap-1.5 text-xs text-zinc-500"><span class="{{ $st['dot'] }} size-1.5 rounded-full"></span>{{ __($st['label']) }} · {{ Script::speakingTime($s->body) }}</span>
                                    </span>
                                    @if ($shoot = $s->shootLabel())
                                        @php $soon = $s->shoot_date->gte(today()) && $s->shoot_date->lte(today()->addDays(7)); @endphp
                                        <span @class([
                                            'inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-inset',
                                            'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20' => $soon,
                                            'bg-zinc-50 text-zinc-500 ring-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10' => ! $soon,
                                        ])><flux:icon.video-camera variant="micro" class="size-3" />{{ $shoot }}</span>
                                    @else
                                        <span class="shrink-0 text-[0.6875rem] text-zinc-400">{{ $s->updated_at->diffForHumans(short: true) }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                        @if ($list->count() > 5)
                            <li class="px-3 pt-1">
                                <button type="button" @click="all = ! all" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-300">
                                    <span x-show="! all">{{ trans_choice('Show :count more|Show :count more', $list->count() - 5) }}</span><span x-show="all" x-cloak>{{ __('Show less') }}</span>
                                </button>
                            </li>
                        @endif
                    </ul>

                    <button type="button" wire:click="addTo({{ $c?->id ?? 0 }})" class="mt-auto flex items-center justify-center gap-1.5 border-t border-zinc-100 py-3 text-sm font-medium text-brand-600 transition hover:bg-brand-50/60 dark:border-white/5 dark:text-brand-300 dark:hover:bg-brand-500/10" data-test="add-to-{{ $key }}">
                        <flux:icon.plus variant="micro" class="size-4" />{{ __('Add script') }}
                    </button>
                </article>
            @endforeach
        </section>
    @endif

    {{-- New script: for a client, or something else --}}
    <flux:modal name="new-script" class="w-full max-w-md overflow-visible!">
        <form wire:submit="create" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.pencil-square variant="mini" class="size-5" /></span>
                <div>
                    <flux:heading size="lg">{{ __('New script') }}</flux:heading>
                    <flux:text>{{ __('Who is it for?') }}</flux:text>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                @foreach (['client' => ['building-office-2', __('For a client'), __('Kept in the client\'s box')], 'other' => ['sparkles', __('Other script'), __('Our own content, ideas')]] as $value => [$icon, $label, $hint])
                    <label class="cursor-pointer">
                        <input type="radio" wire:model.live="newFor" value="{{ $value }}" class="peer sr-only" data-test="for-{{ $value }}">
                        <span class="flex h-full flex-col gap-2 rounded-xl p-4 ring-1 ring-zinc-200 transition peer-checked:bg-brand-50/70 peer-checked:ring-2 peer-checked:ring-brand-500 hover:ring-zinc-300 dark:ring-white/10 dark:peer-checked:bg-brand-500/10">
                            <span class="flex size-9 items-center justify-center rounded-lg bg-white text-brand-600 shadow-sm ring-1 ring-zinc-200 dark:bg-white/5 dark:text-brand-300 dark:ring-white/10"><flux:icon :icon="$icon" variant="mini" class="size-4.5" /></span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</span>
                            <span class="text-xs text-zinc-500">{{ $hint }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($newFor === 'client')
                <x-rich-select model="newClient" searchable :label="__('Client')" :placeholder="__('Choose a client')" :options="$clientOptions" />
            @endif
            <flux:input wire:model="newTitle" :label="__('Title (optional)')" :placeholder="__('e.g. Dashain offer reel')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrow-right" data-test="start-script">{{ __('Start writing') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
