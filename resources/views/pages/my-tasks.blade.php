<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Task;
use App\Models\WorkType;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Employee logins: the tasks assigned to them. They can add, edit and move their work; only admins delete. */
new #[Title('My tasks')] class extends Component {
    /** open | today | late | upcoming | done */
    #[Url(except: 'open')]
    public string $tab = 'open';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $client = '';

    public ?int $viewingId = null;

    // Task form
    public ?int $editingId = null;

    public string $fTitle = '';

    public string $fDescription = '';

    public string $fClient = '';

    public string $fType = '';

    public string $fStatus = 'todo';

    public string $fPriority = 'medium';

    public string $fStart = '';

    public string $fDue = '';

    /** @var list<int> */
    public array $fAssignees = [];

    private function meId(): int
    {
        return auth()->user()->employee?->id ?? 0;
    }

    private function mine()
    {
        return Task::with(['client', 'assignees'])->whereHas('assignees', fn ($q) => $q->whereKey($this->meId()));
    }

    #[Computed]
    public function open()
    {
        return $this->mine()->whereNull('completed_at')
            ->when($this->client !== '', fn ($q) => $q->where('client_id', (int) $this->client))
            ->orderByRaw('due_date is null')->orderBy('due_date')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->latest('id')->get();
    }

    #[Computed]
    public function done()
    {
        return $this->mine()->whereNotNull('completed_at')
            ->when($this->client !== '', fn ($q) => $q->where('client_id', (int) $this->client))
            ->latest('completed_at')->limit(60)->get();
    }

    /** This week (Sunday on): what fell due or got done, for the progress ring. */
    #[Computed]
    public function week(): array
    {
        $start = today()->startOfWeek(\Carbon\CarbonInterface::SUNDAY);
        $tasks = $this->mine()->where(fn ($q) => $q->where('completed_at', '>=', $start)
            ->orWhere(fn ($w) => $w->whereDate('due_date', '>=', $start)->whereDate('due_date', '<=', $start->copy()->addDays(6))))->get(['tasks.id', 'completed_at']);

        return ['total' => $tasks->count(), 'done' => $tasks->whereNotNull('completed_at')->count()];
    }

    /** Clients I have work for, for the filter. */
    #[Computed]
    public function myClients()
    {
        return Client::whereHas('tasks', fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($this->meId())))->orderBy('company')->orderBy('name')->get();
    }

    #[Computed]
    public function clients()
    {
        return Client::where('status', 'active')->orderBy('company')->orderBy('name')->get();
    }

    #[Computed]
    public function team()
    {
        return Employee::where('status', '!=', 'inactive')->orderBy('name')->get();
    }

    #[Computed]
    public function viewing(): ?Task
    {
        return $this->viewingId ? $this->mine()->find($this->viewingId) : null;
    }

    private function refresh(): void
    {
        unset($this->open, $this->done, $this->week, $this->viewing, $this->myClients);
    }

    public function openTask(int $id): void
    {
        $this->viewingId = $this->mine()->findOrFail($id)->id;
        Flux::modal('my-task')->show();
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, Task::STATUSES), 422);
        $t = $this->mine()->findOrFail($id);
        $t->update(['status' => $status, 'completed_at' => $status === 'done' ? ($t->completed_at ?? now()) : null]);
        $this->refresh();
        if ($status === 'done') {
            Flux::toast(variant: 'success', text: __('Nice, “:title” is done.', ['title' => $t->title]));
        }
    }

    public function setPriority(int $id, string $priority): void
    {
        abort_unless(array_key_exists($priority, Task::PRIORITIES), 422);
        $this->mine()->findOrFail($id)->update(['priority' => $priority]);
        $this->refresh();
    }

    public function newTask(string $priority = 'medium'): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'fTitle', 'fDescription', 'fStart', 'fDue');
        $this->fClient = $this->client;
        $this->fType = (string) array_key_first(Task::types());
        $this->fStatus = 'todo';
        $this->fPriority = array_key_exists($priority, Task::PRIORITIES) ? $priority : 'medium';
        $this->fDue = $this->tab === 'today' ? today()->toDateString() : '';
        $this->fAssignees = $this->meId() ? [$this->meId()] : [];
        Flux::modal('my-task')->close();
        Flux::modal('task-form')->show();
    }

    public function editTask(int $id): void
    {
        $t = $this->mine()->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $t->id;
        $this->fTitle = $t->title;
        $this->fDescription = (string) $t->description;
        $this->fClient = (string) $t->client_id;
        $this->fType = $t->type;
        $this->fStatus = $t->stage();
        $this->fPriority = $t->priority;
        $this->fStart = (string) $t->start_date?->toDateString();
        $this->fDue = (string) $t->due_date?->toDateString();
        $this->fAssignees = $t->assignees->pluck('id')->all();
        Flux::modal('my-task')->close();
        Flux::modal('task-form')->show();
    }

    public function saveTask(): void
    {
        $data = $this->validate([
            'fTitle' => ['required', 'string', 'max:255'],
            'fDescription' => ['nullable', 'string', 'max:2000'],
            'fClient' => ['nullable', Rule::exists('clients', 'id')],
            'fType' => ['required', Rule::exists('work_types', 'slug')],
            'fStatus' => ['required', Rule::in(array_keys(Task::STATUSES))],
            'fPriority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
            'fStart' => ['nullable', 'date'],
            'fDue' => array_filter(['nullable', 'date', $this->fStart ? 'after_or_equal:fStart' : null]),
            'fAssignees' => ['array'],
            'fAssignees.*' => ['integer', Rule::exists('employees', 'id')],
        ], attributes: ['fTitle' => __('title'), 'fDue' => __('due date'), 'fStart' => __('start date'), 'fType' => __('type')]);

        $task = $this->editingId ? $this->mine()->findOrFail($this->editingId) : new Task;
        $task->fill([
            'title' => trim($data['fTitle']),
            'description' => trim((string) $data['fDescription']) ?: null,
            'type' => $data['fType'],
            'status' => $data['fStatus'],
            'priority' => $data['fPriority'],
            'start_date' => $data['fStart'] ?: null,
            'due_date' => $data['fDue'] ?: null,
            'completed_at' => $data['fStatus'] === 'done' ? ($task->completed_at ?? now()) : null,
        ]);
        $task->client_id = $data['fClient'] ? (int) $data['fClient'] : null;
        $task->save();

        // I always stay on it, or it would vanish from this page.
        $assignees = array_map('intval', $data['fAssignees'] ?? []);
        if ($this->meId()) {
            $assignees = array_values(array_unique([...$assignees, $this->meId()]));
        }
        $task->assignees()->sync($assignees);

        Flux::modal('task-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Task updated.') : __('Task added.'));
        $this->reset('editingId');
        $this->refresh();
    }
}; ?>

@php
    $panel = 'rounded-2xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $meId = auth()->user()->employee?->id;
    $q = mb_strtolower(trim($search));
    $match = fn ($list) => $q === '' ? $list : $list->filter(fn ($t) => str_contains(mb_strtolower($t->title.' '.$t->description.' '.$t->client?->displayName()), $q));
    $open = $match($this->open);
    $done = $match($this->done);
    $late = $open->filter->isOverdue();
    $dueToday = $open->filter(fn ($t) => $t->due_date?->isToday());
    $upcoming = $open->filter(fn ($t) => $t->due_date?->gt(today()));
    $tabs = [
        'open' => [__('All open'), $open, 'inbox-stack'],
        'today' => [__('Today'), $dueToday, 'sun'],
        'late' => [__('Overdue'), $late, 'exclamation-triangle'],
        'upcoming' => [__('Upcoming'), $upcoming, 'calendar-days'],
        'done' => [__('Done'), $done, 'check-badge'],
    ];
    $tab = array_key_exists($tab, $tabs) ? $tab : 'open';
    $list = $tabs[$tab][1];
    $types = Task::types();
    $typeOf = fn ($t) => $types[$t->type] ?? Task::unknownType($t->type);
    $toneOf = fn ($t) => WorkType::PALETTE[$typeOf($t)['color']] ?? WorkType::PALETTE['zinc'];
    $stageTone = [
        'todo' => ['pill' => 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300', 'dot' => 'bg-zinc-400', 'head' => 'text-zinc-600 dark:text-zinc-300'],
        'in_progress' => ['pill' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300', 'dot' => 'bg-sky-500', 'head' => 'text-sky-700 dark:text-sky-300'],
        'review' => ['pill' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300', 'dot' => 'bg-amber-500', 'head' => 'text-amber-700 dark:text-amber-300'],
        'done' => ['pill' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'dot' => 'bg-emerald-500', 'head' => 'text-emerald-700 dark:text-emerald-300'],
    ];
    $when = function ($t) {
        if ($t->completed_at) {
            return __('Done :date', ['date' => $t->completed_at->toNepali(false)]);
        }
        if (! $t->due_date) {
            return null;
        }
        $days = (int) today()->diffInDays($t->due_date, false);

        return $days < 0 ? trans_choice(':count day late|:count days late', -$days) : ($days === 0 ? __('Today') : ($days === 1 ? __('Tomorrow') : $t->due_date->toNepali(false)));
    };
    $week = $this->week;
    $weekPct = $week['total'] ? (int) round($week['done'] / $week['total'] * 100) : 0;
    $ring = 2 * M_PI * 42;
    // Four boxes, one per priority.
    $boxes = [
        'urgent' => ['head' => 'bg-rose-500', 'soft' => 'bg-rose-50/60 ring-rose-200/80 dark:bg-rose-500/[0.05] dark:ring-rose-500/20', 'hint' => __('Drop everything')],
        'high' => ['head' => 'bg-orange-500', 'soft' => 'bg-orange-50/60 ring-orange-200/80 dark:bg-orange-500/[0.05] dark:ring-orange-500/20', 'hint' => __('Next in line')],
        'medium' => ['head' => 'bg-sky-500', 'soft' => 'bg-sky-50/60 ring-sky-200/80 dark:bg-sky-500/[0.05] dark:ring-sky-500/20', 'hint' => __('Normal work')],
        'low' => ['head' => 'bg-zinc-400', 'soft' => 'bg-zinc-100/60 ring-zinc-200/80 dark:bg-white/[0.02] dark:ring-white/10', 'hint' => __('When there is time')],
    ];
@endphp

<div class="stagger flex w-full flex-col gap-6">
    {{-- Hero --}}
    <section class="grain relative isolate overflow-hidden rounded-2xl bg-brand-500 p-6 text-white sm:p-8">
        <div class="absolute inset-0 -z-10 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_right,black,transparent_70%)]"></div>
        <div class="absolute -top-24 -right-20 -z-10 size-72 rounded-full bg-white/10"></div>
        <div class="absolute right-40 -bottom-32 -z-10 hidden size-64 rounded-full bg-sun/40 blur-2xl sm:block"></div>

        <div class="flex flex-wrap items-center justify-between gap-6">
            <div class="min-w-0">
                <p class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/20 backdrop-blur">
                    <flux:icon.calendar variant="micro" class="size-3.5" />{{ today()->toNepali() }} · {{ now()->translatedFormat('l') }}
                </p>
                <h1 class="mt-4 text-2xl font-semibold tracking-[-0.02em] sm:text-3xl">{{ __('My tasks') }}</h1>
                <p class="mt-2 max-w-lg text-sm leading-relaxed text-brand-100">
                    @if ($late->count())
                        {{ trans_choice(':count task is late. Start there.|:count tasks are late. Start there.', $late->count()) }}
                    @elseif ($dueToday->count())
                        {{ trans_choice(':count task due today.|:count tasks due today.', $dueToday->count()) }}
                    @else
                        {{ __('Nothing due today. Plan ahead or pick something up.') }}
                    @endif
                </p>
                <div class="mt-6 flex flex-wrap gap-2">
                    <button type="button" wire:click="newTask" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-brand-600 shadow-sm transition hover:bg-brand-50" data-test="new-task"><flux:icon.plus variant="micro" class="size-4" />{{ __('New task') }}</button>
                    @foreach ([['today', __('Today'), $dueToday->count()], ['late', __('Overdue'), $late->count()], ['upcoming', __('Upcoming'), $upcoming->count()]] as [$key, $label, $n])
                        <button type="button" wire:click="$set('tab', '{{ $key }}')" class="inline-flex items-center gap-2 rounded-lg bg-white/15 px-3.5 py-2 text-sm font-semibold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25">
                            {{ $label }}<span @class(['rounded-md px-1.5 text-xs tabular-nums', 'bg-rose-500 text-white' => $key === 'late' && $n, 'bg-white/20' => ! ($key === 'late' && $n)])>{{ $n }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- This week --}}
            <div class="flex items-center gap-4 rounded-2xl bg-white/10 p-4 ring-1 ring-white/20 backdrop-blur">
                <div class="relative size-24 shrink-0">
                    <svg viewBox="0 0 100 100" class="size-24 -rotate-90" aria-hidden="true">
                        <circle cx="50" cy="50" r="42" fill="none" stroke-width="10" class="stroke-white/20" />
                        <circle cx="50" cy="50" r="42" fill="none" stroke-width="10" stroke-linecap="round" class="stroke-sun transition-all duration-700" stroke-dasharray="{{ $ring }}" stroke-dashoffset="{{ $ring * (1 - $weekPct / 100) }}" />
                    </svg>
                    <span class="absolute inset-0 flex flex-col items-center justify-center"><span class="text-xl font-bold tabular-nums">{{ $weekPct }}%</span></span>
                </div>
                <div>
                    <p class="text-xs font-semibold tracking-wider text-white/70 uppercase">{{ __('This week') }}</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums">{{ $week['done'] }} <span class="text-sm font-normal text-white/70">/ {{ $week['total'] }} {{ __('done') }}</span></p>
                </div>
            </div>
        </div>
    </section>

    @unless ($meId)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">{{ __('Your login is not linked to an employee profile yet, so no tasks show here. Ask an admin to link it.') }}</p>
    @endunless

    {{-- Tabs and filters --}}
    <section class="flex flex-col gap-3">
        <div class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]">
            @foreach ($tabs as $key => [$label, $items, $icon])
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                    'inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-medium ring-1 transition',
                    'bg-zinc-900 text-white ring-zinc-900 shadow-md dark:bg-white dark:text-zinc-900 dark:ring-white' => $tab === $key,
                    'bg-white text-zinc-600 ring-zinc-200 hover:text-zinc-900 hover:ring-zinc-300 dark:bg-zinc-950 dark:text-zinc-300 dark:ring-white/10 dark:hover:text-white' => $tab !== $key,
                ]) data-test="tab-{{ $key }}">
                    <flux:icon :icon="$icon" variant="micro" class="size-4" />{{ $label }}
                    <span @class([
                        'rounded-full px-1.5 text-xs tabular-nums',
                        'bg-white/20' => $tab === $key,
                        'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' => $tab !== $key && $key === 'late' && $items->count(),
                        'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-400' => $tab !== $key && ! ($key === 'late' && $items->count()),
                    ])>{{ $items->count() }}</span>
                </button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="min-w-56 flex-1"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search my tasks')" clearable /></div>
            <div class="w-full sm:w-56">
                <flux:select wire:model.live="client">
                    <flux:select.option value="">{{ __('All clients') }}</flux:select.option>
                    @foreach ($this->myClients as $c)
                        <flux:select.option value="{{ $c->id }}">{{ $c->displayName() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </section>

    {{-- Four boxes in one row, by priority --}}
    <div class="grid auto-cols-[minmax(17rem,1fr)] grid-flow-col items-start gap-4 overflow-x-auto pb-2 [scrollbar-width:thin]" data-test="priority-boxes">
        @foreach ($boxes as $pk => $box)
            @php $cards = $list->where('priority', $pk); @endphp
            <section wire:key="box-{{ $pk }}" class="{{ $box['soft'] }} flex min-w-0 flex-col overflow-hidden rounded-2xl ring-1" data-test="box-{{ $pk }}">
                <div class="{{ $box['head'] }} flex items-center gap-2.5 px-4 py-3 text-white">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-white/20"><flux:icon :icon="Task::PRIORITY_STYLES[$pk]['icon']" variant="mini" class="size-4" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ __(Task::PRIORITIES[$pk]) }}</p>
                        <p class="text-[0.6875rem] text-white/80">{{ $box['hint'] }}</p>
                    </div>
                    <span class="rounded-full bg-white/25 px-2 py-0.5 text-xs font-bold tabular-nums">{{ $cards->count() }}</span>
                </div>

                <div class="flex flex-col gap-2 p-2.5">
                    @forelse ($cards as $t)
                        @php $type = $typeOf($t); $tc = $toneOf($t); $stage = $t->stage(); $isLate = $t->isOverdue(); @endphp
                        <article wire:key="t-{{ $t->id }}" class="group relative flex flex-col gap-2.5 overflow-hidden rounded-xl bg-white p-3 ps-4 shadow-[0_1px_2px_rgb(15_23_42/0.06)] ring-1 ring-zinc-200/70 transition hover:-translate-y-0.5 hover:shadow-md dark:bg-zinc-950 dark:ring-white/10">
                            <span class="{{ $tc['dot'] }} absolute inset-y-0 start-0 w-1"></span>
                            <div class="flex items-start gap-2.5">
                                <button type="button" wire:click="setStatus({{ $t->id }}, '{{ $t->completed_at ? 'todo' : 'done' }}')" @class([
                                    'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition',
                                    'border-emerald-500 bg-emerald-500 text-white' => $t->completed_at,
                                    'border-zinc-300 text-transparent hover:border-emerald-500 hover:bg-emerald-50 hover:text-emerald-500 dark:border-zinc-600 dark:hover:bg-emerald-500/10' => ! $t->completed_at,
                                ]) aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}" data-test="toggle-{{ $t->id }}"><flux:icon.check variant="micro" class="size-3" /></button>
                                <button type="button" wire:click="openTask({{ $t->id }})" @class(['min-w-0 flex-1 text-start text-sm leading-snug font-semibold', 'text-zinc-400 line-through' => $t->completed_at, 'text-zinc-900 group-hover:text-brand-600 dark:text-white' => ! $t->completed_at])>{{ $t->title }}</button>
                                <flux:dropdown align="end">
                                    <button type="button" class="-me-1 shrink-0 rounded-md p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5" aria-label="{{ __('More') }}"><flux:icon.ellipsis-vertical variant="micro" class="size-4" /></button>
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="openTask({{ $t->id }})">{{ __('Open') }}</flux:menu.item>
                                        <flux:menu.item icon="pencil-square" wire:click="editTask({{ $t->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.submenu :heading="__('Move to')" icon="flag">
                                            @foreach (Task::PRIORITIES as $mk => $ml)
                                                @continue($mk === $pk)
                                                <flux:menu.item :icon="Task::PRIORITY_STYLES[$mk]['icon']" wire:click="setPriority({{ $t->id }}, '{{ $mk }}')">{{ __($ml) }}</flux:menu.item>
                                            @endforeach
                                        </flux:menu.submenu>
                                        @if ($t->client_id)
                                            <flux:menu.separator />
                                            <flux:menu.item icon="building-office-2" :href="route('clients.show', $t->client_id)" wire:navigate>{{ __('Open client') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </div>

                            <div class="flex min-w-0 flex-wrap items-center gap-1.5 ps-7.5">
                                <span class="{{ $tc['chip'] }} inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1"><flux:icon :icon="$type['icon']" variant="micro" class="size-3" />{{ $type['label'] }}</span>
                                <span class="inline-flex min-w-0 items-center gap-1 text-xs text-zinc-500">
                                    @if ($t->client?->avatarUrl())<img src="{{ $t->client->avatarUrl() }}" alt="" class="size-4 shrink-0 rounded object-cover">@endif
                                    <span class="truncate">{{ $t->client?->displayName() ?? __('Team task') }}</span>
                                </span>
                            </div>

                            <div class="flex items-center gap-1.5 ps-7.5">
                                @if ($w = $when($t))
                                    <span @class(['inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium tabular-nums', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $isLate, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $t->completed_at, 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300' => ! $isLate && ! $t->completed_at])>
                                        <flux:icon :icon="$t->completed_at ? 'check' : ($isLate ? 'exclamation-circle' : 'calendar')" variant="micro" class="size-3" />{{ $w }}
                                    </span>
                                @endif
                                <flux:dropdown align="start">
                                    <button type="button" class="{{ $stageTone[$stage]['pill'] }} inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold transition hover:opacity-80">
                                        <span class="{{ $stageTone[$stage]['dot'] }} size-1.5 rounded-full"></span>{{ __(Task::STATUSES[$stage]['label']) }}<flux:icon.chevron-down variant="micro" class="size-3" />
                                    </button>
                                    <flux:menu>
                                        @foreach (Task::STATUSES as $sk => $s)
                                            <flux:menu.item :icon="$s['icon']" wire:click="setStatus({{ $t->id }}, '{{ $sk }}')">{{ __($s['label']) }}</flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                                @if ($t->assignees->count() > 1)
                                    <div class="ms-auto flex -space-x-1.5" title="{{ $t->assignees->pluck('name')->join(', ') }}">
                                        @foreach ($t->assignees->take(3) as $person)
                                            <x-employee-avatar :employee="$person" class="size-6 text-[0.5625rem] ring-2 ring-white dark:ring-zinc-950" />
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="flex flex-col items-center gap-1.5 rounded-xl border border-dashed border-zinc-300 px-3 py-8 text-center dark:border-white/10">
                            <flux:icon.check-circle variant="mini" class="size-5 text-zinc-300 dark:text-zinc-600" />
                            <p class="text-xs text-zinc-400">{{ $tab === 'done' ? __('Nothing done here') : __('Nothing here') }}</p>
                        </div>
                    @endforelse

                    @if ($tab !== 'done')
                        <button type="button" wire:click="newTask('{{ $pk }}')" class="flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-xs font-medium text-zinc-500 transition hover:bg-white hover:text-brand-600 dark:hover:bg-white/5" data-test="add-{{ $pk }}">
                            <flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add :priority task', ['priority' => mb_strtolower(__(Task::PRIORITIES[$pk]))]) }}
                        </button>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    {{-- Task details --}}
    <flux:modal name="my-task" class="w-full max-w-lg">
        @if ($v = $this->viewing)
            @php $type = $typeOf($v); $tc = $toneOf($v); @endphp
            <div class="flex flex-col gap-5">
                <div class="flex items-start gap-3 pe-8">
                    <span class="{{ $tc['dot'] }} flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow-md"><flux:icon :icon="$type['icon']" variant="mini" class="size-5" /></span>
                    <div class="min-w-0">
                        <flux:heading size="lg">{{ $v->title }}</flux:heading>
                        <flux:text>
                            @if ($v->client_id)<a href="{{ route('clients.show', $v->client_id) }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-300">{{ $v->client->displayName() }}</a>@else{{ __('Team task') }}@endif
                            · {{ $type['label'] }}
                        </flux:text>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-white/5"><dt class="text-xs text-zinc-500">{{ __('Start') }}</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ $v->start_date?->toNepali() ?? '—' }}</dd></div>
                    <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-white/5"><dt class="text-xs text-zinc-500">{{ __('Due') }}</dt><dd @class(['font-medium', 'text-rose-600 dark:text-rose-400' => $v->isOverdue(), 'text-zinc-900 dark:text-white' => ! $v->isOverdue()])>{{ $v->due_date?->toNepali() ?? __('No date') }}</dd></div>
                    <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-white/5"><dt class="text-xs text-zinc-500">{{ __('Priority') }}</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ __(Task::PRIORITIES[$v->priority] ?? 'Medium') }}</dd></div>
                    <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-white/5"><dt class="text-xs text-zinc-500">{{ __('Added') }}</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ $v->created_at->toNepali() }}</dd></div>
                    <div class="col-span-2 rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-white/5">
                        <dt class="text-xs text-zinc-500">{{ __('People') }}</dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            @foreach ($v->assignees as $person)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-white py-0.5 ps-0.5 pe-2.5 text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-white/10"><x-employee-avatar :employee="$person" class="size-5 text-[0.5rem]" />{{ $person->name }}</span>
                            @endforeach
                        </dd>
                    </div>
                </dl>
                @if ($v->description)
                    <p class="rounded-xl bg-brand-50/50 px-4 py-3 text-sm leading-relaxed whitespace-pre-line text-zinc-700 dark:bg-brand-500/10 dark:text-zinc-200">{{ $v->description }}</p>
                @endif
                <div>
                    <p class="mb-2 text-xs font-medium text-zinc-500">{{ __('Status') }}</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach (Task::STATUSES as $sk => $s)
                            <button type="button" wire:click="setStatus({{ $v->id }}, '{{ $sk }}')" @class([
                                'flex flex-col items-center gap-1 rounded-xl px-2 py-2.5 text-xs font-medium ring-1 transition',
                                'bg-brand-500 text-white ring-brand-500 shadow-md shadow-brand-500/25' => $v->stage() === $sk,
                                'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10' => $v->stage() !== $sk,
                            ])><flux:icon :icon="$s['icon']" variant="mini" class="size-4" />{{ __($s['label']) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:button variant="primary" icon="pencil-square" wire:click="editTask({{ $v->id }})" data-test="edit-task">{{ __('Edit') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Add / edit --}}
    <flux:modal name="task-form" class="w-full max-w-xl">
        <form wire:submit="saveTask" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit task') : __('New task') }}</flux:heading>
                <flux:text>{{ __('Pick a client so it also shows on their profile. You stay on the task.') }}</flux:text>
            </div>
            <flux:input wire:model="fTitle" :label="__('Title')" :placeholder="__('e.g. Write the Tihar reel caption')" required autofocus />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="fClient" :label="__('Client')">
                    <flux:select.option value="">{{ __('No client (team task)') }}</flux:select.option>
                    @foreach ($this->clients as $c)
                        <flux:select.option value="{{ $c->id }}">{{ $c->displayName() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="fType" :label="__('Type')">
                    @foreach ($types as $slug => $t)
                        <flux:select.option value="{{ $slug }}">{{ $t['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="fStatus" :label="__('Status')">
                    @foreach (Task::STATUSES as $sk => $s)
                        <flux:select.option value="{{ $sk }}">{{ __($s['label']) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="fPriority" :label="__('Priority')">
                    @foreach (Task::PRIORITIES as $pk => $pl)
                        <flux:select.option value="{{ $pk }}">{{ __($pl) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model="fStart" :label="__('Start')" />
                <flux:input type="date" wire:model="fDue" :label="__('Due')" />
            </div>
            <flux:textarea wire:model="fDescription" :label="__('Notes')" rows="3" :placeholder="__('Anything the team should know')" />
            <div>
                <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ __('People') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($this->team as $person)
                        <label wire:key="pick-{{ $person->id }}" class="cursor-pointer">
                            <input type="checkbox" wire:model="fAssignees" value="{{ $person->id }}" class="peer sr-only" @disabled($person->id === $meId)>
                            <span class="inline-flex items-center gap-1.5 rounded-full py-0.5 ps-0.5 pe-2.5 text-xs font-medium text-zinc-600 ring-1 ring-zinc-200 transition peer-checked:bg-brand-500 peer-checked:text-white peer-checked:ring-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-300 peer-not-checked:hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:peer-not-checked:hover:bg-white/5">
                                <x-employee-avatar :employee="$person" class="size-6 text-[0.5625rem]" />{{ $person->id === $meId ? __('You') : $person->name }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-task">{{ $editingId ? __('Save changes') : __('Add task') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
