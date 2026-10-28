<?php

use App\Concerns\ManagesTasks;
use App\Models\Holiday;
use App\Models\Script;
use App\Models\Task;
use App\Models\WorkType;
use App\Support\NepaliDate;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Flux\Flux;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Calendar')] class extends Component {
    use ManagesTasks;

    /** BS month shown, e.g. "2083-07". */
    #[Url(except: '')]
    public string $month = '';

    /** Selected day (AD, Y-m-d). */
    public string $day = '';

    /** @var list<string> Work types switched off. */
    #[Url(except: [])]
    public array $off = [];

    /** Name for a holiday on the selected day (admins). */
    public string $holidayName = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = NepaliDate::period(today());
        }
        $this->day = today()->toDateString();
    }

    /** @return array{0: int, 1: int} */
    private function ym(): array
    {
        return array_map('intval', explode('-', $this->month));
    }

    /** First and last AD day of the BS month shown. */
    private function range(): array
    {
        [$y, $m] = $this->ym();

        return [NepaliDate::toAd($y, $m, 1), NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))];
    }

    public function shift(int $by): void
    {
        [$y, $m] = $this->ym();
        $i = $y * 12 + $m - 1 + $by;
        $this->month = sprintf('%d-%02d', intdiv($i, 12), $i % 12 + 1);
        // Keep the selection inside the month shown.
        $this->day = $this->month === NepaliDate::period(today()) ? today()->toDateString() : $this->range()[0]->toDateString();
    }

    public function goToday(): void
    {
        $this->month = NepaliDate::period(today());
        $this->day = today()->toDateString();
    }

    public function pick(string $date): void
    {
        $d = Carbon::parse($date);
        $this->day = $d->toDateString();
        $this->month = NepaliDate::period($d);
    }

    /** Holidays on the visible grid: date => name. */
    #[Computed]
    public function holidays(): array
    {
        $weeks = $this->weeks;

        return Holiday::whereBetween('date', [$weeks[0][0]->toDateString(), end($weeks)[6]->toDateString()])->get()
            ->mapWithKeys(fn ($h) => [$h->date->toDateString() => $h->name])->all();
    }

    /** Admins: makes the selected day a holiday for everyone. */
    public function markHoliday(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $data = $this->validate(['holidayName' => ['required', 'string', 'min:2', 'max:80']], attributes: ['holidayName' => __('holiday name')]);

        Holiday::updateOrCreate(['date' => Carbon::parse($this->day)->toDateString()], ['name' => trim($data['holidayName'])]);
        $this->reset('holidayName');
        unset($this->holidays);
        Flux::toast(variant: 'success', text: __(':date is now a holiday.', ['date' => NepaliDate::format(Carbon::parse($this->day))]));
    }

    public function removeHoliday(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        Holiday::whereDate('date', Carbon::parse($this->day)->toDateString())->get()->each->delete();
        unset($this->holidays);
        Flux::toast(text: __('Holiday removed.'));
    }

    public function toggleType(string $type): void
    {
        $this->off = in_array($type, $this->off, true) ? array_values(array_diff($this->off, [$type])) : [...$this->off, $type];
    }

    /** Whole weeks (Sunday first) covering the month. */
    #[Computed]
    public function weeks(): array
    {
        [$first, $last] = $this->range();
        $end = $last->copy()->addDays(6 - $last->dayOfWeek);
        $days = [];
        for ($d = $first->copy()->subDays($first->dayOfWeek); $d->lte($end); $d->addDay()) {
            $days[] = $d->copy();
        }

        return array_chunk($days, 7);
    }

    /** Client tasks due between two days: open first, then by priority. */
    private function tasksBetween($from, $to, bool $filtered = true)
    {
        return Task::with(['client', 'assignees'])->whereNotNull('client_id')
            ->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)
            // Admins and Content Creators plan every client; other employee logins see their own work only.
            ->unless(auth()->user()->canPlanContent(), fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey(auth()->user()->employee?->id ?? 0)))
            ->when($filtered && $this->off, fn ($q) => $q->whereNotIn('type', $this->off))
            ->orderByRaw('completed_at is not null')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('id')
            ->get();
    }

    /** Tasks on the visible grid, grouped by day. */
    #[Computed]
    public function byDay(): array
    {
        $weeks = $this->weeks;

        return $this->tasksBetween($weeks[0][0], end($weeks)[6])->groupBy(fn ($t) => $t->due_date->toDateString())->all();
    }

    /** Script shoots on the visible grid, grouped by day. */
    #[Computed]
    public function shootsByDay(): array
    {
        $weeks = $this->weeks;

        return Script::with('client')->whereDate('shoot_date', '>=', $weeks[0][0])->whereDate('shoot_date', '<=', end($weeks)[6])
            ->orderBy('title')->get()->groupBy(fn ($s) => $s->shoot_date->toDateString())->all();
    }

    /** This month at a glance (ignores the filters). */
    #[Computed]
    public function stats(): array
    {
        [$first, $last] = $this->range();
        $tasks = $this->tasksBetween($first, $last, false);

        return [
            'total' => $tasks->count(),
            'done' => $tasks->whereNotNull('completed_at')->count(),
            'overdue' => $tasks->filter->isOverdue()->count(),
            'byType' => $tasks->countBy('type')->all(),
            'shoots' => Script::whereDate('shoot_date', '>=', $first)->whereDate('shoot_date', '<=', $last)->count(),
        ];
    }

    public function needsClientAndDate(): bool
    {
        return true;
    }

    protected function taskSaved(Task $task): void
    {
        $this->pick($task->due_date->toDateString());
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $types = $this->types;
    $typeOf = fn (?string $slug) => $types[$slug] ?? Task::unknownType($slug);
    $toneOf = fn (?string $slug) => WorkType::PALETTE[$typeOf($slug)['color']] ?? WorkType::PALETTE['zinc'];
    [$y, $m] = array_map('intval', explode('-', $month));
    $byDay = $this->byDay;
    $stats = $this->stats;
    $selected = Carbon::parse($day);
    $dayTasks = collect($byDay[$day] ?? []);
    $shootsByDay = $this->shootsByDay;
    $dayShoots = collect($shootsByDay[$day] ?? []);
    $weeks = $this->weeks;
    $weekdays = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $bsDay = fn ($d) => NepaliDate::fromAd($d)[2] ?? $d->day;
    $holidays = $this->holidays;
    $dayHoliday = $holidays[$day] ?? null;
    $isAdmin = auth()->user()->isAdmin();
@endphp

<div class="stagger flex w-full flex-col gap-4 sm:gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Calendar') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ auth()->user()->canPlanContent() ? __('Every client task on its due date. Plan the month and add work as it comes.') : __('Your tasks and every shoot, on their day.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newTask('{{ $day }}')" class="shadow-lg shadow-brand-500/25" data-test="new-task">{{ __('Add task') }}</flux:button>
    </header>

    {{-- Month at a glance --}}
    <section class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" data-test="calendar-stats">
        @foreach ([
            [__('Tasks this month'), $stats['total'], 'clipboard-document-list', 'bg-brand-500 shadow-brand-500/30'],
            [__('Done'), $stats['done'].($stats['total'] ? ' · '.round($stats['done'] / $stats['total'] * 100).'%' : ''), 'check-badge', 'bg-emerald-500 shadow-emerald-500/30'],
            [__('Overdue'), $stats['overdue'], 'exclamation-triangle', 'bg-rose-500 shadow-rose-500/30'],
            [__('Shoots this month'), $stats['shoots'], 'video-camera', 'bg-violet-500 shadow-violet-500/30'],
        ] as [$label, $value, $icon, $tile])
            <div class="{{ $panel }} flex min-w-0 items-center gap-3 p-3 sm:gap-4 sm:p-4">
                <span class="{{ $tile }} flex size-9 shrink-0 items-center justify-center rounded-xl text-white shadow-md sm:size-11"><flux:icon :icon="$icon" variant="mini" class="size-4.5 sm:size-5" /></span>
                <div class="min-w-0">
                    <p class="truncate text-xs text-zinc-500 sm:text-sm">{{ $label }}</p>
                    <p class="text-xl leading-tight font-bold text-zinc-900 tabular-nums sm:text-2xl dark:text-white">{{ $value }}</p>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Work type filters --}}
    @if ($types)
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0">
            @foreach ($types as $slug => $t)
                @php $on = ! in_array($slug, $off, true); @endphp
                <button type="button" wire:click="toggleType('{{ $slug }}')" @class([
                    'inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition select-none',
                    'bg-white text-zinc-700 ring-zinc-200 shadow-xs hover:ring-zinc-300 dark:bg-zinc-950 dark:text-zinc-200 dark:ring-white/10' => $on,
                    'text-zinc-400 ring-zinc-200/60 line-through dark:ring-white/5' => ! $on,
                ])>
                    <span @class(['size-2 rounded-full', $toneOf($slug)['dot'] => $on, 'bg-zinc-300 dark:bg-zinc-600' => ! $on])></span>
                    {{ $t['label'] }}
                    <span class="rounded-full bg-zinc-100 px-1.5 text-[0.625rem] font-semibold text-zinc-500 tabular-nums dark:bg-white/10">{{ $stats['byType'][$slug] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
    @endif

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- Month --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5 sm:px-5 sm:py-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight text-zinc-900 sm:text-xl dark:text-white" data-test="month-title">{{ NepaliDate::MONTHS[$m - 1] }} {{ $y }}</h2>
                    <p class="text-xs text-zinc-500">{{ $weeks[0][0]->format('M') }} – {{ end($weeks)[6]->format('M Y') }}</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <flux:button size="sm" wire:click="goToday">{{ __('Today') }}</flux:button>
                    <flux:button size="sm" icon="chevron-left" wire:click="shift(-1)" :aria-label="__('Previous month')" />
                    <flux:button size="sm" icon="chevron-right" wire:click="shift(1)" :aria-label="__('Next month')" />
                </div>
            </div>

            <div class="sm:overflow-x-auto">
                <div class="sm:min-w-[44rem]">
                    <div class="grid grid-cols-7 border-y border-zinc-100 bg-zinc-50/80 dark:border-white/5 dark:bg-white/[0.02]">
                        @foreach ($weekdays as $i => $w)
                            <p @class(['py-2 text-center text-[0.6875rem] font-semibold tracking-wide uppercase sm:px-3 sm:text-start sm:text-xs', 'text-rose-500' => $i === 6, 'text-zinc-500' => $i !== 6])><span class="sm:hidden">{{ mb_substr($w, 0, 1) }}</span><span class="max-sm:hidden">{{ $w }}</span></p>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7">
                        @foreach ($weeks as $week)
                            @foreach ($week as $d)
                                @php
                                    $key = $d->toDateString();
                                    $items = collect($byDay[$key] ?? []);
                                    $inMonth = NepaliDate::period($d) === $month;
                                    $isSel = $key === $day;
                                    $holiday = $holidays[$key] ?? null;
                                @endphp
                                <div wire:key="d-{{ $key }}" wire:click="pick('{{ $key }}')" @class([
                                    'group relative flex min-h-14 cursor-pointer flex-col items-center gap-1 border-e border-b border-zinc-100 p-1 transition select-none sm:min-h-28 sm:items-stretch sm:p-1.5 dark:border-white/5',
                                    'bg-zinc-50/60 dark:bg-white/[0.015]' => ! $inMonth && ! $holiday,
                                    'bg-rose-50/70 dark:bg-rose-500/[0.06]' => $holiday && ! $isSel,
                                    'hover:bg-brand-50/40 dark:hover:bg-brand-500/5' => ! $isSel,
                                    'bg-brand-50/70 ring-2 ring-brand-500 ring-inset dark:bg-brand-500/10' => $isSel,
                                ])>
                                    <div class="flex items-center justify-center sm:justify-between sm:px-1">
                                        <span @class([
                                            'flex size-7 items-center justify-center rounded-full text-[0.8125rem] font-semibold tabular-nums sm:text-sm',
                                            'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $d->isToday(),
                                            'text-rose-600 dark:text-rose-400' => ! $d->isToday() && $holiday,
                                            'text-zinc-900 dark:text-white' => ! $d->isToday() && ! $holiday && $inMonth,
                                            'text-zinc-400' => ! $d->isToday() && ! $holiday && ! $inMonth,
                                        ])>{{ $bsDay($d) }}</span>
                                        <span class="text-[0.625rem] text-zinc-400 tabular-nums max-sm:hidden">{{ $d->day === 1 ? $d->format('j M') : $d->day }}</span>
                                    </div>
                                    {{-- Phones: a dot per shoot and task instead of the labels --}}
                                    @if ($holiday || $items->isNotEmpty() || ! empty($shootsByDay[$key]))
                                        <div class="flex flex-wrap justify-center gap-0.5 sm:hidden" aria-hidden="true">
                                            @if ($holiday)<span class="size-1.5 rounded-full bg-rose-500"></span>@endif
                                            @foreach ($shootsByDay[$key] ?? [] as $s)<span class="size-1.5 rounded-full bg-violet-500"></span>@endforeach
                                            @foreach ($items->take(4) as $t)<span class="{{ $t->completed_at ? 'bg-zinc-300 dark:bg-zinc-600' : ($t->isOverdue() ? 'bg-rose-500' : $toneOf($t->type)['dot']) }} size-1.5 rounded-full"></span>@endforeach
                                        </div>
                                    @endif
                                    @if ($holiday)
                                        <span class="flex items-center gap-1 truncate rounded-md bg-rose-500 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-white shadow-sm shadow-rose-500/30 max-sm:hidden" title="{{ $holiday }}" data-test="holiday-{{ $key }}"><flux:icon.sparkles variant="micro" class="size-3 shrink-0" /><span class="truncate">{{ $holiday }}</span></span>
                                    @endif
                                    @foreach ($shootsByDay[$key] ?? [] as $s)
                                        <a href="{{ route('scripts.edit', $s) }}" wire:navigate @click.stop class="flex items-center gap-1 truncate rounded-md bg-violet-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-violet-700 ring-1 ring-violet-200 hover:bg-violet-100 max-sm:hidden dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20" title="{{ $s->client?->displayName() }}" data-test="shoot-{{ $s->id }}">
                                            <flux:icon.video-camera variant="micro" class="size-3 shrink-0" /><span class="truncate">{{ __('Shoot: :title', ['title' => $s->title]) }}</span>
                                        </a>
                                    @endforeach
                                    @foreach ($items->take(3) as $t)
                                        <span @class([
                                            'flex items-center gap-1 truncate rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1 max-sm:hidden',
                                            $toneOf($t->type)['chip'] => ! $t->completed_at,
                                            'bg-zinc-50 text-zinc-400 ring-zinc-200/70 line-through dark:bg-white/5 dark:ring-white/5' => $t->completed_at,
                                        ])>
                                            <span class="{{ $t->isOverdue() ? 'bg-rose-500' : $toneOf($t->type)['dot'] }} size-1.5 shrink-0 rounded-full"></span>
                                            <span class="truncate">{{ $t->title }}</span>
                                        </span>
                                    @endforeach
                                    @if ($items->count() > 3)
                                        <span class="px-1.5 text-[0.6875rem] font-medium text-zinc-500 max-sm:hidden">+{{ $items->count() - 3 }} {{ __('more') }}</span>
                                    @endif
                                    <button type="button" wire:click.stop="newTask('{{ $key }}')" class="absolute right-1.5 bottom-1.5 hidden size-6 max-sm:hidden! items-center justify-center rounded-md bg-white text-zinc-500 shadow-sm ring-1 ring-zinc-200 group-hover:flex hover:text-brand-600 dark:bg-zinc-900 dark:ring-white/10" aria-label="{{ __('Add task on this day') }}"><flux:icon.plus variant="micro" class="size-3.5" /></button>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <aside class="flex flex-col gap-6">
            {{-- Selected day --}}
            <section class="{{ $panel }} overflow-hidden">
                <div @class(['relative overflow-hidden bg-linear-to-br px-5 py-4 text-white', 'from-brand-500 to-violet-600' => ! $dayHoliday, 'from-rose-500 to-orange-500' => $dayHoliday])>
                    <div class="pointer-events-none absolute -top-8 -right-8 size-28 rounded-full bg-white/10"></div>
                    <p class="text-xs font-medium text-white/75">{{ $selected->isToday() ? __('Today') : $selected->format('l') }}</p>
                    <p class="text-2xl font-bold tracking-tight" data-test="day-title">{{ NepaliDate::format($selected) }}</p>
                    <p class="text-xs text-white/75">{{ $selected->format('l, j F Y') }} · {{ trans_choice(':count task|:count tasks', $dayTasks->count()) }}</p>
                    @if ($dayHoliday)
                        <p class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-white/20 px-2.5 py-1 text-xs font-semibold ring-1 ring-white/25"><flux:icon.sparkles variant="micro" class="size-3.5" />{{ __('Holiday: :name', ['name' => $dayHoliday]) }}</p>
                    @endif
                </div>
                @if ($isAdmin)
                    <div class="border-b border-zinc-100 p-3 dark:border-white/5" data-test="holiday-box">
                        @if ($dayHoliday)
                            <div class="flex items-center gap-2 rounded-lg bg-rose-50 px-3 py-2 dark:bg-rose-500/10">
                                <flux:icon.sparkles variant="micro" class="size-4 shrink-0 text-rose-500" />
                                <p class="min-w-0 flex-1 text-xs text-rose-800 dark:text-rose-200">{{ __('Off for everyone. Attendance and leave skip this day.') }}</p>
                                <flux:button size="xs" variant="ghost" class="text-rose-600!" wire:click="removeHoliday" wire:confirm="{{ __('Remove the holiday :name?', ['name' => $dayHoliday]) }}" data-test="remove-holiday">{{ __('Remove') }}</flux:button>
                            </div>
                        @elseif ($selected->isHoliday())
                            <p class="flex items-center gap-2 px-1 text-xs text-zinc-500"><flux:icon.sun variant="micro" class="size-4 text-rose-400" />{{ __('Weekly holiday') }}</p>
                        @else
                            <form wire:submit="markHoliday" class="flex items-start gap-2" x-data="{ open: false }">
                                <button type="button" x-show="! open" @click="open = true; $nextTick(() => $refs.name.focus())" class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-rose-600 ring-1 ring-rose-200 transition hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-500/30 dark:hover:bg-rose-500/10" data-test="mark-holiday"><flux:icon.sparkles variant="micro" class="size-3.5" />{{ __('Mark as holiday') }}</button>
                                <div x-show="open" x-cloak class="flex w-full flex-col gap-2">
                                    <div class="flex gap-2">
                                        <input x-ref="name" wire:model="holidayName" type="text" maxlength="80" placeholder="{{ __('e.g. Dashain, Tihar, Holi') }}" @keydown.escape="open = false" class="h-8 min-w-0 flex-1 rounded-lg border-0 bg-white px-2.5 text-sm ring-1 ring-zinc-200 placeholder:text-zinc-400 focus:ring-2 focus:ring-rose-500 focus:outline-none dark:bg-white/5 dark:text-white dark:ring-white/10" data-test="holiday-name" />
                                        <button type="submit" class="inline-flex h-8 items-center gap-1 rounded-lg bg-rose-500 px-2.5 text-xs font-semibold text-white transition hover:bg-rose-600" data-test="save-holiday"><flux:icon.check variant="micro" class="size-3.5" />{{ __('Save') }}</button>
                                    </div>
                                    <flux:error name="holidayName" />
                                </div>
                            </form>
                        @endif
                    </div>
                @endif
                <div class="flex flex-col gap-1 p-3">
                    @foreach ($dayShoots as $s)
                        <a href="{{ route('scripts.edit', $s) }}" wire:navigate wire:key="sh-{{ $s->id }}" class="group flex items-center gap-3 rounded-lg bg-violet-50/70 p-2 transition hover:bg-violet-50 dark:bg-violet-500/10">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-violet-600 text-white"><flux:icon.video-camera variant="micro" class="size-4" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-zinc-900 group-hover:text-violet-700 dark:text-white">{{ __('Shoot: :title', ['title' => $s->title]) }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $s->client?->displayName() ?? __('Other scripts') }} · {{ __(Script::STATUSES[$s->status]['label'] ?? $s->status) }}</span>
                            </span>
                            <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-violet-300 group-hover:text-violet-600" />
                        </a>
                    @endforeach
                    @if ($dayShoots->isNotEmpty() && $dayTasks->isEmpty())
                        <p class="px-2 pt-2 text-xs text-zinc-400">{{ __('No tasks due on this day.') }}</p>
                    @else
                    @forelse ($dayTasks as $t)
                        @php $type = $typeOf($t->type); @endphp
                        <div wire:key="t-{{ $t->id }}" class="group flex items-start gap-3 rounded-lg p-2 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})" @class([
                                'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md ring-1 transition',
                                'bg-emerald-500 text-white ring-emerald-500' => $t->completed_at,
                                'ring-zinc-300 hover:ring-brand-500 dark:ring-white/20' => ! $t->completed_at,
                            ]) aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}">
                                @if ($t->completed_at)<flux:icon.check variant="micro" class="size-3.5" />@endif
                            </button>
                            <div class="min-w-0 flex-1">
                                <p @class(['text-sm font-semibold', 'text-zinc-400 line-through' => $t->completed_at, 'text-zinc-900 dark:text-white' => ! $t->completed_at])>{{ $t->title }}</p>
                                <a href="{{ route('clients.show', $t->client) }}" wire:navigate class="block truncate text-xs text-zinc-500 hover:text-brand-600">{{ $t->client->displayName() }}</a>
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    <span class="{{ $toneOf($t->type)['chip'] }} inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1"><flux:icon :icon="$type['icon']" variant="micro" class="size-3" />{{ $type['label'] }}</span>
                                    <span class="{{ (Task::PRIORITY_STYLES[$t->priority] ?? Task::PRIORITY_STYLES['medium'])['tone'] }} rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1">{{ __(Task::PRIORITIES[$t->priority] ?? 'Medium') }}</span>
                                    @if ($t->isOverdue())<span class="text-[0.6875rem] font-semibold text-rose-600 dark:text-rose-400">{{ __('Overdue') }}</span>@endif
                                    @if ($t->assignees->isNotEmpty())
                                        <span class="flex -space-x-1.5">
                                            @foreach ($t->assignees->take(3) as $a)
                                                <span title="{{ $a->name }}" class="flex size-5 items-center justify-center rounded-full bg-brand-100 text-[0.5625rem] font-bold text-brand-700 ring-2 ring-white dark:bg-brand-500/20 dark:text-brand-200 dark:ring-zinc-950">{{ $a->initials() }}</span>
                                            @endforeach
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex shrink-0 gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editTask({{ $t->id }})" :aria-label="__('Edit')" />
                                @if (auth()->user()->canPlanContent())<flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $t->id }})" :aria-label="__('Delete')" class="text-rose-600!" />@endif
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 py-6 text-center">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-white/5"><flux:icon.calendar class="size-6" /></span>
                            <p class="text-sm text-zinc-500">{{ __('No tasks due on this day.') }}</p>
                        </div>
                    @endforelse
                    @endif
                    <flux:button icon="plus" size="sm" wire:click="newTask('{{ $day }}')" class="mt-1 w-full">{{ __('Add task on this day') }}</flux:button>
                </div>
            </section>
        </aside>
    </div>

    @include('partials.task-form')
</div>
