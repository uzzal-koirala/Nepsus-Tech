<?php

use App\Models\Task;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('My tasks')] class extends Component {
    #[Url(except: 'open')]
    public string $show = 'open';

    #[Url(except: '')]
    public string $search = '';

    public ?int $viewingId = null;

    private function mine()
    {
        return Task::with(['client', 'assignees'])->whereHas('assignees', fn ($q) => $q->whereKey(auth()->user()->employee?->id ?? 0));
    }

    #[Computed]
    public function open()
    {
        return $this->mine()->whereNull('completed_at')->orderByRaw('due_date is null')->orderBy('due_date')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->get();
    }

    #[Computed]
    public function done()
    {
        return $this->mine()->whereNotNull('completed_at')->latest('completed_at')->limit(40)->get();
    }

    #[Computed]
    public function viewing(): ?Task
    {
        return $this->viewingId ? $this->mine()->find($this->viewingId) : null;
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
        unset($this->open, $this->done, $this->viewing);
        if ($status === 'done') {
            Flux::toast(variant: 'success', text: __('Nice, “:title” is done.', ['title' => $t->title]));
        }
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $q = mb_strtolower(trim($search));
    $match = fn ($list) => $q === '' ? $list : $list->filter(fn ($t) => str_contains(mb_strtolower($t->title.' '.$t->description.' '.$t->client?->displayName()), $q));
    $open = $match($this->open);
    $done = $match($this->done);
    $weekEnd = today()->addDays(7);
    $groups = $show === 'open' ? [
        ['late', __('Overdue'), $open->filter(fn ($t) => $t->due_date?->lt(today())), 'text-rose-600 dark:text-rose-400'],
        ['today', __('Today'), $open->filter(fn ($t) => $t->due_date?->isToday()), 'text-brand-600 dark:text-brand-300'],
        ['week', __('This week'), $open->filter(fn ($t) => $t->due_date?->gt(today()) && $t->due_date->lte($weekEnd)), 'text-zinc-600 dark:text-zinc-300'],
        ['later', __('Later'), $open->filter(fn ($t) => $t->due_date?->gt($weekEnd)), 'text-zinc-500'],
        ['none', __('No date'), $open->whereNull('due_date'), 'text-zinc-400'],
    ] : [['done', __('Done recently'), $done, 'text-emerald-600 dark:text-emerald-400']];
    $types = Task::types();
    $stageTone = ['todo' => 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300', 'in_progress' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300', 'review' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300', 'done' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'];
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('My tasks') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Everything assigned to you. Tick it off when it is done.') }}</p>
        </div>
    </header>

    <div class="flex flex-wrap items-center gap-2">
        <div class="grid grid-cols-2 gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
            @foreach (['open' => [__('To do'), $this->open->count()], 'done' => [__('Done'), $this->done->count()]] as $key => [$label, $n])
                <button type="button" wire:click="$set('show', '{{ $key }}')" @class([
                    'flex items-center justify-center gap-2 rounded-lg px-4 py-1.5 text-sm font-medium transition',
                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $show === $key,
                    'text-zinc-500 hover:text-zinc-900 dark:hover:text-white' => $show !== $key,
                ]) data-test="show-{{ $key }}">{{ $label }}<span class="text-xs tabular-nums opacity-70">{{ $n }}</span></button>
            @endforeach
        </div>
        <div class="ms-auto w-full sm:w-72"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search my tasks')" /></div>
    </div>

    @php $any = collect($groups)->contains(fn ($g) => $g[2]->isNotEmpty()); @endphp
    @if (! $any)
        <section class="{{ $panel }} flex flex-col items-center gap-3 px-6 py-16 text-center">
            <span class="flex size-16 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge class="size-8" /></span>
            <p class="font-semibold text-zinc-900 dark:text-white">{{ $q !== '' ? __('Nothing matches') : ($show === 'open' ? __('All clear') : __('Nothing done yet')) }}</p>
            <p class="text-sm text-zinc-500">{{ $show === 'open' ? __('No open tasks assigned to you.') : __('Finished tasks show up here.') }}</p>
        </section>
    @else
        <section class="{{ $panel }} overflow-hidden" data-test="my-task-list">
            @foreach ($groups as [$key, $label, $list, $tone])
                @continue($list->isEmpty())
                <p class="{{ $tone }} border-b border-zinc-100 bg-zinc-50/60 px-5 py-2 text-[0.6875rem] font-semibold tracking-wider uppercase dark:border-white/5 dark:bg-white/[0.02]">{{ $label }} · {{ $list->count() }}</p>
                <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($list as $t)
                        @php $type = $types[$t->type] ?? Task::unknownType($t->type); $stage = $t->stage(); @endphp
                        <li wire:key="t-{{ $t->id }}" class="group flex items-center gap-3 px-5 py-3 transition hover:bg-zinc-50/70 dark:hover:bg-white/[0.02]">
                            <button type="button" wire:click="setStatus({{ $t->id }}, '{{ $t->completed_at ? 'todo' : 'done' }}')" @class([
                                'flex size-5 shrink-0 items-center justify-center rounded-md ring-1 transition',
                                'bg-emerald-500 text-white ring-emerald-500' => $t->completed_at,
                                'ring-zinc-300 hover:bg-emerald-50 hover:ring-emerald-500 dark:ring-white/20' => ! $t->completed_at,
                            ]) aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}" data-test="toggle-{{ $t->id }}">
                                @if ($t->completed_at)<flux:icon.check variant="micro" class="size-3.5" />@endif
                            </button>
                            <button type="button" wire:click="openTask({{ $t->id }})" class="min-w-0 flex-1 text-start">
                                <span @class(['block truncate text-sm font-medium', 'text-zinc-400 line-through' => $t->completed_at, 'text-zinc-900 group-hover:text-brand-600 dark:text-white' => ! $t->completed_at])>{{ $t->title }}</span>
                                <span class="flex items-center gap-1.5 truncate text-xs text-zinc-500">
                                    <flux:icon :icon="$type['icon']" variant="micro" class="size-3.5 text-zinc-400" />{{ $type['label'] }} · {{ $t->client?->displayName() ?? __('Team task') }}
                                </span>
                            </button>
                            @if (! $t->completed_at && in_array($t->priority, ['urgent', 'high']))
                                <span class="{{ Task::PRIORITY_STYLES[$t->priority]['tone'] }} hidden shrink-0 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1 sm:inline">{{ __(Task::PRIORITIES[$t->priority]) }}</span>
                            @endif
                            <flux:dropdown align="end">
                                <button type="button" class="{{ $stageTone[$stage] }} hidden shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold transition hover:opacity-80 sm:inline-flex">
                                    {{ __(Task::STATUSES[$stage]['label']) }}<flux:icon.chevron-down variant="micro" class="size-3" />
                                </button>
                                <flux:menu>
                                    @foreach (Task::STATUSES as $sk => $s)
                                        <flux:menu.item :icon="$s['icon']" wire:click="setStatus({{ $t->id }}, '{{ $sk }}')">{{ __($s['label']) }}</flux:menu.item>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>
                            <span @class(['w-20 shrink-0 text-end text-xs tabular-nums', 'font-semibold text-rose-600' => ! $t->completed_at && $t->due_date?->lt(today()), 'text-zinc-500' => $t->completed_at || ! $t->due_date?->lt(today())])>
                                @if ($t->completed_at){{ $t->completed_at->diffForHumans(short: true) }}@elseif ($t->due_date){{ $t->due_date->isToday() ? __('Today') : ($t->due_date->isTomorrow() ? __('Tomorrow') : $t->due_date->toNepali(false)) }}@else — @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </section>
    @endif

    {{-- Task details --}}
    <flux:modal name="my-task" class="w-full max-w-lg">
        @if ($v = $this->viewing)
            @php $type = $types[$v->type] ?? Task::unknownType($v->type); @endphp
            <div class="flex flex-col gap-5">
                <div class="flex items-start gap-3 pe-8">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon :icon="$type['icon']" variant="mini" class="size-5" /></span>
                    <div class="min-w-0">
                        <flux:heading size="lg">{{ $v->title }}</flux:heading>
                        <flux:text>{{ $v->client?->displayName() ?? __('Team task') }} · {{ $type['label'] }}</flux:text>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5"><dt class="text-xs text-zinc-500">{{ __('Due') }}</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ $v->due_date?->toNepali() ?? __('No date') }}</dd></div>
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5"><dt class="text-xs text-zinc-500">{{ __('Priority') }}</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ __(Task::PRIORITIES[$v->priority] ?? 'Medium') }}</dd></div>
                    <div class="col-span-2 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5"><dt class="text-xs text-zinc-500">{{ __('With') }}</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ $v->assignees->pluck('name')->join(', ') }}</dd></div>
                </dl>
                @if ($v->description)
                    <p class="rounded-lg bg-brand-50/50 px-4 py-3 text-sm leading-relaxed whitespace-pre-line text-zinc-700 dark:bg-brand-500/10 dark:text-zinc-200">{{ $v->description }}</p>
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
            </div>
        @endif
    </flux:modal>
</div>
