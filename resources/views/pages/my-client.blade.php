<?php

use App\Models\Client;
use App\Models\Script;
use App\Models\Task;
use App\Models\Video;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/** A client for employee logins: contact details and the work. No prices, payments or agreements. */
new #[Title('Client')] class extends Component {
    public Client $client;

    #[Computed]
    public function tasks()
    {
        return $this->client->tasks()->with('assignees')->whereNull('completed_at')
            ->orderByRaw('due_date is null')->orderBy('due_date')->get();
    }

    #[Computed]
    public function scripts()
    {
        return $this->client->scripts()->latest('updated_at')->get()->sortBy(fn ($s) => $s->sortKey())->values();
    }

    #[Computed]
    public function videos()
    {
        return $this->client->videos()->latest()->get();
    }

    public function toggleDone(int $id): void
    {
        $t = $this->client->tasks()->whereHas('assignees', fn ($q) => $q->whereKey(auth()->user()->employee?->id ?? 0))->findOrFail($id);
        $t->update(['completed_at' => now(), 'status' => 'done']);
        unset($this->tasks);
    }

    public function newScript(): void
    {
        $script = Script::create(['title' => __('Untitled script'), 'client_id' => $this->client->id, 'type' => 'reel', 'status' => 'draft', 'user_id' => auth()->id()]);
        $this->redirectRoute('scripts.edit', $script, navigate: true);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $c = $client;
    $me = auth()->user()->employee?->id;
    $tasks = $this->tasks;
    $scripts = $this->scripts;
    $videos = $this->videos;
    $types = Task::types();
    $service = Client::SERVICE_TYPES[$c->service_type] ?? null;
    $socials = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'google' => 'Google'];
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <a href="{{ route('my.clients') }}" wire:navigate class="inline-flex w-fit items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium text-zinc-600 transition hover:bg-white hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5"><flux:icon.arrow-left variant="micro" class="size-4" />{{ __('All clients') }}</a>

    {{-- Who --}}
    <section class="{{ $panel }} overflow-hidden">
        <div class="relative h-24 bg-linear-to-br from-brand-500 via-brand-600 to-violet-600">
            <span class="pointer-events-none absolute -top-12 -right-8 size-44 rounded-full bg-white/10"></span>
        </div>
        <div class="flex flex-wrap items-end gap-4 px-6 pb-5">
            @if ($c->avatarUrl())
                <img src="{{ $c->avatarUrl() }}" alt="" class="-mt-10 size-20 shrink-0 rounded-2xl object-cover shadow-lg ring-4 ring-white dark:ring-zinc-950">
            @else
                <span class="-mt-10 flex size-20 shrink-0 items-center justify-center rounded-2xl bg-white text-2xl font-bold text-brand-600 shadow-lg ring-4 ring-white dark:bg-zinc-900 dark:ring-zinc-950">{{ $c->initials() }}</span>
            @endif
            <div class="min-w-0 flex-1 pt-3">
                <h1 class="truncate text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $c->displayName() }}</h1>
                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-500">
                    @if ($service)<span class="inline-flex items-center gap-1"><flux:icon :icon="$service['icon']" variant="micro" class="size-4" />{{ __($service['label']) }}</span>@endif
                    <span class="inline-flex items-center gap-1"><flux:icon.user variant="micro" class="size-4" />{{ $c->contactName() }}</span>
                    @if ($c->phone)<a href="tel:{{ $c->phone }}" class="inline-flex items-center gap-1 hover:text-brand-600"><flux:icon.phone variant="micro" class="size-4" />{{ $c->phone }}</a>@endif
                    @if ($c->email)<a href="mailto:{{ $c->email }}" class="inline-flex items-center gap-1 hover:text-brand-600"><flux:icon.envelope variant="micro" class="size-4" />{{ $c->email }}</a>@endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @foreach ($c->socialLinks() as $network => $link)
                    <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="flex size-9 items-center justify-center rounded-lg bg-zinc-50 text-zinc-500 ring-1 ring-zinc-200/70 transition hover:text-brand-600 dark:bg-white/5 dark:ring-white/10" title="{{ $socials[$network] ?? $network }}">
                        <x-social-icon :name="$network" class="size-4" /><span class="sr-only">{{ $socials[$network] ?? $network }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-2">
        {{-- Tasks --}}
        <section class="{{ $panel }} overflow-hidden" data-test="client-tasks">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.check-circle variant="micro" class="size-4" /></span>{{ __('Open tasks') }} <span class="text-sm font-normal text-zinc-400">{{ $tasks->count() }}</span></h2>
            </div>
            <ul class="px-2 pb-3">
                @forelse ($tasks as $t)
                    @php $mine = $t->assignees->contains('id', $me); $type = $types[$t->type] ?? Task::unknownType($t->type); @endphp
                    <li wire:key="t-{{ $t->id }}" @class(['group flex items-center gap-3 rounded-lg px-3 py-2.5', 'bg-brand-50/50 dark:bg-brand-500/5' => $mine])>
                        @if ($mine)
                            <button type="button" wire:click="toggleDone({{ $t->id }})" class="flex size-5 shrink-0 items-center justify-center rounded-md ring-1 ring-brand-300 transition hover:bg-emerald-50 hover:ring-emerald-500 dark:ring-brand-500/40" aria-label="{{ __('Mark as done') }}"></button>
                        @else
                            <span class="flex size-5 shrink-0 items-center justify-center text-zinc-300"><flux:icon :icon="$type['icon']" variant="micro" class="size-4" /></span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $t->title }}</span>
                            <span class="block truncate text-xs text-zinc-500">{{ $type['label'] }} · {{ $t->assignees->pluck('name')->join(', ') ?: __('Nobody yet') }}</span>
                        </span>
                        @if ($mine)<span class="shrink-0 rounded-full bg-brand-500 px-2 py-0.5 text-[0.625rem] font-semibold text-white">{{ __('You') }}</span>@endif
                        @if ($t->due_date)
                            <span @class(['shrink-0 text-xs tabular-nums', 'font-semibold text-rose-600' => $t->isOverdue(), 'text-zinc-500' => ! $t->isOverdue()])>{{ $t->due_date->isToday() ? __('Today') : $t->due_date->toNepali(false) }}</span>
                        @endif
                    </li>
                @empty
                    <li class="px-3 py-8 text-center text-xs text-zinc-500">{{ __('No open tasks for this client.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Scripts --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300"><flux:icon.pencil-square variant="micro" class="size-4" /></span>{{ __('Scripts') }} <span class="text-sm font-normal text-zinc-400">{{ $scripts->count() }}</span></h2>
                <flux:button size="sm" icon="plus" wire:click="newScript" data-test="new-script">{{ __('New script') }}</flux:button>
            </div>
            <ul class="px-2 pb-3">
                @forelse ($scripts as $s)
                    @php $st = Script::STATUSES[$s->status] ?? Script::STATUSES['draft']; $type = Script::TYPES[$s->type] ?? Script::TYPES['other']; @endphp
                    <li wire:key="s-{{ $s->id }}">
                        <a href="{{ route('scripts.edit', $s) }}" wire:navigate class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span class="{{ $st['dot'] }} h-8 w-1 shrink-0 rounded-full"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $s->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ __($type['label']) }} · {{ Script::speakingTime($s->body) }}@if ($shoot = $s->shootLabel()) · {{ $shoot }}@endif</span>
                            </span>
                            <span class="shrink-0 text-xs text-zinc-500">{{ __($st['label']) }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-3 py-8 text-center text-xs text-zinc-500">{{ __('No scripts yet.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Footage --}}
    <section class="{{ $panel }} overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5 pb-3">
            <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300"><flux:icon.video-camera variant="micro" class="size-4" /></span>{{ __('Videos') }} <span class="text-sm font-normal text-zinc-400">{{ $videos->count() }}</span></h2>
            <flux:button size="sm" icon="plus" :href="route('videos.index', ['client' => $c->id])" wire:navigate>{{ __('Add video') }}</flux:button>
        </div>
        <div class="grid gap-3 px-5 pb-5 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($videos as $v)
                @php $st = Video::STATUSES[$v->status] ?? Video::STATUSES['raw']; @endphp
                <a href="{{ $v->url }}" target="_blank" rel="noopener" wire:key="v-{{ $v->id }}" class="group flex items-center gap-3 rounded-xl bg-zinc-50 p-3 transition hover:bg-zinc-100 dark:bg-white/[0.03] dark:hover:bg-white/5">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-zinc-800 to-brand-900 text-white"><flux:icon :icon="$v->isFolder() ? 'folder-open' : 'play'" variant="micro" class="size-4" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $v->title }}</span>
                        <span class="block truncate text-xs text-zinc-500">{{ $v->source() }}{{ $v->shot_on ? ' · '.$v->shot_on->toNepali(false) : '' }}</span>
                    </span>
                    <span class="{{ $st['tone'] }} shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset">{{ __($st['short']) }}</span>
                </a>
            @empty
                <p class="col-span-full py-6 text-center text-xs text-zinc-500">{{ __('No footage saved for this client yet.') }}</p>
            @endforelse
        </div>
    </section>
</div>
