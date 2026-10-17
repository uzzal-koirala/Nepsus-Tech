<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Task;
use App\Models\WorkType;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Calendar')] class extends Component {
    /** BS month shown, e.g. "2083-07". */
    #[Url(except: '')]
    public string $month = '';

    /** Selected day (AD, Y-m-d). */
    public string $day = '';

    /** @var list<string> Work types switched off. */
    #[Url(except: [])]
    public array $off = [];

    public ?int $editingId = null;

    /** @var array<string, mixed> Add / edit task. */
    public array $task = [];

    public ?int $deletingId = null;

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = NepaliDate::period(today());
        }
        $this->day = today()->toDateString();
        $this->task = $this->blankTask();
    }

    private function blankTask(?string $date = null): array
    {
        return ['client_id' => '', 'title' => '', 'type' => array_key_first($this->types) ?? '', 'priority' => 'medium', 'status' => 'todo', 'due_date' => $date ?? today()->toDateString(), 'description' => '', 'assignees' => []];
    }

    #[Computed]
    public function types(): array
    {
        return Task::types();
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
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
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
        ];
    }

    #[Computed]
    public function upcoming()
    {
        return Task::with('client')->whereNotNull('client_id')->whereNull('completed_at')
            ->whereBetween('due_date', [today()->addDay()->toDateString(), today()->addDays(7)->toDateString()])
            ->when($this->off, fn ($q) => $q->whereNotIn('type', $this->off))
            ->orderBy('due_date')->take(8)->get();
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    public function newTask(?string $date = null): void
    {
        $this->editingId = null;
        $this->task = $this->blankTask($date ?: $this->day);
        $this->resetErrorBag();
        Flux::modal('task-form')->show();
    }

    public function editTask(int $id): void
    {
        $t = Task::with('assignees')->whereNotNull('client_id')->findOrFail($id);
        $this->editingId = $t->id;
        $this->task = ['client_id' => (string) $t->client_id, 'title' => $t->title, 'type' => $t->type, 'priority' => $t->priority, 'status' => $t->stage(), 'due_date' => (string) $t->due_date?->toDateString(), 'description' => (string) $t->description, 'assignees' => $t->assignees->pluck('id')->map('strval')->all()];
        $this->resetErrorBag();
        Flux::modal('task-form')->show();
    }

    public function saveTask(): void
    {
        $data = $this->validate([
            'task.client_id' => ['required', 'exists:clients,id'],
            'task.title' => ['required', 'string', 'max:255'],
            'task.type' => ['required', Rule::exists('work_types', 'slug')],
            'task.priority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
            'task.status' => ['required', Rule::in(array_keys(Task::STATUSES))],
            'task.due_date' => ['required', 'date'],
            'task.description' => ['nullable', 'string', 'max:2000'],
            'task.assignees' => ['array'],
            'task.assignees.*' => ['integer', Rule::exists('employees', 'id')],
        ], attributes: ['task.client_id' => __('client'), 'task.title' => __('task'), 'task.due_date' => __('due date'), 'task.type' => __('work type')])['task'];

        $t = $this->editingId ? Task::findOrFail($this->editingId) : new Task;
        $t->client_id = $data['client_id'];
        $t->fill([
            'title' => $data['title'], 'type' => $data['type'], 'priority' => $data['priority'], 'status' => $data['status'],
            'due_date' => $data['due_date'], 'description' => $data['description'] ?: null,
            'completed_at' => $data['status'] === 'done' ? ($t->completed_at ?? now()) : null,
        ])->save();
        $t->assignees()->sync($data['assignees'] ?? []);

        $this->pick($data['due_date']);
        Flux::modal('task-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Task updated.') : __('Task added.'));
    }

    public function toggleDone(int $id): void
    {
        $t = Task::whereNotNull('client_id')->findOrFail($id);
        $t->update($t->completed_at ? ['completed_at' => null, 'status' => 'todo'] : ['completed_at' => now(), 'status' => 'done']);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('task-delete')->show();
    }

    public function deleteTask(): void
    {
        Task::whereNotNull('client_id')->whereKey($this->deletingId)->delete();
        $this->deletingId = null;
        Flux::modal('task-delete')->close();
        Flux::toast(text: __('Task deleted.'));
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
    $weeks = $this->weeks;
    $weekdays = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $bsDay = fn ($d) => NepaliDate::fromAd($d)[2] ?? $d->day;
    $priorityTones = [
        'urgent' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'high' => 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
        'medium' => 'bg-zinc-50 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
        'low' => 'bg-zinc-50 text-zinc-500 ring-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10',
    ];
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Calendar') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Every client task on its due date. Plan the month and add work as it comes.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newTask" class="shadow-lg shadow-brand-500/25" data-test="new-task">{{ __('Add task') }}</flux:button>
    </header>

    {{-- Month at a glance --}}
    <section class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            [__('Tasks this month'), $stats['total'], 'clipboard-document-list', 'bg-brand-500'],
            [__('Done'), $stats['done'].($stats['total'] ? ' · '.round($stats['done'] / $stats['total'] * 100).'%' : ''), 'check-badge', 'bg-emerald-500'],
            [__('Overdue'), $stats['overdue'], 'exclamation-triangle', 'bg-rose-500'],
        ] as [$label, $value, $icon, $tile])
            <div class="{{ $panel }} flex items-center gap-4 p-4">
                <span class="{{ $tile }} flex size-11 items-center justify-center rounded-xl text-white shadow-md"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                <div>
                    <p class="text-sm text-zinc-500">{{ $label }}</p>
                    <p class="text-2xl leading-tight font-bold text-zinc-900 tabular-nums dark:text-white">{{ $value }}</p>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Work type filters --}}
    @if ($types)
        <div class="flex flex-wrap gap-2">
            @foreach ($types as $slug => $t)
                @php $on = ! in_array($slug, $off, true); @endphp
                <button type="button" wire:click="toggleType('{{ $slug }}')" @class([
                    'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition',
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
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-white" data-test="month-title">{{ NepaliDate::MONTHS[$m - 1] }} {{ $y }}</h2>
                    <p class="text-xs text-zinc-500">{{ $weeks[0][0]->format('M') }} – {{ end($weeks)[6]->format('M Y') }}</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <flux:button size="sm" wire:click="goToday">{{ __('Today') }}</flux:button>
                    <flux:button size="sm" icon="chevron-left" wire:click="shift(-1)" :aria-label="__('Previous month')" />
                    <flux:button size="sm" icon="chevron-right" wire:click="shift(1)" :aria-label="__('Next month')" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-[44rem]">
                    <div class="grid grid-cols-7 border-y border-zinc-100 bg-zinc-50/80 dark:border-white/5 dark:bg-white/[0.02]">
                        @foreach ($weekdays as $i => $w)
                            <p @class(['px-3 py-2 text-xs font-semibold tracking-wide uppercase', 'text-rose-500' => $i === 6, 'text-zinc-500' => $i !== 6])>{{ $w }}</p>
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
                                @endphp
                                <div wire:key="d-{{ $key }}" wire:click="pick('{{ $key }}')" @class([
                                    'group relative flex min-h-28 cursor-pointer flex-col gap-1 border-e border-b border-zinc-100 p-1.5 transition dark:border-white/5',
                                    'bg-zinc-50/60 dark:bg-white/[0.015]' => ! $inMonth,
                                    'hover:bg-brand-50/40 dark:hover:bg-brand-500/5' => ! $isSel,
                                    'bg-brand-50/70 ring-2 ring-brand-500 ring-inset dark:bg-brand-500/10' => $isSel,
                                ])>
                                    <div class="flex items-center justify-between px-1">
                                        <span @class([
                                            'flex size-7 items-center justify-center rounded-full text-sm font-semibold tabular-nums',
                                            'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $d->isToday(),
                                            'text-zinc-900 dark:text-white' => ! $d->isToday() && $inMonth,
                                            'text-zinc-400' => ! $d->isToday() && ! $inMonth,
                                        ])>{{ $bsDay($d) }}</span>
                                        <span class="text-[0.625rem] text-zinc-400 tabular-nums">{{ $d->day === 1 ? $d->format('j M') : $d->day }}</span>
                                    </div>
                                    @foreach ($items->take(3) as $t)
                                        <span @class([
                                            'flex items-center gap-1 truncate rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1',
                                            $toneOf($t->type)['chip'] => ! $t->completed_at,
                                            'bg-zinc-50 text-zinc-400 ring-zinc-200/70 line-through dark:bg-white/5 dark:ring-white/5' => $t->completed_at,
                                        ])>
                                            <span class="{{ $t->isOverdue() ? 'bg-rose-500' : $toneOf($t->type)['dot'] }} size-1.5 shrink-0 rounded-full"></span>
                                            <span class="truncate">{{ $t->title }}</span>
                                        </span>
                                    @endforeach
                                    @if ($items->count() > 3)
                                        <span class="px-1.5 text-[0.6875rem] font-medium text-zinc-500">+{{ $items->count() - 3 }} {{ __('more') }}</span>
                                    @endif
                                    <button type="button" wire:click.stop="newTask('{{ $key }}')" class="absolute right-1.5 bottom-1.5 hidden size-6 items-center justify-center rounded-md bg-white text-zinc-500 shadow-sm ring-1 ring-zinc-200 group-hover:flex hover:text-brand-600 dark:bg-zinc-900 dark:ring-white/10" aria-label="{{ __('Add task on this day') }}"><flux:icon.plus variant="micro" class="size-3.5" /></button>
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
                <div class="relative overflow-hidden bg-linear-to-br from-brand-500 to-violet-600 px-5 py-4 text-white">
                    <div class="pointer-events-none absolute -top-8 -right-8 size-28 rounded-full bg-white/10"></div>
                    <p class="text-xs font-medium text-white/75">{{ $selected->isToday() ? __('Today') : $selected->format('l') }}</p>
                    <p class="text-2xl font-bold tracking-tight" data-test="day-title">{{ NepaliDate::format($selected) }}</p>
                    <p class="text-xs text-white/75">{{ $selected->format('l, j F Y') }} · {{ trans_choice(':count task|:count tasks', $dayTasks->count()) }}</p>
                </div>
                <div class="flex flex-col gap-1 p-3">
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
                                    <span class="{{ $priorityTones[$t->priority] ?? $priorityTones['medium'] }} rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1">{{ __(Task::PRIORITIES[$t->priority] ?? 'Medium') }}</span>
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
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $t->id }})" :aria-label="__('Delete')" class="text-rose-600!" />
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 py-6 text-center">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-white/5"><flux:icon.calendar class="size-6" /></span>
                            <p class="text-sm text-zinc-500">{{ __('No tasks due on this day.') }}</p>
                        </div>
                    @endforelse
                    <flux:button icon="plus" size="sm" wire:click="newTask('{{ $day }}')" class="mt-1 w-full">{{ __('Add task on this day') }}</flux:button>
                </div>
            </section>

            {{-- Next 7 days --}}
            <section class="{{ $panel }} p-5">
                <p class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><flux:icon.sparkles variant="mini" class="size-5 text-brand-500" />{{ __('Coming up') }}<span class="text-xs font-normal text-zinc-500">{{ __('next 7 days') }}</span></p>
                <div class="mt-3 flex flex-col divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse ($this->upcoming as $t)
                        @php $bs = NepaliDate::fromAd($t->due_date); @endphp
                        <button type="button" wire:click="pick('{{ $t->due_date->toDateString() }}')" class="flex items-center gap-3 py-2.5 text-start">
                            <span class="flex w-11 shrink-0 flex-col items-center rounded-lg bg-zinc-50 py-1 dark:bg-white/5">
                                <span class="text-[0.625rem] font-semibold text-zinc-500 uppercase">{{ $bs ? mb_substr(NepaliDate::MONTHS[$bs[1] - 1], 0, 3) : $t->due_date->format('M') }}</span>
                                <span class="text-base leading-none font-bold text-zinc-900 dark:text-white">{{ $bs[2] ?? $t->due_date->day }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $t->title }}</span>
                                <span class="flex items-center gap-1.5 truncate text-xs text-zinc-500"><span class="{{ $toneOf($t->type)['dot'] }} size-1.5 shrink-0 rounded-full"></span>{{ $t->client->displayName() }}</span>
                            </span>
                        </button>
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500">{{ __('A quiet week ahead.') }}</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    {{-- Add / edit task --}}
    <flux:modal name="task-form" class="w-full max-w-2xl overflow-visible!">
        <form wire:submit="saveTask" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.clipboard-document-check variant="mini" class="size-5" /></span>
                <flux:heading size="lg">{{ $editingId ? __('Edit task') : __('Add task') }}</flux:heading>
            </div>
            @php
                $clientOptions = $this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->company ? $c->name : $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']);
                $typeOptions = collect($types)->map(fn ($t, $slug) => ['value' => $slug, 'label' => $t['label'], 'icon' => $t['icon'], 'tile' => $toneOf($slug)['chip']])->values();
                $priorityIcons = ['low' => 'arrow-down', 'medium' => 'minus', 'high' => 'arrow-up', 'urgent' => 'fire'];
                $priorityOptions = collect(Task::PRIORITIES)->map(fn ($label, $k) => ['value' => $k, 'label' => __($label), 'icon' => $priorityIcons[$k] ?? 'minus', 'tile' => $priorityTones[$k]])->values();
                $statusOptions = collect(Task::STATUSES)->map(fn ($s, $k) => ['value' => $k, 'label' => __($s['label']), 'icon' => $s['icon']])->values();
                $employees = Employee::where('status', '!=', 'inactive')->orderBy('name')->get();
            @endphp
            <div class="grid gap-4 sm:grid-cols-2">
                <x-rich-select model="task.client_id" class="sm:col-span-2" :label="__('Client')" :placeholder="__('Pick a client')" :options="$clientOptions" searchable />
                <div class="sm:col-span-2"><flux:input wire:model="task.title" :label="__('Task')" :placeholder="__('e.g. Design 4 posts for Dashain')" required data-test="task-title" /></div>
                <x-rich-select model="task.type" :label="__('Work type')" :options="$typeOptions" :searchable="count($types) > 6" />
                <x-nepali-date wire:model="task.due_date" :label="__('Due date')" />
                <x-rich-select model="task.priority" :label="__('Priority')" :options="$priorityOptions" />
                <x-rich-select model="task.status" :label="__('Status')" :options="$statusOptions" />
                @if ($employees->isNotEmpty())
                    <div class="sm:col-span-2">
                        <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Assigned to') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($employees as $e)
                                <label wire:key="as-{{ $e->id }}" class="flex cursor-pointer items-center gap-2 rounded-full py-1 ps-1 pe-3 text-sm ring-1 ring-zinc-200 transition has-checked:bg-brand-50 has-checked:text-brand-700 has-checked:ring-brand-500 dark:ring-white/10 dark:has-checked:bg-brand-500/10 dark:has-checked:text-brand-200">
                                    <input type="checkbox" wire:model="task.assignees" value="{{ $e->id }}" class="sr-only">
                                    <span class="flex size-6 items-center justify-center rounded-full bg-zinc-100 text-[0.625rem] font-bold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ $e->initials() }}</span>
                                    {{ $e->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="sm:col-span-2"><flux:textarea wire:model="task.description" :label="__('Details')" rows="2" :placeholder="__('Brief, links, captions...')" /></div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-task">{{ __('Save task') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="task-delete" class="w-full max-w-sm">
        <div class="flex flex-col items-center gap-4 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.trash class="size-7" /></span>
            <div>
                <flux:heading size="lg">{{ __('Delete this task?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('It will be removed from the client too.') }}</flux:text>
            </div>
            <div class="flex w-full gap-2">
                <flux:modal.close><flux:button variant="ghost" class="flex-1">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteTask" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
