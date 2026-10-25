<?php

use App\Models\Attendance;
use App\Models\Client;
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

    private function mine()
    {
        return Task::whereHas('assignees', fn ($q) => $q->whereKey($this->me?->id ?? 0));
    }

    /** My open tasks with a date up to a week ahead, plus undated ones. */
    #[Computed]
    public function tasks()
    {
        return $this->mine()->with('client')->whereNull('completed_at')
            ->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', today()->addDays(7)))
            ->orderByRaw('due_date is null')->orderBy('due_date')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->get();
    }

    #[Computed]
    public function doneThisWeek(): int
    {
        return $this->mine()->where('completed_at', '>=', today()->startOfWeek(\Carbon\CarbonInterface::SUNDAY))->count();
    }

    /** My work due this Nepali month: how much is done. */
    #[Computed]
    public function month(): array
    {
        [$from, $to] = NepaliDate::monthRange(today());
        $tasks = $this->mine()->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)->get(['id', 'completed_at']);

        return ['total' => $tasks->count(), 'done' => $tasks->whereNotNull('completed_at')->count()];
    }

    /** Today and the next 6 days: my open tasks and every shoot, per day. */
    #[Computed]
    public function week(): array
    {
        $end = today()->addDays(6);
        $tasks = $this->mine()->whereNull('completed_at')->whereDate('due_date', '>=', today())->whereDate('due_date', '<=', $end)->get(['id', 'due_date'])
            ->countBy(fn ($t) => $t->due_date->toDateString());
        $shoots = Script::whereDate('shoot_date', '>=', today())->whereDate('shoot_date', '<=', $end)->get(['id', 'shoot_date'])
            ->countBy(fn ($s) => $s->shoot_date->toDateString());

        return collect(range(0, 6))->map(fn ($i) => today()->addDays($i))->map(fn ($d) => [
            'date' => $d, 'tasks' => $tasks[$d->toDateString()] ?? 0, 'shoots' => $shoots[$d->toDateString()] ?? 0,
        ])->all();
    }

    #[Computed]
    public function shoots()
    {
        return Script::with('client')->whereDate('shoot_date', '>=', today())->whereDate('shoot_date', '<=', today()->addDays(14))
            ->orderBy('shoot_date')->limit(5)->get();
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

    /** Clients I have open work for. */
    #[Computed]
    public function clients()
    {
        $me = $this->me?->id ?? 0;

        return Client::whereHas('tasks', fn ($q) => $q->whereNull('completed_at')->whereHas('assignees', fn ($a) => $a->whereKey($me)))
            ->withCount(['tasks as my_open' => fn ($q) => $q->whereNull('completed_at')->whereHas('assignees', fn ($a) => $a->whereKey($me))])
            ->orderByDesc('my_open')->limit(5)->get();
    }

    /** This Nepali month: the status of each day I was marked. */
    #[Computed]
    public function attendance(): array
    {
        [$from, $to] = NepaliDate::monthRange(today());

        return $this->me
            ? Attendance::where('employee_id', $this->me->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()
                ->mapWithKeys(fn ($a) => [$a->date->toDateString() => $a->status])->all()
            : [];
    }

    public function toggleDone(int $id): void
    {
        $t = $this->mine()->findOrFail($id);
        $t->update($t->completed_at ? ['completed_at' => null, 'status' => 'todo'] : ['completed_at' => now(), 'status' => 'done']);
        unset($this->tasks, $this->doneThisWeek, $this->month, $this->week, $this->clients);
    }

    public function newScript(): void
    {
        $script = Script::create(['title' => __('Untitled script'), 'type' => 'reel', 'status' => 'draft', 'user_id' => auth()->id()]);
        $this->redirectRoute('scripts.edit', $script, navigate: true);
    }
}; ?>

@php
    $panel = 'rounded-2xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $head = 'flex items-center justify-between gap-3 px-5 pt-5 pb-3';
    $link = 'rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10';
    $me = $this->me;
    $tasks = $this->tasks;
    $overdue = $tasks->filter(fn ($t) => $t->due_date?->lt(today()));
    $today = $tasks->filter(fn ($t) => $t->due_date?->isToday());
    $soon = $tasks->filter(fn ($t) => $t->due_date?->gt(today()));
    $undated = $tasks->whereNull('due_date');
    $shoots = $this->shoots;
    $week = $this->week;
    $month = $this->month;
    $monthPct = $month['total'] ? (int) round($month['done'] / $month['total'] * 100) : 0;
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
    $firstName = str(auth()->user()->name)->before(' ');
    $nextSalary = $me?->nextSalaryDate();
    $salaryDays = $nextSalary ? (int) today()->diffInDays($nextSalary) : null;
    $types = Task::types();
    $att = $this->attendance;
    [$monthFrom, $monthTo] = NepaliDate::monthRange(today());
    $attTone = ['present' => 'bg-emerald-500 text-white', 'half_day' => 'bg-amber-400 text-amber-950', 'leave' => 'bg-sky-500 text-white', 'absent' => 'bg-rose-500 text-white'];
    $attCounts = collect($att)->countBy()->all();
    $ring = 2 * M_PI * 42;
@endphp

<div class="stagger flex w-full flex-col gap-6">
    {{-- Hello --}}
    <section class="relative overflow-hidden rounded-2xl bg-linear-to-br from-brand-500 via-brand-600 to-violet-600 p-6 text-white shadow-[0_24px_50px_-24px_rgb(49_81_254/0.7)] sm:p-8">
        <span class="pointer-events-none absolute -top-20 -right-16 size-72 rounded-full bg-white/10"></span>
        <span class="pointer-events-none absolute right-48 -bottom-28 size-56 rounded-full bg-sun/25 blur-2xl"></span>
        <div class="pointer-events-none absolute inset-0 opacity-25 [background-image:radial-gradient(currentColor_1px,transparent_1px)] [background-size:18px_18px] [mask-image:linear-gradient(to_left,black,transparent_60%)]"></div>
        <div class="relative flex flex-wrap items-center justify-between gap-6">
            <div class="flex items-center gap-4">
                @if ($me?->avatarUrl())
                    <img src="{{ $me->avatarUrl() }}" alt="" class="size-16 shrink-0 rounded-2xl object-cover ring-4 ring-white/25">
                @else
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-white/15 text-xl font-bold ring-4 ring-white/20 backdrop-blur">{{ auth()->user()->initials() }}</span>
                @endif
                <div>
                    <p class="text-sm text-white/75">{{ today()->toNepali() }} · {{ today()->format('l, j F') }}</p>
                    <h1 class="mt-0.5 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $greeting }}, {{ $firstName }} 👋</h1>
                    <p class="mt-1 text-sm text-white/80">
                        @if ($me){{ $me->designation ?: __($me->departmentMeta()['label']) }} · @endif
                        {{ $today->count() || $overdue->count() ? trans_choice(':count thing to finish today|:count things to finish today', $today->count() + $overdue->count()) : __('Nothing due today. Nice.') }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="newScript" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-brand-700 shadow-lg shadow-black/10 transition hover:-translate-y-0.5" data-test="quick-script"><flux:icon.pencil-square variant="mini" class="size-4" />{{ __('New script') }}</button>
                <a href="{{ route('videos.index') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25"><flux:icon.video-camera variant="mini" class="size-4" />{{ __('Add video') }}</a>
                <a href="{{ route('calendar.index') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25"><flux:icon.calendar-days variant="mini" class="size-4" />{{ __('Calendar') }}</a>
            </div>
        </div>
    </section>

    @unless ($me)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">{{ __('Your login is not linked to an employee profile yet, so no tasks show here. Ask an admin to link it.') }}</p>
    @endunless

    {{-- Stat boxes --}}
    <section class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ([
            [__('Due today'), $today->count(), 'clock', 'bg-brand-500 shadow-brand-500/30', __('Tasks to finish today'), 'from-brand-50'],
            [__('Overdue'), $overdue->count(), 'exclamation-triangle', 'bg-rose-500 shadow-rose-500/30', $overdue->count() ? __('Finish these first') : __('Nothing late'), 'from-rose-50'],
            [__('Shoots soon'), $shoots->count(), 'video-camera', 'bg-violet-500 shadow-violet-500/30', __('In the next 2 weeks'), 'from-violet-50'],
            [__('Done this week'), $this->doneThisWeek, 'check-badge', 'bg-emerald-500 shadow-emerald-500/30', __('Keep it going'), 'from-emerald-50'],
        ] as [$label, $n, $icon, $tile, $sub, $tint])
            <div class="{{ $panel }} {{ $tint }} relative overflow-hidden bg-linear-to-br to-white to-60% p-5 dark:from-white/[0.03] dark:to-zinc-950">
                <div class="flex items-center justify-between">
                    <span class="{{ $tile }} flex size-10 items-center justify-center rounded-xl text-white shadow-md"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                    <span class="text-3xl font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $n }}</span>
                </div>
                <p class="mt-4 text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</p>
                <p class="text-xs text-zinc-500">{{ $sub }}</p>
            </div>
        @endforeach
    </section>

    {{-- The week ahead --}}
    <section class="{{ $panel }} p-5">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Your week') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Your tasks and every shoot for the next 7 days') }}</p>
            </div>
            <a href="{{ route('calendar.index') }}" wire:navigate class="{{ $link }}">{{ __('Open calendar') }}</a>
        </div>
        <div class="grid grid-cols-4 gap-2 sm:grid-cols-7">
            @foreach ($week as $d)
                @php [$bsY, $bsM, $bsD] = NepaliDate::fromAd($d['date']); $busy = $d['tasks'] + $d['shoots']; @endphp
                <a href="{{ route('calendar.index', ['month' => sprintf('%d-%02d', $bsY, $bsM)]) }}" wire:navigate @class([
                    'group flex flex-col items-center gap-1 rounded-xl px-2 py-3 text-center ring-1 transition hover:-translate-y-0.5',
                    'bg-brand-500 text-white ring-brand-500 shadow-lg shadow-brand-500/30' => $d['date']->isToday(),
                    'bg-zinc-50 ring-zinc-100 hover:bg-white hover:ring-brand-200 dark:bg-white/[0.03] dark:ring-white/5' => ! $d['date']->isToday(),
                    'max-sm:hidden' => $loop->index >= 4,
                ])>
                    <span @class(['text-[0.6875rem] font-semibold tracking-wider uppercase', 'text-white/80' => $d['date']->isToday(), 'text-zinc-400' => ! $d['date']->isToday()])>{{ $d['date']->isToday() ? __('Today') : $d['date']->format('D') }}</span>
                    <span @class(['text-2xl font-bold tabular-nums', 'text-zinc-900 dark:text-white' => ! $d['date']->isToday()])>{{ $bsD }}</span>
                    <span @class(['text-[0.625rem]', 'text-white/70' => $d['date']->isToday(), 'text-zinc-400' => ! $d['date']->isToday()])>{{ mb_substr(NepaliDate::MONTHS[$bsM - 1], 0, 3) }}</span>
                    <span class="mt-1 flex min-h-5 flex-wrap items-center justify-center gap-1">
                        @if ($d['tasks'])<span @class(['rounded-full px-1.5 py-0.5 text-[0.625rem] font-semibold', 'bg-white/20' => $d['date']->isToday(), 'bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200' => ! $d['date']->isToday()])>{{ trans_choice(':count task|:count tasks', $d['tasks']) }}</span>@endif
                        @if ($d['shoots'])<span @class(['inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[0.625rem] font-semibold', 'bg-white/20' => $d['date']->isToday(), 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200' => ! $d['date']->isToday()])><flux:icon.video-camera variant="micro" class="size-3" />{{ $d['shoots'] }}</span>@endif
                        @if (! $busy)<span @class(['text-[0.625rem]', 'text-white/60' => $d['date']->isToday(), 'text-zinc-300 dark:text-zinc-600' => ! $d['date']->isToday()])>{{ __('Free') }}</span>@endif
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- My tasks --}}
        <section class="{{ $panel }} overflow-hidden" data-test="my-tasks">
            <div class="{{ $head }}">
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('My tasks') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Late, today and the next 7 days. Tick them off here.') }}</p>
                </div>
                <a href="{{ route('my.tasks') }}" wire:navigate class="{{ $link }}">{{ __('All my tasks') }}</a>
            </div>
            @foreach ([[__('Overdue'), $overdue, 'text-rose-600 dark:text-rose-400', 'bg-rose-500'], [__('Today'), $today, 'text-brand-600 dark:text-brand-300', 'bg-brand-500'], [__('Next 7 days'), $soon, 'text-zinc-500', 'bg-zinc-300'], [__('No date'), $undated, 'text-zinc-400', 'bg-zinc-200']] as [$label, $list, $tone, $bar])
                @continue($list->isEmpty())
                <p class="{{ $tone }} flex items-center gap-2 px-5 pt-3 pb-1 text-[0.6875rem] font-semibold tracking-wider uppercase"><span class="{{ $bar }} h-3 w-1 rounded-full"></span>{{ $label }} · {{ $list->count() }}</p>
                <ul class="px-2 pb-1">
                    @foreach ($list as $t)
                        @php $type = $types[$t->type] ?? Task::unknownType($t->type); @endphp
                        <li wire:key="t-{{ $t->id }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})" class="flex size-5 shrink-0 items-center justify-center rounded-md ring-1 ring-zinc-300 transition hover:bg-emerald-50 hover:ring-emerald-500 dark:ring-white/20" aria-label="{{ __('Mark as done') }}" data-test="done-{{ $t->id }}">
                                <flux:icon.check variant="micro" class="size-3.5 text-emerald-600 opacity-0 transition group-hover:opacity-60" />
                            </button>
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400"><flux:icon :icon="$type['icon']" variant="micro" class="size-4" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $t->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $t->client?->displayName() ?? __('Team task') }} · {{ $type['label'] }}</span>
                            </span>
                            @if (in_array($t->priority, ['urgent', 'high']))
                                <span class="{{ (Task::PRIORITY_STYLES[$t->priority])['tone'] }} shrink-0 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1">{{ __(Task::PRIORITIES[$t->priority]) }}</span>
                            @endif
                            @if ($t->due_date)
                                <span @class(['w-16 shrink-0 text-end text-xs tabular-nums', 'font-semibold text-rose-600' => $t->due_date->lt(today()), 'text-zinc-500' => ! $t->due_date->lt(today())])>{{ $t->due_date->isToday() ? __('Today') : ($t->due_date->isTomorrow() ? __('Tomorrow') : $t->due_date->toNepali(false)) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
            @if ($tasks->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 pt-6 pb-10 text-center">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge class="size-7" /></span>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('All clear') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('No open tasks for this week.') }}</p>
                </div>
            @endif
            <div class="h-3"></div>
        </section>

        <div class="flex flex-col gap-6">
            {{-- Month progress --}}
            <section class="{{ $panel }} p-5">
                <div class="flex items-center gap-5">
                    <div class="relative size-28 shrink-0">
                        <svg viewBox="0 0 100 100" class="size-28 -rotate-90">
                            <circle cx="50" cy="50" r="42" fill="none" stroke-width="10" class="stroke-zinc-100 dark:stroke-white/10" />
                            <circle cx="50" cy="50" r="42" fill="none" stroke-width="10" stroke-linecap="round" class="stroke-brand-500 transition-all duration-700" stroke-dasharray="{{ $ring }}" stroke-dashoffset="{{ $ring * (1 - $monthPct / 100) }}" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold text-zinc-900 tabular-nums dark:text-white">{{ $monthPct }}%</span>
                            <span class="text-[0.625rem] text-zinc-500">{{ __('done') }}</span>
                        </div>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-wider text-zinc-400 uppercase">{{ today()->toNepaliMonth() }}</p>
                        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ $month['done'] }} <span class="text-sm font-normal text-zinc-400">/ {{ $month['total'] }}</span></p>
                        <p class="text-xs text-zinc-500">{{ __('of your work this month is done') }}</p>
                    </div>
                </div>
            </section>

            {{-- Quick links --}}
            <section class="{{ $panel }} grid grid-cols-2 gap-2 p-3">
                @foreach ([
                    [__('My tasks'), 'my.tasks', 'check-circle', 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300'],
                    [__('Clients'), 'my.clients', 'building-office-2', 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300'],
                    [__('Scripts'), 'scripts.index', 'pencil-square', 'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300'],
                    [__('Videos'), 'videos.index', 'film', 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300'],
                ] as [$label, $route, $icon, $tone])
                    <a href="{{ route($route) }}" wire:navigate class="group flex items-center gap-3 rounded-xl p-3 transition hover:bg-zinc-50 dark:hover:bg-white/5">
                        <span class="{{ $tone }} flex size-10 items-center justify-center rounded-xl transition group-hover:scale-110"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $label }}</span>
                    </a>
                @endforeach
            </section>
        </div>
    </div>

    {{-- Content --}}
    <div class="grid items-start gap-6 md:grid-cols-2 xl:grid-cols-3">
        {{-- Shoots --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="{{ $head }}">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300"><flux:icon.video-camera variant="micro" class="size-4" /></span>{{ __('Upcoming shoots') }}</h2>
                <a href="{{ route('calendar.index') }}" wire:navigate class="{{ $link }}">{{ __('Calendar') }}</a>
            </div>
            <ul class="flex flex-col gap-1 px-3 pb-3">
                @forelse ($shoots as $s)
                    @php [$day, $mon] = array_pad(explode(' ', $s->shoot_date->toNepali(false), 2), 2, ''); @endphp
                    <li wire:key="sh-{{ $s->id }}">
                        <a href="{{ route('scripts.edit', $s) }}" wire:navigate class="group flex items-center gap-3 rounded-xl p-2 transition hover:bg-violet-50 dark:hover:bg-violet-500/10">
                            <span class="flex w-11 shrink-0 flex-col items-center overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-violet-100 dark:bg-zinc-900 dark:ring-violet-500/20">
                                <span class="w-full bg-violet-600 py-0.5 text-center text-[0.5625rem] font-bold tracking-wider text-white uppercase">{{ mb_substr($mon, 0, 3) }}</span>
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

        {{-- Scripts --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="{{ $head }}">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.pencil-square variant="micro" class="size-4" /></span>{{ __('Scripts in progress') }}</h2>
                <a href="{{ route('scripts.index') }}" wire:navigate class="{{ $link }}">{{ __('All') }}</a>
            </div>
            <ul class="px-2 pb-3">
                @forelse ($this->scripts as $s)
                    @php $st = Script::STATUSES[$s->status]; @endphp
                    <li wire:key="sc-{{ $s->id }}">
                        <a href="{{ route('scripts.edit', $s) }}" wire:navigate class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span class="{{ $st['dot'] }} h-8 w-1 shrink-0 rounded-full"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $s->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $s->client?->displayName() ?? __('Other scripts') }} · {{ $s->updated_at->diffForHumans(short: true) }}</span>
                            </span>
                            <span class="{{ $st['tone'] }} shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset">{{ __($st['label']) }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-3 py-8 text-center text-xs text-zinc-500">{{ __('No scripts in progress.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Videos --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="{{ $head }}">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300"><flux:icon.film variant="micro" class="size-4" /></span>{{ __('Videos to edit') }}</h2>
                <a href="{{ route('videos.index') }}" wire:navigate class="{{ $link }}">{{ __('All') }}</a>
            </div>
            <ul class="px-2 pb-3">
                @forelse ($this->videos as $v)
                    @php $st = Video::STATUSES[$v->status]; @endphp
                    <li wire:key="v-{{ $v->id }}">
                        <a href="{{ $v->url }}" target="_blank" rel="noopener" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-zinc-800 to-brand-900 text-white transition group-hover:scale-105"><flux:icon :icon="$v->isFolder() ? 'folder-open' : 'play'" variant="micro" class="size-4" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $v->title }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $v->client?->displayName() ?? __('No client') }}</span>
                            </span>
                            <span class="{{ $st['tone'] }} shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset">{{ __($st['short']) }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-3 py-8 text-center text-xs text-zinc-500">{{ __('No footage waiting.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Me --}}
    <div class="grid items-start gap-6 md:grid-cols-2 xl:grid-cols-3">
        {{-- My clients --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="{{ $head }}">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300"><flux:icon.building-office-2 variant="micro" class="size-4" /></span>{{ __('My clients') }}</h2>
                <a href="{{ route('my.clients') }}" wire:navigate class="{{ $link }}">{{ __('All clients') }}</a>
            </div>
            <ul class="px-2 pb-3">
                @forelse ($this->clients as $c)
                    <li wire:key="c-{{ $c->id }}">
                        <a href="{{ route('clients.show', $c) }}" wire:navigate class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            @if ($c->avatarUrl())
                                <img src="{{ $c->avatarUrl() }}" alt="" class="size-9 shrink-0 rounded-lg object-cover">
                            @else
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-brand-500 to-violet-600 text-xs font-bold text-white">{{ $c->initials() }}</span>
                            @endif
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $c->displayName() }}</span>
                            <span class="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-[0.6875rem] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ trans_choice(':count task|:count tasks', $c->my_open) }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-3 py-8 text-center text-xs text-zinc-500">{{ __('No client work for you right now.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Attendance --}}
        <section class="{{ $panel }} p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><span class="flex size-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300"><flux:icon.finger-print variant="micro" class="size-4" /></span>{{ __('My attendance') }}</h2>
                <span class="text-xs text-zinc-500">{{ today()->toNepaliMonth() }}</span>
            </div>
            <div class="grid grid-cols-7 gap-1 text-center">
                @foreach ([__('S'), __('M'), __('T'), __('W'), __('T'), __('F'), __('S')] as $w)
                    <span class="text-[0.625rem] font-semibold text-zinc-400">{{ $w }}</span>
                @endforeach
                @for ($i = 0; $i < $monthFrom->dayOfWeek; $i++)<span></span>@endfor
                @for ($d = $monthFrom->copy(); $d->lte($monthTo); $d = $d->addDay())
                    @php $status = $att[$d->toDateString()] ?? null; @endphp
                    <span @class([
                        'flex aspect-square items-center justify-center rounded-md text-[0.6875rem] font-medium tabular-nums',
                        $attTone[$status] ?? '' => $status,
                        'ring-2 ring-brand-500' => $d->isToday(),
                        'bg-rose-50 text-rose-400 dark:bg-rose-500/10' => ! $status && $d->isHoliday(),
                        'bg-zinc-50 text-zinc-400 dark:bg-white/[0.03]' => ! $status && ! $d->isHoliday(),
                    ]) title="{{ $status ? __(Attendance::STATUSES[$status]['label']) : '' }}">{{ NepaliDate::fromAd($d)[2] }}</span>
                @endfor
            </div>
            <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-[0.6875rem] text-zinc-500">
                @foreach (['present' => 'bg-emerald-500', 'half_day' => 'bg-amber-400', 'leave' => 'bg-sky-500', 'absent' => 'bg-rose-500'] as $k => $dot)
                    <span class="inline-flex items-center gap-1"><span class="{{ $dot }} size-2 rounded-full"></span>{{ __(Attendance::STATUSES[$k]['label']) }} <span class="font-semibold text-zinc-700 tabular-nums dark:text-zinc-200">{{ $attCounts[$k] ?? 0 }}</span></span>
                @endforeach
            </div>
        </section>

        {{-- Salary --}}
        <section class="relative overflow-hidden rounded-2xl bg-linear-to-br from-emerald-500 to-teal-600 p-5 text-white shadow-[0_18px_40px_-20px_rgb(16_185_129/0.7)] md:col-span-2 xl:col-span-1">
            <span class="pointer-events-none absolute -top-10 -right-10 size-36 rounded-full bg-white/10"></span>
            <span class="pointer-events-none absolute -bottom-14 -left-6 size-32 rounded-full bg-white/10"></span>
            <div class="relative">
                <p class="flex items-center gap-2 text-xs font-semibold tracking-wider text-white/80 uppercase"><flux:icon.banknotes variant="micro" class="size-4" />{{ __('Next salary') }}</p>
                @if ($nextSalary)
                    <p class="mt-3 text-4xl font-bold tracking-tight">{{ $salaryDays === 0 ? __('Today') : trans_choice(':count day|:count days', $salaryDays) }}</p>
                    <p class="mt-1 text-sm text-white/85">{{ $nextSalary->toNepali() }} · {{ $nextSalary->format('l') }}</p>
                    @php $cycle = NepaliDate::monthLength(...array_slice(NepaliDate::fromAd(today()), 0, 2)); @endphp
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/20"><div class="h-full rounded-full bg-white transition-[width] duration-700" style="width: {{ max(4, 100 - min(100, round($salaryDays / max(1, $cycle) * 100))) }}%"></div></div>
                @else
                    <p class="mt-3 text-2xl font-bold">{{ __('Not set yet') }}</p>
                    <p class="mt-1 text-sm text-white/85">{{ __('Ask an admin to set your salary day.') }}</p>
                @endif
                @if ($me?->joined_on)
                    <p class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-2.5 py-1.5 text-xs font-medium ring-1 ring-white/20"><flux:icon.sparkles variant="micro" class="size-3.5" />{{ __('With us :time', ['time' => $me->tenure() ?? $me->joined_on->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}</p>
                @endif
            </div>
        </section>
    </div>
</div>
