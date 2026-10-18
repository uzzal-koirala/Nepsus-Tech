<?php

use App\Concerns\ManagesTasks;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\WorkType;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tasks')] class extends Component {
    use ManagesTasks;

    #[Url(except: 'boxes')]
    public string $view = 'boxes';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $clientFilter = '';

    #[Url(except: '')]
    public string $priority = '';

    /** @var array<string, string> Add / edit box. */
    public array $box = ['name' => '', 'client_id' => '', 'color' => 'violet', 'due_date' => '', 'priority' => 'medium'];

    public ?int $editingBoxId = null;

    public ?int $deletingBoxId = null;

    /** @var array<int|string, string> Quick-add title typed in each box. */
    public array $quick = [];

    public function mount(): void
    {
        $this->view = in_array($this->view, ['boxes', 'board', 'list'], true) ? $this->view : 'boxes';
        $this->task = $this->blankTask();
    }

    public function newTaskFor(string $status): void
    {
        $this->newTask(null, $status);
    }

    public function newTaskIn(int $boxId): void
    {
        $box = TaskGroup::findOrFail($boxId);
        $this->newTask();
        $this->task['task_group_id'] = (string) $box->id;
        $this->task['client_id'] = (string) $box->client_id;
    }

    /** Typing a title in a box and pressing Enter adds a to-do there. */
    public function quickAdd(int $boxId): void
    {
        $box = TaskGroup::findOrFail($boxId);
        $title = trim($this->quick[$boxId] ?? '');
        $this->validate(["quick.$boxId" => ['required', 'string', 'max:255']], attributes: ["quick.$boxId" => __('task')]);

        $box->tasks()->create(['title' => $title, 'type' => array_key_first($this->types), 'priority' => 'medium', 'status' => 'todo'])
            ->forceFill(['client_id' => $box->client_id])->save();
        unset($this->quick[$boxId]);
    }

    #[Computed]
    public function boxList()
    {
        return TaskGroup::with(['client', 'tasks' => fn ($q) => $q->with('assignees')->orderByRaw('completed_at is not null')->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')])
            ->orderBy('position')->orderBy('id')->get();
    }

    public function newBox(): void
    {
        $this->view = 'boxes'; // the box form lives in the boxes view
        $this->editingBoxId = null;
        $this->box = ['name' => '', 'client_id' => '', 'color' => array_keys(WorkType::PALETTE)[TaskGroup::count() % count(WorkType::PALETTE)], 'due_date' => '', 'priority' => 'medium'];
        $this->resetErrorBag();
        Flux::modal('box-form')->show();
    }

    public function editBox(int $id): void
    {
        $b = TaskGroup::findOrFail($id);
        $this->editingBoxId = $b->id;
        $this->box = ['name' => $b->name, 'client_id' => (string) $b->client_id, 'color' => $b->color, 'due_date' => (string) $b->due_date?->toDateString(), 'priority' => $b->priority];
        $this->resetErrorBag();
        Flux::modal('box-form')->show();
    }

    public function saveBox(): void
    {
        $data = $this->validate([
            'box.name' => ['required', 'string', 'max:120'],
            'box.client_id' => ['nullable', 'exists:clients,id'],
            'box.due_date' => ['nullable', 'date'],
            'box.priority' => ['required', \Illuminate\Validation\Rule::in(array_keys(Task::PRIORITIES))],
            'box.color' => ['required', \Illuminate\Validation\Rule::in(array_keys(WorkType::PALETTE))],
        ], attributes: ['box.name' => __('box name')])['box'];

        $b = TaskGroup::updateOrCreate(['id' => $this->editingBoxId], ['name' => $data['name'], 'client_id' => $data['client_id'] ?: null, 'color' => $data['color'], 'due_date' => $data['due_date'] ?: null, 'priority' => $data['priority']] + ($this->editingBoxId ? [] : ['position' => (int) TaskGroup::max('position') + 1]));
        // Tasks follow their box's client when they had none.
        if ($b->client_id) {
            $b->tasks()->whereNull('client_id')->update(['client_id' => $b->client_id]);
        }

        $this->view = 'boxes';
        Flux::modal('box-form')->close();
        Flux::toast(variant: 'success', text: $this->editingBoxId ? __('Box updated.') : __('Box created. Add its tasks.'));
    }

    public function confirmDeleteBox(int $id): void
    {
        $this->deletingBoxId = $id;
        Flux::modal('box-delete')->show();
    }

    public function deleteBox(): void
    {
        TaskGroup::whereKey($this->deletingBoxId)->first()?->delete();
        $this->deletingBoxId = null;
        Flux::modal('box-delete')->close();
        Flux::toast(text: __('Box deleted.'));
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'clientFilter', 'priority');
    }

    /** Open work plus anything finished in the last 30 days. */
    #[Computed]
    public function all()
    {
        return Task::with(['client', 'assignees'])
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', now()->subDays(30)))
            ->orderByRaw('due_date is null')->orderBy('due_date')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest('id')->get();
    }

    #[Computed]
    public function visible()
    {
        $q = mb_strtolower(trim($this->search));

        return $this->all
            ->when($this->clientFilter === 'none', fn ($c) => $c->whereNull('client_id'))
            ->when(ctype_digit($this->clientFilter), fn ($c) => $c->where('client_id', (int) $this->clientFilter))
            ->when($this->priority !== '', fn ($c) => $c->where('priority', $this->priority))
            ->when($q !== '', fn ($c) => $c->filter(fn ($t) => str_contains(mb_strtolower($t->title.' '.$t->description.' '.$t->client?->displayName()), $q)))
            ->values();
    }

    #[Computed]
    public function stats(): array
    {
        $open = $this->all->whereNull('completed_at');

        return [
            'open' => $open->count(),
            'today' => $open->filter(fn ($t) => $t->due_date?->isToday())->count(),
            'overdue' => $open->filter->isOverdue()->count(),
            'week' => $this->all->filter(fn ($t) => $t->completed_at?->gte(now()->subDays(7)))->count(),
        ];
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $types = $this->types;
    $typeOf = fn (?string $slug) => $types[$slug] ?? Task::unknownType($slug);
    $toneOf = fn (?string $slug) => WorkType::PALETTE[$typeOf($slug)['color']] ?? WorkType::PALETTE['zinc'];
    $statuses = Task::STATUSES;
    $statusTones = [
        'todo' => ['dot' => 'bg-zinc-400', 'pill' => 'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/10'],
        'in_progress' => ['dot' => 'bg-brand-500', 'pill' => 'bg-brand-50 text-brand-700 ring-brand-200 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-500/20'],
        'review' => ['dot' => 'bg-amber-400', 'pill' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-500/20'],
        'done' => ['dot' => 'bg-emerald-500', 'pill' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-500/20'],
    ];
    $tasks = $this->visible;
    $s = $this->stats;
    $filtered = $search !== '' || $clientFilter !== '' || $priority !== '';
    $due = function ($t) {
        if (! $t->due_date) {
            return null;
        }
        $tone = $t->completed_at ? 'text-zinc-400' : ($t->isOverdue() ? 'text-rose-600 dark:text-rose-400' : ($t->due_date->isToday() ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500'));
        $label = $t->due_date->isToday() ? __('Today') : ($t->due_date->isTomorrow() ? __('Tomorrow') : $t->due_date->toNepali(false));

        return [$tone, $label];
    };
    $clientOptions = collect([['value' => '', 'label' => __('All clients'), 'icon' => 'building-office-2'], ['value' => 'none', 'label' => __('No client'), 'hint' => __('Internal work'), 'icon' => 'home']])
        ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
    $priorityFilterOptions = collect([['value' => '', 'label' => __('Any priority'), 'icon' => 'funnel']])
        ->concat(collect(Task::PRIORITIES)->map(fn ($label, $k) => ['value' => $k, 'label' => __($label), 'icon' => Task::PRIORITY_STYLES[$k]['icon'], 'tile' => Task::PRIORITY_STYLES[$k]['tone']])->values());
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Tasks') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Create work, give it to the right people and watch it move to done.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button icon="archive-box" wire:click="newBox" data-test="new-box">{{ __('New box') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="newTaskFor('todo')" class="shadow-lg shadow-brand-500/25" data-test="new-task">{{ __('New task') }}</flux:button>
        </div>
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            [__('Open tasks'), $s['open'], __('Waiting or in progress'), 'clipboard-document-list', 'bg-brand-500'],
            [__('Due today'), $s['today'], __('Finish before the day ends'), 'sun', 'bg-amber-500'],
            [__('Overdue'), $s['overdue'], __('Past the due date'), 'exclamation-triangle', 'bg-rose-500'],
            [__('Done this week'), $s['week'], __('Last 7 days'), 'check-badge', 'bg-emerald-500'],
        ] as [$label, $value, $sub, $icon, $tile])
            <div class="{{ $panel }} flex items-center gap-4 p-5">
                <span class="{{ $tile }} flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow-md"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $label }}</p>
                    <p class="text-[1.75rem] leading-tight font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $value }}</p>
                    <p class="truncate text-xs text-zinc-500">{{ $sub }}</p>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-2">
        @if ($view !== 'boxes')
        <div class="min-w-56 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search tasks or clients')" />
        </div>
        <x-rich-select model="clientFilter" live searchable :options="$clientOptions" class="w-60" />
        <x-rich-select model="priority" live :options="$priorityFilterOptions" class="w-44" />
        @if ($filtered)
            <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear') }}</flux:button>
        @endif
        @endif
        <nav class="ms-auto flex gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
            @foreach (['boxes' => ['archive-box', __('Boxes')], 'board' => ['view-columns', __('Board')], 'list' => ['list-bullet', __('List')]] as $key => [$icon, $label])
                <button type="button" wire:click="$set('view', '{{ $key }}')" @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $view === $key,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => $view !== $key,
                ])><flux:icon :icon="$icon" variant="micro" class="size-4" />{{ $label }}</button>
            @endforeach
        </nav>
    </div>

    @if ($view === 'boxes')
        @include('partials.task-boxes')
    @elseif ($this->all->isEmpty())
        <section class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
            <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.clipboard-document-check class="size-8" /></span>
            <div>
                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('No tasks yet') }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Add the first piece of work and assign it to someone.') }}</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="newTaskFor('todo')">{{ __('New task') }}</flux:button>
        </section>
    @elseif ($view === 'board')
        {{-- Board: drag a card to another column --}}
        <section class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0" x-data="{ dragging: null, over: null }">
            <div class="grid min-w-[64rem] grid-cols-4 items-start gap-4">
                @foreach ($statuses as $key => $st)
                    @php $col = $tasks->filter(fn ($t) => $t->stage() === $key); @endphp
                    <div class="flex min-h-96 flex-col rounded-xl bg-zinc-100/70 p-2 ring-1 ring-transparent transition dark:bg-white/[0.03]"
                        :class="over === '{{ $key }}' && 'ring-brand-400 bg-brand-50/60 dark:bg-brand-500/10'"
                        @dragover.prevent="over = '{{ $key }}'" @dragleave="over = null"
                        @drop.prevent="over = null; if (dragging) $wire.moveTask(dragging, '{{ $key }}')"
                        data-test="column-{{ $key }}">
                        <div class="flex items-center gap-2 px-2 py-2">
                            <span class="{{ $statusTones[$key]['dot'] }} size-2.5 rounded-full"></span>
                            <p class="flex-1 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __($st['label']) }}</p>
                            <span class="rounded-md bg-white px-1.5 py-0.5 text-xs font-semibold text-zinc-600 tabular-nums shadow-xs dark:bg-white/10 dark:text-zinc-300">{{ $col->count() }}</span>
                            <button type="button" wire:click="newTaskFor('{{ $key }}')" class="rounded-md p-1 text-zinc-400 transition hover:bg-white hover:text-brand-600 dark:hover:bg-white/10" aria-label="{{ __('Add task') }}"><flux:icon.plus variant="micro" class="size-4" /></button>
                        </div>

                        <div class="flex flex-col gap-2">
                            @foreach ($col as $t)
                                @php $type = $typeOf($t->type); $d = $due($t); @endphp
                                <article wire:key="card-{{ $t->id }}" draggable="true" @dragstart="dragging = {{ $t->id }}" @dragend="dragging = null"
                                    class="group cursor-grab rounded-lg bg-white p-3 shadow-xs ring-1 ring-zinc-200/70 transition hover:shadow-md hover:ring-brand-200 active:cursor-grabbing dark:bg-zinc-900 dark:ring-white/10">
                                    <div class="flex items-center gap-1.5">
                                        <span class="{{ $toneOf($t->type)['chip'] }} inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1"><flux:icon :icon="$type['icon']" variant="micro" class="size-3" />{{ $type['label'] }}</span>
                                        @if (in_array($t->priority, ['high', 'urgent'], true))
                                            <span class="{{ Task::PRIORITY_STYLES[$t->priority]['tone'] }} inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-semibold ring-1"><flux:icon :icon="Task::PRIORITY_STYLES[$t->priority]['icon']" variant="micro" class="size-3" />{{ __(Task::PRIORITIES[$t->priority]) }}</span>
                                        @endif
                                        <flux:dropdown align="end" class="ms-auto">
                                            <button type="button" class="rounded p-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Actions') }}"><flux:icon.ellipsis-horizontal variant="micro" class="size-4" /></button>
                                            <flux:menu>
                                                <flux:menu.heading>{{ __('Move to') }}</flux:menu.heading>
                                                @foreach ($statuses as $to => $tst)
                                                    @if ($to !== $key)
                                                        <flux:menu.item :icon="$tst['icon']" wire:click="moveTask({{ $t->id }}, '{{ $to }}')">{{ __($tst['label']) }}</flux:menu.item>
                                                    @endif
                                                @endforeach
                                                <flux:menu.separator />
                                                <flux:menu.item icon="pencil-square" wire:click="editTask({{ $t->id }})">{{ __('Edit') }}</flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $t->id }})">{{ __('Delete') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                    <button type="button" wire:click="editTask({{ $t->id }})" class="mt-2 block w-full text-start">
                                        <p @class(['text-sm leading-snug font-semibold', 'text-zinc-400 line-through' => $t->completed_at, 'text-zinc-900 group-hover:text-brand-600 dark:text-white' => ! $t->completed_at])>{{ $t->title }}</p>
                                        @if ($t->client)<p class="mt-0.5 flex items-center gap-1 truncate text-xs text-zinc-500"><flux:icon.building-office-2 variant="micro" class="size-3 shrink-0" />{{ $t->client->displayName() }}</p>@endif
                                    </button>
                                    <div class="mt-3 flex items-center justify-between gap-2">
                                        @if ($d)
                                            <span class="{{ $d[0] }} inline-flex items-center gap-1 text-[0.6875rem] font-medium"><flux:icon.calendar variant="micro" class="size-3" />{{ $d[1] }}</span>
                                        @else
                                            <span class="text-[0.6875rem] text-zinc-400">{{ __('No date') }}</span>
                                        @endif
                                        @if ($t->assignees->isNotEmpty())
                                            <span class="flex -space-x-1.5">
                                                @foreach ($t->assignees->take(3) as $a)
                                                    <x-employee-avatar :employee="$a" class="size-6 text-[0.6rem] ring-2 ring-white dark:ring-zinc-900" />
                                                @endforeach
                                                @if ($t->assignees->count() > 3)<span class="flex size-6 items-center justify-center rounded-full bg-zinc-100 text-[0.6rem] font-semibold text-zinc-600 ring-2 ring-white dark:bg-white/10 dark:ring-zinc-900">+{{ $t->assignees->count() - 3 }}</span>@endif
                                            </span>
                                        @else
                                            <span class="flex size-6 items-center justify-center rounded-full border border-dashed border-zinc-300 text-zinc-300 dark:border-white/20" title="{{ __('Unassigned') }}"><flux:icon.user variant="micro" class="size-3" /></span>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                            @if ($col->isEmpty())
                                <button type="button" wire:click="newTaskFor('{{ $key }}')" class="flex items-center justify-center gap-1.5 rounded-lg border-2 border-dashed border-zinc-200 py-6 text-xs font-medium text-zinc-400 transition hover:border-brand-300 hover:text-brand-600 dark:border-white/10">
                                    <flux:icon.plus variant="micro" class="size-4" />{{ __('Add task') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @else
        {{-- List --}}
        <section class="{{ $panel }} overflow-hidden">
            @if ($tasks->isEmpty())
                <p class="px-6 py-14 text-center text-sm text-zinc-500">{{ __('Nothing matches these filters.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[56rem] text-sm">
                        <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                            <tr class="text-start text-xs font-semibold tracking-wide text-zinc-500 uppercase">
                                <th class="w-10 py-2.5 ps-5"></th>
                                <th class="px-3 py-2.5 text-start">{{ __('Task') }}</th>
                                <th class="px-3 py-2.5 text-start">{{ __('Assigned to') }}</th>
                                <th class="px-3 py-2.5 text-start">{{ __('Due') }}</th>
                                <th class="px-3 py-2.5 text-start">{{ __('Priority') }}</th>
                                <th class="px-3 py-2.5 text-start">{{ __('Status') }}</th>
                                <th class="w-20 py-2.5 pe-5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach ($tasks as $t)
                                @php $type = $typeOf($t->type); $d = $due($t); $stage = $t->stage(); @endphp
                                <tr wire:key="row-{{ $t->id }}" class="group transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="py-3 ps-5">
                                        <button type="button" wire:click="toggleDone({{ $t->id }})" @class([
                                            'flex size-5 items-center justify-center rounded-md ring-1 transition',
                                            'bg-emerald-500 text-white ring-emerald-500' => $t->completed_at,
                                            'ring-zinc-300 hover:ring-brand-500 dark:ring-white/20' => ! $t->completed_at,
                                        ]) aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}">@if ($t->completed_at)<flux:icon.check variant="micro" class="size-3.5" />@endif</button>
                                    </td>
                                    <td class="max-w-sm px-3 py-3">
                                        <button type="button" wire:click="editTask({{ $t->id }})" class="flex min-w-0 items-center gap-3 text-start">
                                            <span class="{{ $toneOf($t->type)['chip'] }} flex size-8 shrink-0 items-center justify-center rounded-lg ring-1" title="{{ $type['label'] }}"><flux:icon :icon="$type['icon']" variant="micro" class="size-4" /></span>
                                            <span class="min-w-0">
                                                <span @class(['block truncate font-semibold', 'text-zinc-400 line-through' => $t->completed_at, 'text-zinc-900 group-hover:text-brand-600 dark:text-white' => ! $t->completed_at])>{{ $t->title }}</span>
                                                <span class="block truncate text-xs text-zinc-500">{{ $t->client?->displayName() ?? __('Internal') }} · {{ $type['label'] }}</span>
                                            </span>
                                        </button>
                                    </td>
                                    <td class="px-3 py-3">
                                        @if ($t->assignees->isNotEmpty())
                                            <span class="flex items-center gap-2">
                                                <span class="flex -space-x-1.5">@foreach ($t->assignees->take(3) as $a)<x-employee-avatar :employee="$a" class="size-7 text-[0.625rem] ring-2 ring-white dark:ring-zinc-950" />@endforeach</span>
                                                <span class="max-w-32 truncate text-xs text-zinc-600 dark:text-zinc-300">{{ $t->assignees->count() === 1 ? $t->assignees->first()->name : trans_choice(':count people|:count people', $t->assignees->count()) }}</span>
                                            </span>
                                        @else
                                            <span class="text-xs text-zinc-400">{{ __('Unassigned') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 whitespace-nowrap">@if ($d)<span class="{{ $d[0] }} text-xs font-medium">{{ $d[1] }}</span>@else<span class="text-xs text-zinc-400">—</span>@endif</td>
                                    <td class="px-3 py-3"><span class="{{ Task::PRIORITY_STYLES[$t->priority]['tone'] ?? '' }} inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1"><flux:icon :icon="Task::PRIORITY_STYLES[$t->priority]['icon'] ?? 'minus'" variant="micro" class="size-3" />{{ __(Task::PRIORITIES[$t->priority] ?? 'Medium') }}</span></td>
                                    <td class="px-3 py-3">
                                        <flux:dropdown>
                                            <button type="button" class="{{ $statusTones[$stage]['pill'] }} inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold ring-1"><span class="{{ $statusTones[$stage]['dot'] }} size-1.5 rounded-full"></span>{{ __($statuses[$stage]['label']) }}<flux:icon.chevron-down variant="micro" class="size-3 opacity-60" /></button>
                                            <flux:menu>
                                                @foreach ($statuses as $to => $tst)
                                                    <flux:menu.item :icon="$tst['icon']" wire:click="moveTask({{ $t->id }}, '{{ $to }}')">{{ __($tst['label']) }}</flux:menu.item>
                                                @endforeach
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                    <td class="py-3 pe-5 text-end">
                                        <span class="inline-flex gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editTask({{ $t->id }})" :aria-label="__('Edit')" />
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $t->id }})" :aria-label="__('Delete')" class="text-rose-600!" />
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @include('partials.task-form')
</div>
