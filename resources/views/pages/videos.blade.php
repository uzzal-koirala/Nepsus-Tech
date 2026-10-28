<?php

use App\Models\Client;
use App\Models\Video;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Videos')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'client', except: '')]
    public string $clientFilter = '';

    public ?int $editingId = null;

    public string $url = '';

    public string $title = '';

    public string $clientId = '';

    public string $videoStatus = 'raw';

    public string $shotOn = '';

    public string $editBy = '';

    public string $notes = '';

    public ?int $deletingId = null;

    #[Computed]
    public function all()
    {
        return Video::with('client')->latest()->get();
    }

    #[Computed]
    public function visible()
    {
        $q = mb_strtolower(trim($this->search));

        return $this->all
            ->when($this->status !== '', fn ($c) => $c->where('status', $this->status))
            ->when($this->clientFilter !== '', fn ($c) => $c->where('client_id', $this->clientFilter === 'none' ? null : (int) $this->clientFilter))
            ->when($q !== '', fn ($c) => $c->filter(fn ($v) => str_contains(mb_strtolower($v->title.' '.$v->notes.' '.$v->client?->displayName()), $q)))
            ->values();
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    public function openNew(): void
    {
        $this->reset('editingId', 'url', 'title', 'notes', 'shotOn', 'editBy');
        $this->clientId = $this->clientFilter !== '' && $this->clientFilter !== 'none' ? $this->clientFilter : '';
        $this->videoStatus = 'raw';
        $this->resetErrorBag();
        Flux::modal('video-form')->show();
    }

    public function edit(int $id): void
    {
        $video = Video::findOrFail($id);
        $this->editingId = $video->id;
        $this->url = $video->url;
        $this->title = $video->title;
        $this->clientId = (string) $video->client_id;
        $this->videoStatus = $video->status;
        $this->shotOn = (string) $video->shot_on?->toDateString();
        $this->editBy = (string) $video->edit_by?->toDateString();
        $this->notes = (string) $video->notes;
        $this->resetErrorBag();
        Flux::modal('video-form')->show();
    }

    public function save(): void
    {
        $this->url = trim($this->url);
        $data = $this->validate([
            'url' => ['required', 'url:http,https', 'max:2048'],
            'title' => ['nullable', 'string', 'max:200'],
            'clientId' => ['nullable', Rule::exists('clients', 'id')],
            'videoStatus' => ['required', Rule::in(array_keys(Video::STATUSES))],
            'shotOn' => ['nullable', 'date'],
            'editBy' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['url.url' => __('Paste the full link, starting with https://')], ['url' => __('link')]);

        $values = [
            'url' => $data['url'],
            'title' => trim((string) $data['title']) ?: __('Footage :date', ['date' => ($data['shotOn'] ? \Illuminate\Support\Carbon::parse($data['shotOn']) : today())->toNepali()]),
            'client_id' => $data['clientId'] ?: null,
            'status' => $data['videoStatus'],
            'shot_on' => $data['shotOn'] ?: null,
            'edit_by' => $data['editBy'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];
        $this->editingId
            ? Video::findOrFail($this->editingId)->update($values)
            : Video::create($values + ['user_id' => auth()->id()]);

        Flux::modal('video-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Video updated.') : __('Video added.'));
        unset($this->all);
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(isset(Video::STATUSES[$status]), 422);
        Video::findOrFail($id)->update(['status' => $status]);
        unset($this->all);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('delete-video')->show();
    }

    public function delete(): void
    {
        Video::whereKey($this->deletingId)->delete();
        $this->deletingId = null;
        Flux::modal('delete-video')->close();
        Flux::toast(text: __('Video removed.'));
        unset($this->all);
    }
}; ?>

@php
    $all = $this->all;
    $videos = $this->visible;
    $counts = $all->countBy('status');
    $filtered = $search !== '' || $status !== '' || $clientFilter !== '';
    $clientOptions = $this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->company ? $c->name : $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']);
    $filterOptions = collect([['value' => '', 'label' => __('All clients'), 'icon' => 'squares-2x2'], ['value' => 'none', 'label' => __('No client'), 'hint' => __('Our own content'), 'icon' => 'sparkles']])->concat($clientOptions);
    $statusOptions = collect(Video::STATUSES)->map(fn ($s, $k) => ['value' => $k, 'label' => __($s['label']), 'icon' => $s['icon'], 'tile' => $s['tone']])->values();
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Videos') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Raw footage links from Google Drive, ready for the editors.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openNew" class="shadow-lg shadow-brand-500/25" data-test="add-video">{{ __('Add video') }}</flux:button>
    </header>

    <div class="flex flex-wrap items-center gap-2">
        <nav class="flex flex-wrap gap-2">
            @foreach (['' => ['short' => __('All'), 'dot' => 'bg-brand-500']] + Video::STATUSES as $key => $st)
                @php $on = $status === $key; @endphp
                <button type="button" wire:click="$set('status', '{{ $key }}')" @class([
                    'inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-sm font-medium ring-1 transition',
                    'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900 dark:ring-white' => $on,
                    'bg-white text-zinc-700 ring-zinc-200 hover:ring-zinc-300 dark:bg-zinc-950 dark:text-zinc-200 dark:ring-white/10' => ! $on,
                ])>
                    <span class="{{ $st['dot'] }} size-2 rounded-full"></span>{{ __($st['short']) }}
                    <span @class(['text-xs tabular-nums', 'opacity-70' => $on, 'text-zinc-400' => ! $on])>{{ $key === '' ? $all->count() : ($counts[$key] ?? 0) }}</span>
                </button>
            @endforeach
        </nav>
        <div class="ms-auto flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
            <x-rich-select model="clientFilter" live searchable :options="$filterOptions" class="sm:w-60" />
            <div class="sm:w-64"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search videos')" /></div>
        </div>
    </div>

    @if ($videos->isEmpty())
        <section class="flex flex-col items-center gap-4 rounded-xl bg-white px-6 py-16 text-center ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
            <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.video-camera class="size-8" /></span>
            <div>
                <p class="font-semibold text-zinc-900 dark:text-white">{{ $filtered ? __('Nothing matches') : __('No videos yet') }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ $filtered ? __('Try another filter or search.') : __('Upload the raw footage to Google Drive, then paste its link here.') }}</p>
            </div>
            @unless ($filtered)<flux:button variant="primary" icon="plus" wire:click="openNew">{{ __('Add video') }}</flux:button>@endunless
        </section>
    @else
        <section class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            @foreach ($videos as $v)
                @php $st = Video::STATUSES[$v->status] ?? Video::STATUSES['raw']; @endphp
                <article wire:key="v-{{ $v->id }}" x-data="{ copied: false }" class="group flex flex-col overflow-hidden rounded-xl bg-white shadow-[0_1px_2px_rgb(15_23_42/0.06)] ring-1 ring-zinc-200/70 transition duration-200 hover:-translate-y-0.5 hover:shadow-[0_12px_32px_-12px_rgb(15_23_42/0.22)] dark:bg-zinc-950 dark:ring-white/10" data-test="video-{{ $v->id }}">
                    {{-- Preview --}}
                    <a href="{{ $v->url }}" target="_blank" rel="noopener" class="relative flex h-32 items-center justify-center overflow-hidden bg-linear-to-br from-zinc-800 via-zinc-900 to-brand-900">
                        <span class="pointer-events-none absolute -top-10 -right-10 size-36 rounded-full bg-brand-500/20 blur-2xl"></span>
                        <span class="pointer-events-none absolute -bottom-12 -left-8 size-32 rounded-full bg-violet-500/20 blur-2xl"></span>
                        <span class="relative flex size-12 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/25 backdrop-blur transition group-hover:scale-110 group-hover:bg-white group-hover:text-zinc-900">
                            @if ($v->isFolder())<flux:icon.folder-open variant="mini" class="size-5" />@else<flux:icon.play variant="mini" class="ms-0.5 size-5" />@endif
                        </span>
                        <span class="absolute top-3 left-3 inline-flex items-center gap-1 rounded-md bg-black/40 px-2 py-0.5 text-[0.6875rem] font-medium text-white backdrop-blur">
                            <flux:icon.cloud variant="micro" class="size-3.5" />{{ $v->source() }}{{ $v->isFolder() ? ' · '.__('Folder') : '' }}
                        </span>
                        <span class="{{ $st['tone'] }} absolute top-3 right-3 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-inset">
                            <flux:icon :icon="$st['icon']" variant="micro" class="size-3" />{{ __($st['short']) }}
                        </span>
                        @if ($due = $v->deadline())
                            <span class="{{ $due['tone'] }} absolute bottom-3 left-3 inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-bold ring-1 ring-inset" data-test="edit-by-{{ $v->id }}">
                                <flux:icon :icon="$due['icon']" variant="micro" class="size-3.5" />{{ $due['label'] }}
                            </span>
                        @endif
                    </a>

                    {{-- Details --}}
                    <div class="flex flex-1 flex-col gap-1 px-4 pt-3.5">
                        <a href="{{ $v->url }}" target="_blank" rel="noopener" class="line-clamp-1 font-semibold text-zinc-900 hover:text-brand-600 dark:text-white">{{ $v->title }}</a>
                        <p class="flex min-w-0 items-center gap-1.5 text-xs text-zinc-500">
                            @if ($v->client)
                                <a href="{{ route('clients.show', $v->client) }}" wire:navigate class="truncate hover:text-brand-600">{{ $v->client->displayName() }}</a>
                            @else
                                <span>{{ __('No client') }}</span>
                            @endif
                            @if ($v->shot_on)<span class="text-zinc-300 dark:text-zinc-600">·</span><span class="shrink-0">{{ __('Shot :date', ['date' => $v->shot_on->toNepali(false)]) }}</span>@endif
                        </p>
                        @if ($v->notes)<p class="mt-1 line-clamp-2 text-xs leading-relaxed text-zinc-500">{{ $v->notes }}</p>@endif
                    </div>

                    {{-- Actions --}}
                    <div class="mt-3 flex items-center gap-1.5 px-3 pb-3">
                        <a href="{{ $v->url }}" target="_blank" rel="noopener" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-brand-600 dark:bg-white dark:text-zinc-900" data-test="open-{{ $v->id }}">
                            <flux:icon.arrow-top-right-on-square variant="micro" class="size-3.5" />{{ __('Open') }}
                        </a>
                        <button type="button" @click="navigator.clipboard.writeText(@js($v->url)); copied = true; setTimeout(() => copied = false, 1500)" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-zinc-100 px-3 py-2 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-200 dark:bg-white/5 dark:text-zinc-200">
                            <flux:icon.link variant="micro" class="size-3.5" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))"></span>
                        </button>
                        <flux:dropdown align="end">
                            <button type="button" class="flex size-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:hover:bg-white/5" aria-label="{{ __('More') }}"><flux:icon.ellipsis-vertical variant="mini" class="size-4" /></button>
                            <flux:menu>
                                @foreach (Video::STATUSES as $key => $s)
                                    @continue($key === $v->status)
                                    <flux:menu.item :icon="$s['icon']" wire:click="setStatus({{ $v->id }}, '{{ $key }}')">{{ __('Mark as :status', ['status' => mb_strtolower(__($s['short']))]) }}</flux:menu.item>
                                @endforeach
                                <flux:menu.separator />
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $v->id }})">{{ __('Edit') }}</flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $v->id }})">{{ __('Delete') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    {{-- Add / edit --}}
    <flux:modal name="video-form" class="w-full max-w-lg overflow-visible!">
        <form wire:submit="save" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.video-camera variant="mini" class="size-5" /></span>
                <div>
                    <flux:heading size="lg">{{ $editingId ? __('Edit video') : __('Add video') }}</flux:heading>
                    <flux:text>{{ __('Paste the Google Drive link to the footage.') }}</flux:text>
                </div>
            </div>

            <flux:input wire:model="url" icon="link" :label="__('Link')" placeholder="https://drive.google.com/…" required autofocus data-test="video-url" />
            <flux:input wire:model="title" :label="__('Title')" :placeholder="__('e.g. Dashain reel, kitchen shots')" :description:trailing="__('Leave empty to name it by date.')" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-rich-select model="clientId" searchable :label="__('Client')" :placeholder="__('No client')" :options="collect([['value' => '', 'label' => __('No client'), 'hint' => __('Our own content'), 'icon' => 'sparkles']])->concat($clientOptions)" />
                <x-rich-select model="videoStatus" :label="__('Status')" :options="$statusOptions" />
            </div>
            <div class="grid grid-cols-2 gap-3 sm:gap-4">
                <x-nepali-date wire:model="shotOn" :label="__('Shot on')" :placeholder="__('Shoot day')" />
                <x-nepali-date wire:model="editBy" :label="__('Edit by')" :placeholder="__('Finish by')" align="end" data-test="edit-by" />
            </div>
            <flux:textarea wire:model="notes" :label="__('Notes for the editor')" rows="3" :placeholder="__('Which clips to use, music, length, deadline…')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-video">{{ $editingId ? __('Save changes') : __('Add video') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-video" class="w-full max-w-sm">
        <div class="flex flex-col items-center gap-4 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.trash class="size-7" /></span>
            <div>
                <flux:heading size="lg">{{ __('Remove this video?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Only the link is removed. The footage stays in Google Drive.') }}</flux:text>
            </div>
            <div class="flex w-full gap-2">
                <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" class="flex-1">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
