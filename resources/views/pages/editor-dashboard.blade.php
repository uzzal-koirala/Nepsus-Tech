<?php

use App\Models\Script;
use App\Models\Task;
use App\Models\Video;
use App\Support\NepaliDate;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** The Dashboard for Video Editors: footage to cut, their edit tasks, shoots on the way and what they finished. */
new class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()->canEditVideos(), 403);
    }

    #[Computed]
    public function me()
    {
        return auth()->user()->employee;
    }

    private function mine()
    {
        return Task::whereHas('assignees', fn ($q) => $q->whereKey($this->me?->id ?? 0));
    }

    /** Footage waiting or being edited: the nearest edit-by date first, then what is in progress, then the oldest. */
    #[Computed]
    public function queue()
    {
        return Video::with('client')->where('kind', 'raw')->whereIn('status', ['raw', 'editing'])
            ->orderByRaw('edit_by is null')->orderBy('edit_by')
            ->orderByRaw("case status when 'editing' then 0 else 1 end")->oldest()->get();
    }

    #[Computed]
    public function editedThisWeek(): int
    {
        return Video::where('kind', 'raw')->where('status', 'done')->where('updated_at', '>=', today()->startOfWeek(\Carbon\CarbonInterface::SUNDAY))->count();
    }

    #[Computed]
    public function recentlyEdited()
    {
        return Video::with('client')->where('kind', 'raw')->where('status', 'done')->latest('updated_at')->limit(5)->get();
    }

    /** My open tasks up to a week ahead, and undated ones. */
    #[Computed]
    public function tasks()
    {
        return $this->mine()->with('client')->whereNull('completed_at')
            ->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', today()->addDays(7)))
            ->orderByRaw('due_date is null')->orderBy('due_date')->get();
    }

    /** Shoots in the next two weeks: footage on its way. */
    #[Computed]
    public function shoots()
    {
        return Script::with('client')->whereDate('shoot_date', '>=', today())->whereDate('shoot_date', '<=', today()->addDays(14))->orderBy('shoot_date')->limit(5)->get();
    }

    /** raw -> editing -> done, one step at a time from here. */
    public function moveVideo(int $id, string $status): void
    {
        abort_unless(auth()->user()->canEditVideos() && in_array($status, ['raw', 'editing', 'done'], true), 403);
        $video = Video::findOrFail($id);
        $video->update(['status' => $status]);
        unset($this->queue, $this->editedThisWeek, $this->recentlyEdited);
        Flux::toast(variant: 'success', text: match ($status) {
            'editing' => __('Editing “:title”.', ['title' => $video->title]),
            'done' => __('Nice, “:title” is edited.', ['title' => $video->title]),
            default => __('“:title” is back in the queue.', ['title' => $video->title]),
        });
    }

    public function toggleDone(int $id): void
    {
        $t = $this->mine()->findOrFail($id);
        $t->update($t->completed_at ? ['completed_at' => null, 'status' => 'todo'] : ['completed_at' => now(), 'status' => 'done']);
        unset($this->tasks);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $link = 'rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10';
    $me = $this->me;
    $queue = $this->queue;
    $raw = $queue->where('status', 'raw');
    $editing = $queue->where('status', 'editing');
    $tasks = $this->tasks;
    $dueNow = $tasks->filter(fn ($t) => $t->due_date?->lte(today()));
    // Footage that must be edited today, or is late.
    $footageDue = $queue->filter(fn ($v) => $v->edit_by?->lte(today()))->count();
    $shoots = $this->shoots;
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
@endphp

<div class="stagger flex w-full flex-col gap-4 sm:gap-6" data-test="editor-dashboard">
    {{-- Hero --}}
    <section class="grain relative isolate overflow-hidden rounded-2xl bg-indigo-600 p-5 text-white sm:p-8">
        <div class="absolute inset-0 -z-10 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_right,black,transparent_70%)]"></div>
        <div class="absolute -right-16 -bottom-28 -z-10 hidden size-72 rounded-full bg-sun sm:block lg:right-10"></div>
        {{-- A film strip, for the editing room feel --}}
        <div class="absolute top-6 right-6 -z-10 hidden gap-1.5 opacity-25 lg:flex" aria-hidden="true">
            @for ($i = 0; $i < 5; $i++)<span class="h-16 w-11 rounded-md border-2 border-white/80"></span>@endfor
        </div>

        <p class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/20 backdrop-blur">
            <flux:icon.calendar variant="micro" class="size-3.5" />{{ today()->toNepali() }} · {{ now()->translatedFormat('l') }}
        </p>
        <h1 class="mt-3 text-xl font-semibold tracking-[-0.02em] sm:mt-4 sm:text-3xl">{{ $greeting }}, {{ str(auth()->user()->name)->before(' ') }}</h1>
        <p class="mt-1.5 max-w-lg text-[0.8125rem] leading-relaxed text-indigo-100 sm:mt-2 sm:text-sm">
            {{ __('Video Editor') }} ·
            @if ($queue->isEmpty())
                {{ __('No footage waiting. Enjoy the quiet.') }}
            @else
                {{ trans_choice(':count clip waiting|:count clips waiting', $raw->count()) }} · {{ trans_choice(':count in editing|:count in editing', $editing->count()) }}
            @endif
        </p>
        <div class="mt-5 grid grid-cols-2 gap-2 sm:mt-6 sm:flex sm:flex-wrap">
            <a href="{{ route('videos.index') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-3.5 py-2.5 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-50 sm:py-2" data-test="open-videos"><flux:icon.film variant="micro" class="size-4" />{{ __('All videos') }}</a>
            <a href="{{ route('my.tasks') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white/15 px-3.5 py-2.5 text-sm font-semibold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25 sm:py-2"><flux:icon.check-circle variant="micro" class="size-4" />{{ __('My tasks') }}</a>
        </div>
    </section>

    @unless ($me)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">{{ __('Your login is not linked to an employee profile yet, so no tasks show here. Ask an admin to link it.') }}</p>
    @endunless

    {{-- Stats --}}
    <section class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Waiting to edit'), 'value' => $raw->count(), 'icon' => 'inbox-arrow-down', 'tile' => 'bg-sky-500 shadow-sky-500/30', 'glow' => 'bg-sky-100/70 dark:bg-sky-500/10', 'sub' => $raw->count() ? __('Raw footage in the queue') : __('Queue is empty'), 'href' => route('videos.index', ['status' => 'raw'])],
            ['label' => __('Editing now'), 'value' => $editing->count(), 'icon' => 'scissors', 'tile' => 'bg-amber-500 shadow-amber-500/30', 'glow' => 'bg-amber-100/70 dark:bg-amber-500/10', 'sub' => __('Started, not finished'), 'href' => route('videos.index', ['status' => 'editing'])],
            ['label' => __('Edits due'), 'value' => $dueNow->count() + $footageDue, 'icon' => 'clock', 'tile' => 'bg-rose-500 shadow-rose-500/30', 'glow' => 'bg-rose-100/70 dark:bg-rose-500/10', 'sub' => $dueNow->count() + $footageDue ? __('Footage and tasks, today or late') : __('Nothing due today'), 'href' => route('videos.index')],
            ['label' => __('Edited this week'), 'value' => $this->editedThisWeek, 'icon' => 'check-badge', 'tile' => 'bg-emerald-500 shadow-emerald-500/30', 'glow' => 'bg-emerald-100/70 dark:bg-emerald-500/10', 'sub' => __('Since Sunday'), 'href' => route('videos.index', ['status' => 'done'])],
        ] as $card)
            <a href="{{ $card['href'] }}" wire:navigate class="{{ $panel }} group relative flex min-w-0 flex-col gap-3 overflow-hidden p-4 transition hover:-translate-y-0.5 hover:shadow-lg sm:gap-4 sm:p-5">
                <div class="{{ $card['glow'] }} absolute -top-12 -right-12 size-32 rounded-full"></div>
                <div class="relative flex items-center gap-2 sm:gap-3">
                    <span class="{{ $card['tile'] }} flex size-8 shrink-0 items-center justify-center rounded-lg text-white shadow-md sm:size-9"><flux:icon :icon="$card['icon']" variant="mini" class="size-4 sm:size-4.5" /></span>
                    <p class="min-w-0 flex-1 truncate text-[0.8125rem] font-medium text-zinc-600 sm:text-sm dark:text-zinc-300">{{ $card['label'] }}</p>
                    <flux:icon.arrow-up-right variant="micro" class="hidden size-4 text-zinc-300 transition group-hover:text-zinc-500 sm:block" />
                </div>
                <p class="relative text-2xl leading-none font-bold tracking-tight text-zinc-900 tabular-nums sm:text-[1.75rem] dark:text-white">{{ $card['value'] }}</p>
                <p class="relative line-clamp-2 text-[0.6875rem] text-zinc-500 sm:text-xs">{{ $card['sub'] }}</p>
            </a>
        @endforeach
    </section>

    <div class="grid gap-4 sm:gap-6 lg:grid-cols-3">
        {{-- Footage queue --}}
        <section class="{{ $panel }} min-w-0 overflow-hidden lg:col-span-2" data-test="footage-queue">
            <div class="flex items-center gap-3 px-4 py-4 sm:px-5">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-md shadow-indigo-600/25"><flux:icon.film variant="mini" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Footage queue') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Most urgent first, by the date it must be edited') }}</p>
                </div>
                <a href="{{ route('videos.index') }}" wire:navigate class="{{ $link }}">{{ __('All') }}</a>
            </div>
            @forelse ($queue as $v)
                @php $isEditing = $v->status === 'editing'; $st = Video::STATUSES[$v->status]; @endphp
                @php $lateEdit = $v->deadline()['late'] ?? false; @endphp
                <div wire:key="v-{{ $v->id }}" @class(['flex flex-wrap items-center gap-x-3 gap-y-2.5 border-t border-zinc-100 px-4 py-3 sm:px-5 dark:border-white/5', 'bg-rose-50/50 dark:bg-rose-500/[0.05]' => $lateEdit, 'bg-amber-50/40 dark:bg-amber-500/[0.04]' => $isEditing && ! $lateEdit])>
                    <a href="{{ $v->url }}" target="_blank" rel="noopener" class="group flex min-w-0 flex-1 basis-56 items-center gap-3">
                        <span @class(['relative flex size-11 shrink-0 items-center justify-center rounded-xl text-white transition group-hover:scale-105', 'bg-linear-to-br from-amber-500 to-orange-600' => $isEditing, 'bg-linear-to-br from-zinc-800 to-indigo-900' => ! $isEditing])>
                            <flux:icon :icon="$v->isFolder() ? 'folder-open' : 'play'" variant="mini" class="size-5" />
                            @if ($isEditing)<span class="absolute -top-1 -right-1 size-3 animate-pulse rounded-full bg-amber-400 ring-2 ring-white dark:ring-zinc-950"></span>@endif
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-zinc-900 group-hover:text-indigo-600 dark:text-white">{{ $v->title }}</span>
                            <span class="flex min-w-0 items-center gap-1.5 truncate text-xs text-zinc-500">
                                {{ $v->client?->displayName() ?? __('No client') }}
                                @if ($v->shot_on) · {{ __('shot :date', ['date' => $v->shot_on->toNepali(false)]) }}@endif
                                · {{ $v->source() }}
                            </span>
                        </span>
                    </a>
                    <div class="flex shrink-0 flex-wrap items-center gap-2 max-sm:w-full max-sm:ps-14">
                        @if ($due = $v->deadline())
                            <span class="{{ $due['tone'] }} inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-bold ring-1 ring-inset" data-test="queue-edit-by-{{ $v->id }}"><flux:icon :icon="$due['icon']" variant="micro" class="size-3.5" />{{ $due['label'] }}</span>
                        @endif
                        <span class="{{ $st['tone'] }} inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-inset"><span class="{{ $st['dot'] }} size-1.5 rounded-full"></span>{{ __($st['short']) }}</span>
                        @if ($isEditing)
                            <flux:button size="sm" variant="primary" icon="check" wire:click="moveVideo({{ $v->id }}, 'done')" data-test="edited-{{ $v->id }}">{{ __('Mark edited') }}</flux:button>
                        @else
                            <flux:button size="sm" icon="scissors" wire:click="moveVideo({{ $v->id }}, 'editing')" data-test="start-{{ $v->id }}">{{ __('Start editing') }}</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge class="size-6" /></span>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Queue is empty') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('New footage shows up here when the team adds it.') }}</p>
                </div>
            @endforelse
        </section>

        {{-- My tasks --}}
        <aside class="order-first flex min-w-0 flex-col gap-4 sm:gap-6 lg:order-none">
            <section class="{{ $panel }} overflow-hidden" data-test="editor-tasks">
                <div class="flex items-start gap-3 px-4 pt-4 sm:px-5 sm:pt-5">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/25"><flux:icon.clipboard-document-check variant="mini" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('My tasks') }}</h2>
                        <p class="text-xs text-zinc-500">{{ $tasks->count() ? trans_choice(':count open this week|:count open this week', $tasks->count()) : __('Nothing open') }}</p>
                    </div>
                    <a href="{{ route('my.tasks') }}" wire:navigate class="{{ $link }}">{{ __('All') }}</a>
                </div>
                <div class="flex flex-col p-2 pt-3">
                    @forelse ($tasks->take(6) as $t)
                        @php
                            $days = $t->due_date ? (int) today()->diffInDays($t->due_date, false) : null;
                            $late = $days !== null && $days < 0;
                            $when = $days === null ? __('No date') : ($late ? trans_choice(':count day late|:count days late', -$days) : ($days === 0 ? __('Due today') : ($days === 1 ? __('Tomorrow') : trans_choice('In :count day|In :count days', $days))));
                        @endphp
                        <div wire:key="t-{{ $t->id }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})" class="-m-2 flex size-10 shrink-0 items-center justify-center sm:m-0 sm:size-5" aria-label="{{ __('Mark as done') }}" data-test="done-{{ $t->id }}">
                                <span class="flex size-6 items-center justify-center rounded-full ring-[1.5px] ring-zinc-300 transition group-hover:ring-emerald-500 sm:size-5 dark:ring-white/20"><flux:icon.check variant="micro" class="size-3.5 text-emerald-600 opacity-0 transition group-hover:opacity-60" /></span>
                            </button>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $t->title }}</p>
                                <p class="mt-1 flex items-center gap-1.5">
                                    <span @class(['inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset', 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => $late, 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ! $late && $days !== null && $days <= 1, 'bg-zinc-50 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10' => $days === null || $days > 1])>{{ $when }}</span>
                                    <span class="truncate text-xs text-zinc-500">{{ $t->client?->displayName() ?? __('Team task') }}</span>
                                </p>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 px-5 py-8 text-center">
                            <span class="flex size-11 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge class="size-5" /></span>
                            <p class="text-xs text-zinc-500">{{ __('No open tasks for this week.') }}</p>
                        </div>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    <div class="grid items-start gap-4 sm:gap-6 md:grid-cols-2">
        {{-- Footage on its way --}}
        <section class="{{ $panel }} overflow-hidden" data-test="shoots-coming">
            <div class="flex items-center gap-3 px-4 py-4 sm:px-5">
                <span class="flex size-9 items-center justify-center rounded-lg bg-violet-500 text-white shadow-md shadow-violet-500/25"><flux:icon.video-camera variant="mini" class="size-4.5" /></span>
                <div class="flex-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Shoots coming up') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Footage on its way in the next 2 weeks') }}</p>
                </div>
            </div>
            <ul class="flex flex-col gap-1 border-t border-zinc-100 p-2 dark:border-white/5">
                @forelse ($shoots as $s)
                    @php [$day, $mon] = array_pad(explode(' ', $s->shoot_date->toNepali(false), 2), 2, ''); @endphp
                    <li wire:key="sh-{{ $s->id }}" class="flex items-center gap-3 rounded-xl p-2">
                        <span class="flex w-11 shrink-0 flex-col items-center overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-violet-100 dark:bg-zinc-900 dark:ring-violet-500/20">
                            <span class="w-full bg-violet-600 py-0.5 text-center text-[0.5625rem] font-bold tracking-wider text-white uppercase">{{ mb_substr($mon, 0, 3) }}</span>
                            <span class="py-0.5 text-base font-bold text-zinc-900 tabular-nums dark:text-white">{{ $day }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $s->title }}</span>
                            <span class="block truncate text-xs text-zinc-500">{{ $s->client?->displayName() ?? __('Other scripts') }} · {{ $s->shootLabel() }}</span>
                        </span>
                    </li>
                @empty
                    <li class="px-3 py-8 text-center text-xs text-zinc-500">{{ __('No shoots planned yet.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Recently edited --}}
        <section class="{{ $panel }} overflow-hidden" data-test="recently-edited">
            <div class="flex items-center gap-3 px-4 py-4 sm:px-5">
                <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-500 text-white shadow-md shadow-emerald-500/25"><flux:icon.check-badge variant="mini" class="size-4.5" /></span>
                <div class="flex-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Recently edited') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('The latest finished edits') }}</p>
                </div>
            </div>
            <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                @forelse ($this->recentlyEdited as $v)
                    <li wire:key="done-{{ $v->id }}" class="flex items-center gap-3 px-4 py-3 sm:px-5">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check variant="micro" class="size-4" /></span>
                        <a href="{{ $v->url }}" target="_blank" rel="noopener" class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-zinc-900 hover:text-indigo-600 dark:text-white">{{ $v->title }}</span>
                            <span class="block truncate text-xs text-zinc-500">{{ $v->client?->displayName() ?? __('No client') }} · {{ $v->updated_at->diffForHumans() }}</span>
                        </a>
                        <button type="button" wire:click="moveVideo({{ $v->id }}, 'editing')" wire:confirm="{{ __('Move “:title” back to editing?', ['title' => $v->title]) }}" class="shrink-0 rounded-md p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5" title="{{ __('Back to editing') }}" aria-label="{{ __('Back to editing') }}"><flux:icon.arrow-uturn-left variant="micro" class="size-4" /></button>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-xs text-zinc-500">{{ __('Nothing edited yet.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
