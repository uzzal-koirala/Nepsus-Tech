{{-- Boxes view of the Tasks page: a titled box of tasks with progress and the people on it. --}}
@php
    $boxes = $this->boxList;
    $palette = \App\Models\WorkType::PALETTE;
@endphp

@if ($boxes->isEmpty())
    <section class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
        <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.archive-box class="size-8" /></span>
        <div>
            <p class="font-semibold text-zinc-900 dark:text-white">{{ __('No boxes yet') }}</p>
            <p class="mt-1 max-w-sm text-sm text-zinc-500">{{ __('A box groups the tasks of one piece of work, like "Dashain campaign" or "New website", and shows how far along it is.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newBox">{{ __('Create a box') }}</flux:button>
    </section>
@else
    <section class="grid items-start gap-5 grid-cols-[repeat(auto-fill,minmax(min(100%,18rem),1fr))]">
        @foreach ($boxes as $b)
            @php
                $accent = ($palette[$b->color] ?? $palette['violet'])['hex'];
                $total = $b->tasks->count();
                $done = $b->tasks->whereNotNull('completed_at')->count();
                $pct = $total ? (int) round($done / $total * 100) : 0;
                $overdue = $b->tasks->filter->isOverdue()->count();
                $people = $b->tasks->flatMap->assignees->unique('id')->values();
                $boxLate = $b->due_date && $pct < 100 && $b->due_date->lt(today());
                // One neutral style for people without a photo, so the card stays calm.
                $face = fn ($e, $size) => $e->avatarUrl()
                    ? '<img src="'.e($e->avatarUrl()).'" alt="" title="'.e($e->name).'" class="'.$size.' shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-zinc-900">'
                    : '<span title="'.e($e->name).'" class="'.$size.' flex shrink-0 items-center justify-center rounded-full bg-zinc-100 font-semibold text-zinc-600 ring-2 ring-white dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-900">'.e($e->initials()).'</span>';
            @endphp
            <article wire:key="box-{{ $b->id }}" x-data="{ all: false, adding: false }" data-test="box-{{ $b->id }}"
                class="flex flex-col rounded-3xl bg-white p-2 shadow-[0_1px_2px_rgb(15_23_42/0.05),0_8px_24px_-12px_rgb(15_23_42/0.12)] transition hover:shadow-[0_1px_2px_rgb(15_23_42/0.05),0_16px_36px_-14px_rgb(15_23_42/0.2)] dark:bg-zinc-900">

                {{-- Header: tinted with the box colour --}}
                <div class="rounded-2xl p-4" style="background: {{ $accent }}14">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white shadow-md" style="background: {{ $accent }}; box-shadow: 0 6px 14px -6px {{ $accent }}"><flux:icon.folder variant="mini" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-[0.9375rem] leading-snug font-semibold text-zinc-900 dark:text-white">{{ $b->name }}</h3>
                            <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $b->client?->displayName() ?? __('Internal') }}@if ($b->due_date) · <span @class(['font-medium text-rose-600 dark:text-rose-400' => $boxLate])>{{ __('Due :date', ['date' => $b->due_date->isToday() ? __('today') : $b->due_date->toNepali(false)]) }}</span>@endif
                            </p>
                        </div>
                        <flux:dropdown align="end">
                            <button type="button" class="-me-1.5 -mt-1 rounded-lg p-1.5 text-zinc-500 transition hover:bg-white/70 hover:text-zinc-800 dark:hover:bg-white/10" aria-label="{{ __('Box actions') }}"><flux:icon.ellipsis-horizontal variant="mini" class="size-5" /></button>
                            <flux:menu>
                                <flux:menu.item icon="plus" wire:click="newTaskIn({{ $b->id }})">{{ __('Add a detailed task') }}</flux:menu.item>
                                <flux:menu.item icon="pencil-square" wire:click="editBox({{ $b->id }})">{{ __('Edit box') }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteBox({{ $b->id }})">{{ __('Delete box') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    <div class="mt-4 flex items-end justify-between">
                        <p class="text-[1.625rem] leading-none font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white" data-test="box-progress-{{ $b->id }}">{{ $pct }}<span class="text-base font-semibold text-zinc-400">%</span></p>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                            {{ __(':done / :total done', ['done' => $done, 'total' => $total]) }}@if ($overdue)<span class="text-rose-600 dark:text-rose-400"> · {{ trans_choice(':count late|:count late', $overdue) }}</span>@endif
                        </p>
                    </div>
                    <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-white/80 dark:bg-white/10">
                        <div class="h-full rounded-full transition-all duration-500" style="width: {{ $pct }}%; background: {{ $accent }}"></div>
                    </div>
                </div>

                {{-- Tasks --}}
                <ul class="flex flex-col px-2 pt-3">
                    @forelse ($b->tasks as $i => $t)
                        @php
                            $d = $due($t);
                            $urgent = ! $t->completed_at && in_array($t->priority, ['high', 'urgent'], true);
                        @endphp
                        <li wire:key="bt-{{ $t->id }}" @if ($i >= 4) x-show="all" x-cloak @endif class="group flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-zinc-50 dark:hover:bg-white/[0.04]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})"
                                class="flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition {{ $t->completed_at ? 'text-white' : 'text-transparent hover:text-zinc-300' }}"
                                style="border-color: {{ $t->completed_at ? $accent : $accent.'55' }}; {{ $t->completed_at ? 'background: '.$accent : '' }}"
                                aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}" data-test="box-task-toggle-{{ $t->id }}"><flux:icon.check variant="micro" class="size-3" /></button>
                            <button type="button" wire:click="editTask({{ $t->id }})" class="min-w-0 flex-1 text-start">
                                <span @class(['block truncate text-sm', 'text-zinc-400 line-through' => $t->completed_at, 'font-medium text-zinc-800 dark:text-zinc-100' => ! $t->completed_at])>{{ $t->title }}</span>
                                @if (! $t->completed_at && ($d || $urgent))
                                    <span class="mt-0.5 flex items-center gap-2 text-xs text-zinc-400">
                                        @if ($d)<span @class(['inline-flex items-center gap-1', 'text-rose-600 dark:text-rose-400' => $t->isOverdue()])><flux:icon.calendar variant="micro" class="size-3" />{{ $d[1] }}</span>@endif
                                        @if ($urgent)<span class="inline-flex items-center gap-1"><flux:icon.flag variant="micro" class="size-3" />{{ __(\App\Models\Task::PRIORITIES[$t->priority]) }}</span>@endif
                                    </span>
                                @endif
                            </button>
                            @if ($t->assignees->isNotEmpty())
                                <span class="flex shrink-0 -space-x-1.5">@foreach ($t->assignees->take(2) as $a){!! $face($a, 'size-6 text-[0.5625rem]') !!}@endforeach</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-2 py-3 text-center text-sm text-zinc-400">{{ __('No tasks yet') }}</li>
                    @endforelse
                    @if ($total > 4)
                        <li class="px-2 pt-1">
                            <button type="button" @click="all = ! all" class="text-xs font-medium hover:underline" style="color: {{ $accent }}">
                                <span x-show="! all">{{ trans_choice('Show :count more task|Show :count more tasks', $total - 4) }}</span><span x-show="all" x-cloak>{{ __('Show less') }}</span>
                            </button>
                        </li>
                    @endif
                </ul>

                {{-- Quick add --}}
                <form x-show="adding" x-cloak wire:submit="quickAdd({{ $b->id }})" class="px-2 pt-2">
                    <input x-ref="quick" type="text" wire:model="quick.{{ $b->id }}" @keydown.escape="adding = false" placeholder="{{ __('Task name, then Enter') }}" class="h-10 w-full rounded-xl border-0 bg-zinc-100 px-3.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:bg-white focus:ring-2 dark:bg-white/10 dark:text-white" style="--tw-ring-color: {{ $accent }}66" data-test="quick-{{ $b->id }}">
                </form>
                @error("quick.{$b->id}")<p class="px-4 pt-1 text-xs text-rose-600">{{ $message }}</p>@enderror

                {{-- Team and add --}}
                <div class="flex items-center gap-2 px-4 pt-3 pb-3">
                    @if ($people->isNotEmpty())
                        <span class="flex -space-x-2">
                            @foreach ($people->take(4) as $e){!! $face($e, 'size-7 text-[0.625rem]') !!}@endforeach
                        </span>
                        @if ($people->count() > 4)<span class="text-xs text-zinc-500">+{{ $people->count() - 4 }}</span>@endif
                    @else
                        <span class="text-xs text-zinc-400">{{ __('No one assigned') }}</span>
                    @endif
                    <button type="button" @click="adding = ! adding; $nextTick(() => adding && $refs.quick.focus())" class="ms-auto inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold transition hover:brightness-95" style="color: {{ $accent }}; background: {{ $accent }}14" data-test="quick-open-{{ $b->id }}">
                        <flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add task') }}
                    </button>
                </div>
            </article>
        @endforeach

        <button type="button" wire:click="newBox" class="flex min-h-56 flex-col items-center justify-center gap-3 rounded-3xl bg-zinc-200/40 text-zinc-500 transition hover:bg-zinc-200/70 hover:text-zinc-800 dark:bg-white/5 dark:hover:bg-white/10 dark:hover:text-white">
            <span class="flex size-11 items-center justify-center rounded-2xl bg-white shadow-sm dark:bg-white/10"><flux:icon.plus variant="mini" class="size-5" /></span>
            <span class="text-sm font-medium">{{ __('New box') }}</span>
        </button>
    </section>
@endif

{{-- Add / edit box --}}
<flux:modal name="box-form" class="w-full max-w-lg overflow-visible!">
    <form wire:submit="saveBox" class="flex flex-col gap-5">
        <div class="flex items-center gap-3">
            <span class="{{ ($palette[$box['color'] ?? 'violet'] ?? $palette['violet'])['dot'] }} flex size-10 items-center justify-center rounded-xl text-white shadow-md transition"><flux:icon.archive-box variant="mini" class="size-5" /></span>
            <div>
                <flux:heading size="lg">{{ $editingBoxId ? __('Edit box') : __('New box') }}</flux:heading>
                <flux:text>{{ __('Give the work a title, then add its tasks inside.') }}</flux:text>
            </div>
        </div>
        <flux:input wire:model="box.name" :label="__('Title')" :placeholder="__('e.g. Dashain campaign')" required data-test="box-name" />
        @php
            $boxClientOptions = collect([['value' => '', 'label' => __('No client'), 'hint' => __('Internal work for the team'), 'icon' => 'home']])
                ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->company ? $c->name : $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
        @endphp
        <x-rich-select model="box.client_id" :label="__('Client (optional)')" :options="$boxClientOptions" searchable />
        @php
            $boxPriorityOptions = collect(\App\Models\Task::PRIORITIES)->map(fn ($label, $k) => ['value' => $k, 'label' => __($label), 'icon' => \App\Models\Task::PRIORITY_STYLES[$k]['icon'], 'tile' => \App\Models\Task::PRIORITY_STYLES[$k]['tone']])->values();
        @endphp
        <div class="grid gap-4 sm:grid-cols-2">
            <x-nepali-date wire:model="box.due_date" :label="__('Due date (optional)')" />
            <x-rich-select model="box.priority" :label="__('Priority')" :options="$boxPriorityOptions" />
        </div>
        <div>
            <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Colour') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($palette as $key => $c)
                    @continue($key === 'zinc')
                    <label class="cursor-pointer">
                        <input type="radio" wire:model.live="box.color" value="{{ $key }}" class="peer sr-only">
                        <span class="{{ $c['dot'] }} block size-8 rounded-full ring-2 ring-transparent ring-offset-2 transition peer-checked:ring-zinc-900 hover:scale-110 dark:ring-offset-zinc-900 dark:peer-checked:ring-white" title="{{ ucfirst($key) }}"></span>
                    </label>
                @endforeach
            </div>
        </div>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary" icon="check" data-test="save-box">{{ $editingBoxId ? __('Save box') : __('Create box') }}</flux:button>
        </div>
    </form>
</flux:modal>

<flux:modal name="box-delete" class="w-full max-w-sm">
    <div class="flex flex-col items-center gap-4 text-center">
        <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.trash class="size-7" /></span>
        <div>
            <flux:heading size="lg">{{ __('Delete this box?') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The box and every task inside it will be deleted.') }}</flux:text>
        </div>
        <div class="flex w-full gap-2">
            <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button variant="danger" wire:click="deleteBox" class="flex-1">{{ __('Delete') }}</flux:button>
        </div>
    </div>
</flux:modal>
