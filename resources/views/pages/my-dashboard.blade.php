<?php

use App\Models\Script;
use App\Models\Task;
use App\Support\NepaliDate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /** Admins have their own dashboard, the Overview. */
    public function mount(): void
    {
        if (auth()->user()->isAdmin()) {
            $this->redirectRoute('dashboard', navigate: true);
        }
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

    /** My work due this Nepali month: how much is done. */
    #[Computed]
    public function month(): array
    {
        [$from, $to] = NepaliDate::monthRange(today());
        $tasks = $this->mine()->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)->get(['id', 'completed_at']);

        return ['total' => $tasks->count(), 'done' => $tasks->whereNotNull('completed_at')->count()];
    }

    /** Tasks I finished and tasks that fell due, for the last 12 weeks (Sunday to Saturday), oldest first. */
    #[Computed]
    public function weekly(): array
    {
        $weeks = 12;
        $start = today()->subDays(today()->dayOfWeek)->subWeeks($weeks - 1);
        $end = $start->copy()->addWeeks($weeks)->subDay();
        $week = fn ($date) => intdiv((int) $start->diffInDays($date->copy()->startOfDay()), 7);
        $done = array_fill(0, $weeks, 0);
        $due = array_fill(0, $weeks, 0);

        foreach ($this->mine()->whereBetween('completed_at', [$start, $end->copy()->endOfDay()])->pluck('completed_at') as $at) {
            $done[$week($at)]++;
        }
        foreach ($this->mine()->whereDate('due_date', '>=', $start)->whereDate('due_date', '<=', $end)->pluck('due_date') as $at) {
            $due[$week($at)]++;
        }

        return array_map(function ($i) use ($start, $done, $due) {
            $from = $start->copy()->addWeeks($i);

            return ['from' => $from, 'to' => $from->copy()->addDays(6), 'done' => $done[$i], 'due' => $due[$i]];
        }, range(0, $weeks - 1));
    }

    #[Computed]
    public function recentDone()
    {
        return $this->mine()->with('client')->whereNotNull('completed_at')->latest('completed_at')->latest('id')->limit(5)->get();
    }

    #[Computed]
    public function shootsSoon(): int
    {
        return Script::whereDate('shoot_date', '>=', today())->whereDate('shoot_date', '<=', today()->addDays(14))->count();
    }

    public function toggleDone(int $id): void
    {
        $t = $this->mine()->findOrFail($id);
        $t->update($t->completed_at ? ['completed_at' => null, 'status' => 'todo'] : ['completed_at' => now(), 'status' => 'done']);
        unset($this->tasks, $this->month, $this->weekly, $this->recentDone);
    }

    public function newScript(): void
    {
        abort_unless(auth()->user()->canPlanContent(), 403);
        $script = Script::create(['title' => __('Untitled script'), 'type' => 'reel', 'status' => 'draft', 'user_id' => auth()->id()]);
        $this->redirectRoute('scripts.edit', $script, navigate: true);
    }
}; ?>

{{-- Video Editors get a dashboard of their own: footage to edit instead of the general one. --}}
@if (auth()->user()->isVideoEditor())
<div>
    <livewire:pages::editor-dashboard />
</div>
@else
@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $link = 'rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10';
    $me = $this->me;
    $tasks = $this->tasks;
    $overdue = $tasks->filter(fn ($t) => $t->due_date?->lt(today()));
    $today = $tasks->filter(fn ($t) => $t->due_date?->isToday());
    $shoots = $this->shootsSoon;
    $month = $this->month;
    $flow = $this->weekly;
    [$lastWeek, $thisWeek] = array_slice($flow, -2);
    $doneChange = $lastWeek['done'] > 0 ? (int) round(($thisWeek['done'] - $lastWeek['done']) / $lastWeek['done'] * 100) : null;
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
    $types = Task::types();
@endphp

<div class="stagger flex w-full flex-col gap-4 sm:gap-6">
    {{-- Hero --}}
    <section class="grain relative isolate overflow-hidden rounded-2xl bg-brand-500 p-5 text-white sm:p-8">
        <div class="absolute inset-0 -z-10 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_right,black,transparent_70%)]"></div>
        <div class="absolute -right-16 -bottom-28 -z-10 hidden size-72 rounded-full bg-sun sm:block lg:right-10"></div>
        <img src="{{ asset('images/auth-crew.webp') }}" alt="" class="absolute right-0 -bottom-6 -z-10 hidden h-[135%] w-auto lg:block lg:right-8">

        <p class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/20 backdrop-blur">
            <flux:icon.calendar variant="micro" class="size-3.5" />{{ today()->toNepali() }} · {{ now()->translatedFormat('l') }}
        </p>
        <h1 class="mt-3 text-xl font-semibold tracking-[-0.02em] sm:mt-4 sm:text-3xl">{{ $greeting }}, {{ str(auth()->user()->name)->before(' ') }}</h1>
        <p class="mt-1.5 max-w-lg text-[0.8125rem] leading-relaxed text-brand-100 sm:mt-2 sm:text-sm">
            @if ($me){{ $me->designation ?: __($me->departmentMeta()['label']) }} · @endif
            {{ $today->count() || $overdue->count() ? trans_choice(':count thing to finish today|:count things to finish today', $today->count() + $overdue->count()) : __('Nothing due today. Nice.') }}
            · {{ __(':done of :total done this month', ['done' => $month['done'], 'total' => $month['total']]) }}
        </p>
        <div class="mt-5 grid grid-cols-2 gap-2 sm:mt-6 sm:flex sm:flex-wrap">
            @if (auth()->user()->canPlanContent())
                <button type="button" wire:click="newScript" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-3.5 py-2.5 text-sm sm:py-2 font-semibold text-brand-600 shadow-sm transition hover:bg-brand-50" data-test="quick-script"><flux:icon.plus variant="micro" class="size-4" />{{ __('New script') }}</button>
            @else
                <a href="{{ route('my.tasks') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-3.5 py-2.5 text-sm sm:py-2 font-semibold text-brand-600 shadow-sm transition hover:bg-brand-50"><flux:icon.check-circle variant="micro" class="size-4" />{{ __('My tasks') }}</a>
            @endif
            <a href="{{ route('calendar.index') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white/15 px-3.5 py-2.5 text-sm sm:py-2 font-semibold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25"><flux:icon.calendar-days variant="micro" class="size-4" />{{ __('Calendar') }}</a>
        </div>
    </section>

    @unless ($me)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">{{ __('Your login is not linked to an employee profile yet, so no tasks show here. Ask an admin to link it.') }}</p>
    @endunless

    {{-- Stats --}}
    <section class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Due today'), 'value' => $today->count(), 'icon' => 'clock', 'tile' => 'bg-brand-500 shadow-brand-500/30', 'glow' => 'bg-brand-100/70 dark:bg-brand-500/10', 'sub' => trans_choice(':count open task this week|:count open tasks this week', $tasks->count()), 'href' => route('my.tasks'), 'alert' => false],
            ['label' => __('Overdue'), 'value' => $overdue->count(), 'icon' => 'exclamation-triangle', 'tile' => 'bg-rose-500 shadow-rose-500/30', 'glow' => 'bg-rose-100/70 dark:bg-rose-500/10', 'sub' => $overdue->count() ? __('Finish these first') : __('Nothing late'), 'href' => route('my.tasks'), 'alert' => $overdue->isNotEmpty()],
            ['label' => __('Done this week'), 'value' => $thisWeek['done'], 'icon' => 'check-badge', 'tile' => 'bg-emerald-500 shadow-emerald-500/30', 'glow' => 'bg-emerald-100/70 dark:bg-emerald-500/10', 'sub' => $doneChange === null ? __('Nothing done last week') : __(':change% vs last week', ['change' => ($doneChange >= 0 ? '+' : '').$doneChange]), 'href' => route('my.tasks'), 'alert' => false],
            ['label' => __('Shoots soon'), 'value' => $shoots, 'icon' => 'video-camera', 'tile' => 'bg-violet-500 shadow-violet-500/30', 'glow' => 'bg-violet-100/70 dark:bg-violet-500/10', 'sub' => __('In the next 2 weeks'), 'href' => route('calendar.index'), 'alert' => false],
        ] as $card)
            <a href="{{ $card['href'] }}" wire:navigate class="{{ $panel }} group relative flex min-w-0 flex-col gap-3 overflow-hidden p-4 transition hover:-translate-y-0.5 hover:shadow-lg sm:gap-4 sm:p-5">
                <div class="{{ $card['glow'] }} absolute -top-12 -right-12 size-32 rounded-full"></div>
                <div class="relative flex items-center gap-2 sm:gap-3">
                    <span class="{{ $card['tile'] }} flex size-8 shrink-0 items-center justify-center rounded-lg text-white shadow-md sm:size-9"><flux:icon :icon="$card['icon']" variant="mini" class="size-4 sm:size-4.5" /></span>
                    <p class="min-w-0 flex-1 truncate text-[0.8125rem] font-medium text-zinc-600 sm:text-sm dark:text-zinc-300">{{ $card['label'] }}</p>
                    <flux:icon.arrow-up-right variant="micro" class="hidden size-4 text-zinc-300 transition group-hover:text-zinc-500 sm:block" />
                </div>
                <p class="relative text-2xl leading-none font-bold tracking-tight text-zinc-900 tabular-nums sm:text-[1.75rem] dark:text-white">{{ $card['value'] }}</p>
                <p @class(['relative line-clamp-2 text-[0.6875rem] sm:text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $card['alert'], 'text-zinc-500' => ! $card['alert']])>{{ $card['sub'] }}</p>
            </a>
        @endforeach
    </section>

    <div class="grid gap-4 sm:gap-6 lg:grid-cols-3">
        <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
            {{-- My work: smooth area chart, tasks finished vs tasks due --}}
            @php
                $n = count($flow);
                $done = array_column($flow, 'done');
                $due = array_column($flow, 'due');
                $top = max(4, (int) ceil(max(array_merge($done, $due)) * 1.1 / 4) * 4);
                $xs = array_map(fn ($i) => $n > 1 ? 3 + $i * 94 / ($n - 1) : 50, range(0, $n - 1));
                $y = fn ($v) => 100 - $v / $top * 100;
                $curve = fn (array $vals) => \App\Support\SmoothLine::path(array_map(fn ($i) => [$xs[$i], $y($vals[$i])], range(0, $n - 1)));
                $doneLine = $curve($done);
                $dueLine = $curve($due);
                $close = ' L'.end($xs).',100 L'.$xs[0].',100 Z';
                $points = array_map(fn ($i) => [
                    'x' => $xs[$i],
                    'week' => __('Week of :from – :to', ['from' => NepaliDate::format($flow[$i]['from'], false), 'to' => NepaliDate::format($flow[$i]['to'], false)]),
                    // Axis: the month name where a new month starts, otherwise just the day.
                    'tick' => $i === 0 || NepaliDate::fromAd($flow[$i]['from'])[1] !== NepaliDate::fromAd($flow[$i - 1]['from'])[1] ? NepaliDate::format($flow[$i]['from'], false) : (string) NepaliDate::fromAd($flow[$i]['from'])[2],
                    'done' => $done[$i], 'due' => $due[$i],
                    'yd' => $y($done[$i]), 'yu' => $y($due[$i]),
                ], range(0, $n - 1));
            @endphp
            <section class="{{ $panel }} flex h-full flex-col p-4 sm:p-6 [--c-done:#0e9f6e] [--c-due:#3151fe] dark:[--c-done:#1fa576] dark:[--c-due:#7088ff]" data-test="my-work">
                <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
                    <div class="flex-1">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('My work') }}</h2>
                        <p class="text-xs text-zinc-500 sm:text-sm">{{ __('Tasks finished and tasks due, week by week (last 12 weeks)') }}</p>
                    </div>
                    <div class="flex items-center gap-5">
                        @foreach ([[__('Finished'), array_sum($done), 'var(--c-done)', false], [__('Due'), array_sum($due), 'var(--c-due)', true]] as [$label, $total, $color, $dashed])
                            <div>
                                <p class="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                                    <svg class="h-2 w-4" viewBox="0 0 16 8" aria-hidden="true"><line x1="1" y1="4" x2="15" y2="4" stroke="{{ $color }}" stroke-width="2.5" stroke-linecap="round" @if ($dashed) stroke-dasharray="4 3" @endif /></svg>
                                    {{ $label }}
                                </p>
                                <p class="mt-0.5 text-base font-bold text-zinc-900 tabular-nums dark:text-white">{{ $total }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-5 flex min-h-52 flex-1 gap-2 sm:mt-6 sm:min-h-64 sm:gap-3" x-data="{ i: null, pts: @js($points), pick(x) { const r = this.$refs.plot.getBoundingClientRect(); const at = (x - r.left) / r.width * 100; this.i = this.pts.reduce((best, p, k) => Math.abs(p.x - at) < Math.abs(this.pts[best].x - at) ? k : best, 0) } }">
                    {{-- Count axis --}}
                    <div class="flex w-6 shrink-0 flex-col text-end text-[0.6875rem] text-zinc-400 tabular-nums" aria-hidden="true">
                        <div class="relative flex-1">
                            @foreach ([1, 0.75, 0.5, 0.25, 0] as $f)
                                <span class="absolute right-0 -translate-y-1/2" style="top: {{ (1 - $f) * 100 }}%">{{ $top * $f }}</span>
                            @endforeach
                        </div>
                        <div class="h-6 shrink-0"></div>{{-- lines up with the week labels --}}
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <div class="relative min-h-40 flex-1 touch-pan-y sm:min-h-48" x-ref="plot" @mouseleave="i = null" @mousemove="pick($event.clientX)"
                            @touchstart.passive="pick($event.touches[0].clientX)" @touchmove.passive="pick($event.touches[0].clientX)" @touchend="setTimeout(() => i = null, 1800)">
                            @foreach ([0, 0.25, 0.5, 0.75, 1] as $f)
                                <div @class(['pointer-events-none absolute inset-x-0 border-t', 'border-zinc-200 dark:border-white/10' => $f === 1, 'border-dashed border-zinc-100 dark:border-white/5' => $f !== 1]) style="top: {{ $f * 100 }}%"></div>
                            @endforeach

                            <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                <defs>
                                    <linearGradient id="mw-done" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--c-done)" stop-opacity=".22" /><stop offset="100%" stop-color="var(--c-done)" stop-opacity="0" /></linearGradient>
                                    <linearGradient id="mw-due" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--c-due)" stop-opacity=".14" /><stop offset="100%" stop-color="var(--c-due)" stop-opacity="0" /></linearGradient>
                                </defs>
                                <path d="{{ $dueLine.$close }}" fill="url(#mw-due)" />
                                <path d="{{ $doneLine.$close }}" fill="url(#mw-done)" />
                                <path d="{{ $dueLine }}" fill="none" stroke="var(--c-due)" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                <path d="{{ $doneLine }}" fill="none" stroke="var(--c-done)" stroke-width="2.5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                            </svg>

                            {{-- This week, always marked --}}
                            @php $last = end($points); @endphp
                            <span x-show="i === null" class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" style="left: {{ $last['x'] }}%; top: {{ $last['yd'] }}%; background: var(--c-done)"></span>
                            <span x-show="i === null" class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" style="left: {{ $last['x'] }}%; top: {{ $last['yu'] }}%; background: var(--c-due)"></span>

                            {{-- Hover: guide line, points and the week's numbers --}}
                            <template x-if="i !== null">
                                <div class="pointer-events-none absolute inset-0">
                                    <div class="absolute inset-y-0 border-l border-zinc-300 dark:border-white/20" :style="`left: ${pts[i].x}%`"></div>
                                    <span class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" :style="`left: ${pts[i].x}%; top: ${pts[i].yd}%; background: var(--c-done)`"></span>
                                    <span class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" :style="`left: ${pts[i].x}%; top: ${pts[i].yu}%; background: var(--c-due)`"></span>
                                    <div class="absolute top-1 w-40 rounded-xl bg-white p-2.5 text-xs sm:w-48 sm:p-3 shadow-[0_12px_32px_-10px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10"
                                        :style="pts[i].x > 60 ? `right: ${100 - pts[i].x + 2}%` : `left: ${pts[i].x + 2}%`">
                                        <p class="font-semibold text-zinc-900 dark:text-white" x-text="pts[i].week"></p>
                                        <p class="mt-1.5 flex items-center justify-between gap-2 text-zinc-500"><span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded" style="background: var(--c-done)"></span>{{ __('Finished') }}</span><span class="font-semibold text-zinc-900 tabular-nums dark:text-white" x-text="pts[i].done"></span></p>
                                        <p class="mt-1 flex items-center justify-between gap-2 text-zinc-500"><span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded" style="background: var(--c-due)"></span>{{ __('Due') }}</span><span class="font-semibold text-zinc-900 tabular-nums dark:text-white" x-text="pts[i].due"></span></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="relative mt-2 h-4 text-[0.6875rem] text-zinc-500">
                            @foreach ($points as $p)
                                <span @class(['absolute whitespace-nowrap', '-translate-x-1/2' => ! $loop->first && ! $loop->last, '-translate-x-full font-semibold text-zinc-900 dark:text-white' => $loop->last, 'hidden' => ! $loop->first && ! $loop->last && (! str_contains($p['tick'], ' ') || $p['x'] > 80), 'max-sm:hidden' => ! $loop->first && ! $loop->last]) style="left: {{ $p['x'] }}%">{{ $loop->last ? __('This week') : $p['tick'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="sr-only"><table>
                    <caption>{{ __('My work, last 12 weeks') }}</caption>
                    <tr><th>{{ __('Week') }}</th><th>{{ __('Finished') }}</th><th>{{ __('Due') }}</th></tr>
                    @foreach ($points as $p)<tr><td>{{ $p['week'] }}</td><td>{{ $p['done'] }}</td><td>{{ $p['due'] }}</td></tr>@endforeach
                </table></div>
            </section>
        </div>

        <aside class="order-first flex min-w-0 flex-col gap-6 lg:order-none">
            {{-- My tasks: tick them off here --}}
            <section class="{{ $panel }} h-full overflow-hidden" data-test="my-tasks">
                <div class="flex items-start gap-3 px-4 pt-4 sm:px-5 sm:pt-5">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/25"><flux:icon.clipboard-document-check variant="mini" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('My tasks') }}</h2>
                        <p class="text-xs text-zinc-500">{{ $tasks->count() ? trans_choice(':count open this week|:count open this week', $tasks->count()) : __('Nothing open') }}</p>
                    </div>
                    <a href="{{ route('my.tasks') }}" wire:navigate class="{{ $link }}">{{ __('All') }}</a>
                </div>

                <div class="flex flex-col p-2 pt-3">
                    @forelse ($tasks->take(7) as $t)
                        @php
                            $type = $types[$t->type] ?? Task::unknownType($t->type);
                            $days = $t->due_date ? (int) today()->diffInDays($t->due_date, false) : null;
                            $tone = $days === null ? 'later' : ($days < 0 ? 'late' : ($days <= 1 ? 'soon' : 'later'));
                            $pill = [
                                'late' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
                                'soon' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
                                'later' => 'bg-zinc-50 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
                            ][$tone];
                            $when = $days === null ? __('No date') : ($days < 0 ? trans_choice(':count day late|:count days late', -$days) : ($days === 0 ? __('Due today') : ($days === 1 ? __('Tomorrow') : trans_choice('In :count day|In :count days', $days))));
                        @endphp
                        <div wire:key="t-{{ $t->id }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})" class="-m-2 flex size-10 shrink-0 items-center justify-center rounded-xl sm:m-0 sm:size-5 sm:rounded-md" aria-label="{{ __('Mark as done') }}" data-test="done-{{ $t->id }}">
                                <span class="flex size-6 items-center justify-center rounded-full ring-[1.5px] ring-zinc-300 transition group-hover:ring-emerald-500 active:bg-emerald-50 sm:size-5 sm:rounded-md sm:ring-1 dark:ring-white/20"><flux:icon.check variant="micro" class="size-3.5 text-emerald-600 opacity-0 transition group-hover:opacity-60" /></span>
                            </button>
                            <span class="hidden size-9 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 sm:flex dark:bg-white/5 dark:text-zinc-400"><flux:icon :icon="$type['icon']" variant="micro" class="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $t->title }}</p>
                                <p class="mt-1 flex items-center gap-1.5">
                                    <span class="{{ $pill }} inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset">{{ $when }}</span>
                                    <span class="truncate text-xs text-zinc-500">{{ $t->client?->displayName() ?? __('Team task') }}</span>
                                </p>
                            </div>
                            @if (in_array($t->priority, ['urgent', 'high']))
                                <span class="{{ (Task::PRIORITY_STYLES[$t->priority])['tone'] }} hidden shrink-0 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1 sm:inline">{{ __(Task::PRIORITIES[$t->priority]) }}</span>
                                <flux:icon.flag variant="micro" @class(['size-4 shrink-0 sm:hidden', 'text-orange-500' => $t->priority === 'high', 'text-rose-500' => $t->priority === 'urgent']) />
                            @endif
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 px-5 py-10 text-center">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge class="size-6" /></span>
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('All clear') }}</p>
                            <p class="text-xs text-zinc-500">{{ __('No open tasks for this week.') }}</p>
                        </div>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    {{-- Recently finished: like Recent transactions on the admin overview --}}
    @php
        $th = 'px-3 py-2.5 text-start text-[0.6875rem] font-semibold tracking-wide text-zinc-500 uppercase whitespace-nowrap';
        $recent = $this->recentDone;
    @endphp
    <section class="{{ $panel }} overflow-hidden" data-test="recently-finished">
        <div class="flex items-center gap-3 px-4 py-4 sm:px-5">
            <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-500 text-white shadow-md shadow-emerald-500/25"><flux:icon.check-badge variant="mini" class="size-4.5" /></span>
            <div class="flex-1">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Recently finished') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Your latest finished tasks') }}</p>
            </div>
        </div>

        @if ($recent->isEmpty())
            <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.clipboard-document-check class="size-6" /></span>
                <p class="text-sm text-zinc-500">{{ __('Nothing finished yet.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-zinc-100 border-t border-zinc-100 sm:hidden dark:divide-white/5 dark:border-white/5">
                @foreach ($recent as $t)
                    @php $onTime = ! $t->due_date || $t->completed_at->copy()->startOfDay()->lte($t->due_date); @endphp
                    <li wire:key="rdm-{{ $t->id }}" class="flex items-center gap-3 px-4 py-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check variant="micro" class="size-4" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $t->title }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ $t->client?->displayName() ?? __('Team task') }} · {{ NepaliDate::format($t->completed_at, false) }}</p>
                        </div>
                        <span @class(['shrink-0 rounded-md px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-inset', 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $onTime, 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ! $onTime])>{{ $onTime ? __('On time') : __('Late') }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="hidden overflow-x-auto border-t border-zinc-100 sm:block dark:border-white/5">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                        <tr>
                            <th class="{{ $th }} ps-5">{{ __('Task') }}</th>
                            <th class="{{ $th }}">{{ __('Client') }}</th>
                            <th class="{{ $th }}">{{ __('Type') }}</th>
                            <th class="{{ $th }}">{{ __('Due') }}</th>
                            <th class="{{ $th }}">{{ __('Finished') }}</th>
                            <th class="{{ str_replace('text-start', 'text-end', $th) }} pe-5">{{ __('Result') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($recent as $t)
                            @php
                                $type = $types[$t->type] ?? Task::unknownType($t->type);
                                $onTime = ! $t->due_date || $t->completed_at->copy()->startOfDay()->lte($t->due_date);
                            @endphp
                            <tr wire:key="rd-{{ $t->id }}" class="transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <td class="max-w-80 px-3 py-2.5 ps-5">
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check variant="micro" class="size-4" /></span>
                                        <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $t->title }}</p>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $t->client?->displayName() ?? __('Team task') }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium whitespace-nowrap text-zinc-700 dark:bg-white/10 dark:text-zinc-200"><flux:icon :icon="$type['icon']" variant="micro" class="size-3.5" />{{ $type['label'] }}</span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $t->due_date ? NepaliDate::format($t->due_date) : '—' }}</td>
                                <td class="px-3 py-2.5 font-medium whitespace-nowrap text-zinc-900 dark:text-white">{{ NepaliDate::format($t->completed_at) }}</td>
                                <td class="px-3 py-2.5 pe-5 text-end whitespace-nowrap">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset',
                                        'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $onTime,
                                        'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ! $onTime,
                                    ])>{{ $onTime ? __('On time') : __('Late') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <a href="{{ route('my.tasks') }}" wire:navigate class="group flex items-center justify-center gap-1.5 border-t border-zinc-100 py-3 text-sm font-medium text-brand-600 transition hover:bg-brand-50/60 dark:border-white/5 dark:text-brand-300 dark:hover:bg-brand-500/10">
                {{ __('View all my tasks') }}<flux:icon.arrow-right variant="micro" class="size-4 transition group-hover:translate-x-0.5" />
            </a>
        @endif
    </section>
</div>
@endif
