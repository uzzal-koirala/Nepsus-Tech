<?php

use App\Models\Attendance;
use App\Models\Script;
use App\Models\Task;
use App\Models\Video;
use App\Support\NepaliDate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My day')] class extends Component {
    #[Computed]
    public function me()
    {
        return auth()->user()->employee;
    }

    /** My open tasks with a date up to a week ahead, plus undated ones. */
    #[Computed]
    public function tasks()
    {
        return Task::with('client')->whereNull('completed_at')
            ->whereHas('assignees', fn ($q) => $q->whereKey($this->me?->id ?? 0))
            ->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', today()->addDays(7)))
            ->orderByRaw('due_date is null')->orderBy('due_date')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->get();
    }

    #[Computed]
    public function doneThisWeek(): int
    {
        return Task::whereHas('assignees', fn ($q) => $q->whereKey($this->me?->id ?? 0))
            ->where('completed_at', '>=', today()->startOfWeek(\Carbon\CarbonInterface::SUNDAY))->count();
    }

    #[Computed]
    public function shoots()
    {
        return Script::with('client')->whereDate('shoot_date', '>=', today())->whereDate('shoot_date', '<=', today()->addDays(14))
            ->orderBy('shoot_date')->limit(6)->get();
    }

    #[Computed]
    public function scripts()
    {
        return Script::with('client')->whereIn('status', ['idea', 'draft', 'review'])->latest('updated_at')->limit(5)->get();
    }

    #[Computed]
    public function videos()
    {
        return Video::with('client')->whereIn('status', ['raw', 'editing'])->orderByRaw("case status when 'editing' then 0 else 1 end")->latest()->limit(5)->get();
    }

    /** This Nepali month: present, half days, leave and absent. */
    #[Computed]
    public function attendance(): array
    {
        [$from, $to] = NepaliDate::monthRange(today());
        $counts = $this->me
            ? Attendance::where('employee_id', $this->me->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->countBy('status')->all()
            : [];

        return array_map(fn ($k) => $counts[$k] ?? 0, array_combine(array_keys(Attendance::STATUSES), array_keys(Attendance::STATUSES)));
    }

    public function toggleDone(int $id): void
    {
        $t = Task::whereHas('assignees', fn ($q) => $q->whereKey($this->me?->id ?? 0))->findOrFail($id);
        $t->update($t->completed_at ? ['completed_at' => null, 'status' => 'todo'] : ['completed_at' => now(), 'status' => 'done']);
        unset($this->tasks, $this->doneThisWeek);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $me = $this->me;
    $tasks = $this->tasks;
    $overdue = $tasks->filter(fn ($t) => $t->due_date?->lt(today()));
    $today = $tasks->filter(fn ($t) => $t->due_date?->isToday());
    $soon = $tasks->filter(fn ($t) => $t->due_date?->gt(today()));
    $undated = $tasks->whereNull('due_date');
    $shoots = $this->shoots;
    $videos = $this->videos;
    $att = $this->attendance;
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
    $firstName = str(auth()->user()->name)->before(' ');
    $nextSalary = $me?->nextSalaryDate();
    $types = Task::types();
@endphp

<div class="stagger flex w-full flex-col gap-6">
    {{-- Hello --}}
    <section class="relative overflow-hidden rounded-2xl bg-linear-to-br from-brand-500 via-brand-600 to-violet-600 p-6 text-white shadow-lg shadow-brand-500/20 sm:p-8">
        <span class="pointer-events-none absolute -top-16 -right-10 size-64 rounded-full bg-white/10"></span>
        <span class="pointer-events-none absolute right-40 -bottom-24 size-48 rounded-full bg-white/5"></span>
        <div class="relative flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-sm text-white/75">{{ today()->toNepali() }} · {{ today()->format('l, j F') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $greeting }}, {{ $firstName }}</h1>
                <p class="mt-1 text-sm text-white/80">
                    @if ($me){{ $me->designation ?: __($me->departmentMeta()['label']) }} · @endif
                    {{ $today->count() || $overdue->count() ? trans_choice(':count thing to finish today|:count things to finish today', $today->count() + $overdue->count()) : __('Nothing due today. Nice.') }}
                </p>
            </div>
            <div class="grid w-full grid-cols-2 gap-2 sm:w-auto sm:grid-cols-4">
                @foreach ([
                    [__('Due today'), $today->count(), 'clock'],
                    [__('Overdue'), $overdue->count(), 'exclamation-triangle'],
                    [__('Shoots soon'), $shoots->count(), 'video-camera'],
                    [__('Done this week'), $this->doneThisWeek, 'check-badge'],
                ] as [$label, $n, $icon])
                    <div class="rounded-xl bg-white/12 px-4 py-3 ring-1 ring-white/15 backdrop-blur sm:min-w-28">
                        <flux:icon :icon="$icon" variant="micro" class="size-4 text-white/70" />
                        <p class="mt-1.5 text-2xl leading-none font-bold tabular-nums">{{ $n }}</p>
                        <p class="mt-1 text-[0.6875rem] text-white/75">{{ $label }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @unless ($me)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">{{ __('Your login is not linked to an employee profile yet, so no tasks show here. Ask an admin to link it.') }}</p>
    @endunless

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- My tasks --}}
        <section class="{{ $panel }} overflow-hidden" data-test="my-tasks">
            <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('My tasks') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Late, today and the next 7 days') }}</p>
                </div>
                <a href="{{ route('my.tasks') }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">{{ __('All my tasks') }}</a>
            </div>
            @forelse ([[__('Overdue'), $overdue, 'text-rose-600 dark:text-rose-400'], [__('Today'), $today, 'text-brand-600 dark:text-brand-300'], [__('Next 7 days'), $soon, 'text-zinc-500'], [__('No date'), $undated, 'text-zinc-400']] as [$label, $list, $tone])
                @continue($list->isEmpty())
                <p class="{{ $tone }} px-5 pt-3 pb-1 text-[0.6875rem] font-semibold tracking-wider uppercase">{{ $label }} · {{ $list->count() }}</p>
                <ul class="px-2 pb-1">
                    @foreach ($list as $t)
                        @php $type = $types[$t->type] ?? Task::unknownType($t->type); @endphp
                        <li wire:key="t-{{ $t->id }}" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})" class="flex size-5 shrink-0 items-center justify-center rounded-md ring-1 ring-zinc-300 transition hover:bg-emerald-50 hover:ring-emerald-500 dark:ring-white/20" aria-label="{{ __('Mark as done') }}" data-test="done-{{ $t->id }}">
                                <flux:icon.check variant="micro" class="size-3.5 text-emerald-600 opacity-0 transition group-hover:opacity-60" />
                            </button>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $t->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $t->client?->displayName() ?? __('Team task') }} · {{ $type['label'] }}</span>
                            </span>
                            @if (in_array($t->priority, ['urgent', 'high']))
                                <span class="{{ (Task::PRIORITY_STYLES[$t->priority])['tone'] }} shrink-0 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1">{{ __(Task::PRIORITIES[$t->priority]) }}</span>
                            @endif
                            @if ($t->due_date)
                                <span @class(['shrink-0 text-xs tabular-nums', 'font-semibold text-rose-600' => $t->due_date->lt(today()), 'text-zinc-500' => ! $t->due_date->lt(today())])>{{ $t->due_date->isToday() ? __('Today') : ($t->due_date->isTomorrow() ? __('Tomorrow') : $t->due_date->toNepali(false)) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @empty
            @endforelse
            @if ($tasks->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 pt-6 pb-10 text-center">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge class="size-6" /></span>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('All clear') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('No open tasks for this week.') }}</p>
                </div>
            @endif
            <div class="h-3"></div>
        </section>

        {{-- Shoots --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Upcoming shoots') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Next 2 weeks') }}</p>
                </div>
                <a href="{{ route('calendar.index') }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">{{ __('Calendar') }}</a>
            </div>
            <ul class="flex flex-col gap-1 px-3 pb-3">
                @forelse ($shoots as $s)
                    @php [$day, $month] = array_pad(explode(' ', $s->shoot_date->toNepali(false), 2), 2, ''); @endphp
                    <li wire:key="sh-{{ $s->id }}">
                        <a href="{{ route('scripts.edit', $s) }}" wire:navigate class="group flex items-center gap-3 rounded-lg p-2 transition hover:bg-violet-50 dark:hover:bg-violet-500/10">
                            <span class="flex w-11 shrink-0 flex-col items-center overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-violet-100 dark:bg-zinc-900 dark:ring-violet-500/20">
                                <span class="w-full bg-violet-600 py-0.5 text-center text-[0.5625rem] font-bold tracking-wider text-white uppercase">{{ mb_substr($month, 0, 3) }}</span>
                                <span class="py-0.5 text-base font-bold text-zinc-900 tabular-nums dark:text-white">{{ $day }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-zinc-900 group-hover:text-violet-700 dark:text-white">{{ $s->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $s->client?->displayName() ?? __('Other scripts') }} · {{ $s->shootLabel() }}</span>
                            </span>
                        </a>
                    </li>
                @empty
                    <li class="flex flex-col items-center gap-2 py-8 text-center">
                        <span class="flex size-11 items-center justify-center rounded-2xl bg-violet-50 text-violet-500 dark:bg-violet-500/10"><flux:icon.video-camera class="size-5" /></span>
                        <p class="text-xs text-zinc-500">{{ __('No shoots planned yet.') }}</p>
                    </li>
                @endforelse
            </ul>
        </section>
    </div>

    <div class="grid items-start gap-6 md:grid-cols-2 xl:grid-cols-3">
        {{-- Scripts --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.pencil-square variant="micro" class="size-4" /></span>{{ __('Scripts in progress') }}</h2>
                <a href="{{ route('scripts.index') }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300">{{ __('All') }}</a>
            </div>
            <ul class="px-2 pb-3">
                @forelse ($this->scripts as $s)
                    @php $st = Script::STATUSES[$s->status]; @endphp
                    <li wire:key="sc-{{ $s->id }}">
                        <a href="{{ route('scripts.edit', $s) }}" wire:navigate class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span class="{{ $st['dot'] }} h-7 w-1 shrink-0 rounded-full"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $s->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $s->client?->displayName() ?? __('Other scripts') }} · {{ $s->updated_at->diffForHumans(short: true) }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-zinc-500">{{ __($st['label']) }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-xs text-zinc-500">{{ __('No scripts in progress.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Videos --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300"><flux:icon.film variant="micro" class="size-4" /></span>{{ __('Videos to edit') }}</h2>
                <a href="{{ route('videos.index') }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300">{{ __('All') }}</a>
            </div>
            <ul class="px-2 pb-3">
                @forelse ($videos as $v)
                    @php $st = Video::STATUSES[$v->status]; @endphp
                    <li wire:key="v-{{ $v->id }}">
                        <a href="{{ $v->url }}" target="_blank" rel="noopener" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-white dark:bg-white dark:text-zinc-900"><flux:icon.play variant="micro" class="size-3.5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $v->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $v->client?->displayName() ?? __('No client') }}</span>
                            </span>
                            <span class="{{ $st['tone'] }} shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset">{{ __($st['short']) }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-xs text-zinc-500">{{ __('No footage waiting.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Me this month --}}
        <section class="{{ $panel }} overflow-hidden md:col-span-2 xl:col-span-1">
            <div class="px-5 pt-5 pb-3">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300"><flux:icon.finger-print variant="micro" class="size-4" /></span>{{ __('My attendance') }}</h2>
                <p class="text-xs text-zinc-500">{{ today()->toNepaliMonth() }}</p>
            </div>
            <div class="grid grid-cols-4 gap-2 px-5">
                @foreach ([['present', 'bg-emerald-500'], ['half_day', 'bg-amber-400'], ['leave', 'bg-sky-500'], ['absent', 'bg-rose-500']] as [$k, $dot])
                    <div class="rounded-xl bg-zinc-50 px-2 py-3 text-center dark:bg-white/[0.03]">
                        <p class="text-xl font-bold text-zinc-900 tabular-nums dark:text-white">{{ $att[$k] }}</p>
                        <p class="mt-0.5 flex items-center justify-center gap-1 text-[0.6875rem] text-zinc-500"><span class="{{ $dot }} size-1.5 rounded-full"></span>{{ __(Attendance::STATUSES[$k]['label']) }}</p>
                    </div>
                @endforeach
            </div>
            <div class="m-5 mt-4 flex items-center gap-3 rounded-xl bg-brand-50/70 px-4 py-3 dark:bg-brand-500/10">
                <flux:icon.calendar-days variant="mini" class="size-5 text-brand-600 dark:text-brand-300" />
                <p class="text-sm text-zinc-700 dark:text-zinc-200">
                    @if ($nextSalary)
                        {{ __('Next salary day') }}: <span class="font-semibold">{{ $nextSalary->isToday() ? __('Today') : $nextSalary->toNepali(false) }}</span>
                    @else
                        {{ __('Salary day not set yet') }}
                    @endif
                </p>
            </div>
        </section>
    </div>
</div>
