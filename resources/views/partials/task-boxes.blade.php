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
    <section class="grid items-start gap-4 grid-cols-[repeat(auto-fill,minmax(min(100%,18rem),1fr))]">
        @foreach ($boxes as $b)
            @php
                $tone = $palette[$b->color] ?? $palette['violet'];
                $total = $b->tasks->count();
                $done = $b->tasks->whereNotNull('completed_at')->count();
                $pct = $total ? (int) round($done / $total * 100) : 0;
                $overdue = $b->tasks->filter->isOverdue()->count();
                $people = $b->tasks->flatMap->assignees->unique('id')->values();
                $barColor = $pct === 100 ? '#10b981' : $tone['hex'];
            @endphp
            <article wire:key="box-{{ $b->id }}" x-data="{ all: false, adding: false }" data-test="box-{{ $b->id }}"
                class="group/box flex flex-col rounded-2xl bg-white p-4 shadow-[0_1px_3px_rgb(15_23_42/0.06)] ring-1 ring-zinc-200/80 transition hover:shadow-[0_10px_30px_-12px_rgb(15_23_42/0.2)] dark:bg-zinc-950 dark:ring-white/10">
                {{-- Title --}}
                <div class="flex items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white shadow-sm" style="background: {{ $tone['hex'] }}"><flux:icon.folder variant="mini" class="size-5" /></span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <h3 class="line-clamp-2 text-[0.9375rem] leading-snug font-semibold text-zinc-900 dark:text-white">{{ $b->name }}</h3>
                        <p class="mt-0.5 truncate text-xs text-zinc-500">
                            @if ($b->client)
                                <a href="{{ route('clients.show', $b->client) }}" wire:navigate class="hover:text-brand-600">{{ $b->client->displayName() }}</a>
                            @else
                                {{ __('Internal work') }}
                            @endif
                        </p>
                    </div>
                    <flux:dropdown align="end">
                        <button type="button" class="-me-1.5 -mt-1 rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Box actions') }}"><flux:icon.ellipsis-vertical variant="mini" class="size-4.5" /></button>
                        <flux:menu>
                            <flux:menu.item icon="plus" wire:click="newTaskIn({{ $b->id }})">{{ __('Add a detailed task') }}</flux:menu.item>
                            <flux:menu.item icon="pencil-square" wire:click="editBox({{ $b->id }})">{{ __('Edit box') }}</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteBox({{ $b->id }})">{{ __('Delete box') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                {{-- Due date and priority --}}
                @php
                    $bp = \App\Models\Task::PRIORITY_STYLES[$b->priority] ?? \App\Models\Task::PRIORITY_STYLES['medium'];
                    $boxLate = $b->due_date && $pct < 100 && $b->due_date->lt(today());
                @endphp
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <span class="{{ $bp['tone'] }} inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1"><flux:icon :icon="$bp['icon']" variant="micro" class="size-3" />{{ __(\App\Models\Task::PRIORITIES[$b->priority] ?? 'Medium') }}</span>
                    @if ($b->due_date)
                        <span @class(['inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1',
                            'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => $boxLate,
                            'bg-zinc-50 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10' => ! $boxLate])>
                            <flux:icon.calendar variant="micro" class="size-3" />{{ $b->due_date->isToday() ? __('Today') : $b->due_date->toNepali(false) }}
                        </span>
                    @endif
                    @if ($overdue)
                        <span class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-rose-600 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20"><flux:icon.clock variant="micro" class="size-3" />{{ trans_choice(':count late|:count late', $overdue) }}</span>
                    @endif
                </div>

                {{-- Progress --}}
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-zinc-500">{{ trans_choice(':done of :count task done|:done of :count tasks done', $total, ['done' => $done]) }}</span>
                        <span class="font-semibold tabular-nums" style="color: {{ $barColor }}" data-test="box-progress-{{ $b->id }}">{{ $pct }}%</span>
                    </div>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                        <div class="h-full rounded-full transition-all duration-500" style="width: {{ $pct }}%; background: {{ $barColor }}"></div>
                    </div>
                </div>

                {{-- Tasks --}}
                <ul class="mt-4 flex flex-col gap-0.5">
                    @forelse ($b->tasks as $i => $t)
                        @php $d = $due($t); @endphp
                        @php $tt = $typeOf($t->type); $tp = \App\Models\Task::PRIORITY_STYLES[$t->priority] ?? \App\Models\Task::PRIORITY_STYLES['medium']; @endphp
                        <li wire:key="bt-{{ $t->id }}" @if ($i >= 4) x-show="all" x-cloak @endif class="group -mx-2 flex items-start gap-2.5 rounded-lg px-2 py-1.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})" @class([
                                'mt-0.5 flex size-[1.125rem] shrink-0 items-center justify-center rounded-md border transition',
                                'border-emerald-500 bg-emerald-500 text-white' => $t->completed_at,
                                'border-zinc-300 hover:border-zinc-400 dark:border-white/20' => ! $t->completed_at,
                            ]) aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}" data-test="box-task-toggle-{{ $t->id }}">@if ($t->completed_at)<flux:icon.check variant="micro" class="size-3" />@endif</button>
                            <button type="button" wire:click="editTask({{ $t->id }})" class="min-w-0 flex-1 text-start">
                                <span @class(['block truncate text-sm', 'text-zinc-400 line-through' => $t->completed_at, 'text-zinc-800 group-hover:text-zinc-950 dark:text-zinc-100 dark:group-hover:text-white' => ! $t->completed_at])>{{ $t->title }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.6875rem] text-zinc-500">
                                    <span class="inline-flex items-center gap-1"><span class="{{ $toneOf($t->type)['dot'] }} size-1.5 rounded-full"></span>{{ $tt['label'] }}</span>
                                    @if (in_array($t->priority, ['high', 'urgent'], true) && ! $t->completed_at)
                                        <span class="inline-flex items-center gap-0.5 font-medium {{ $t->priority === 'urgent' ? 'text-rose-600 dark:text-rose-400' : 'text-orange-600 dark:text-orange-400' }}"><flux:icon :icon="$tp['icon']" variant="micro" class="size-3" />{{ __(\App\Models\Task::PRIORITIES[$t->priority]) }}</span>
                                    @endif
                                    @if ($d && ! $t->completed_at)<span class="{{ $d[0] }} inline-flex items-center gap-0.5"><flux:icon.calendar variant="micro" class="size-3" />{{ $d[1] }}</span>@endif
                                </span>
                            </button>
                            @if ($t->assignees->isNotEmpty())
                                <span class="mt-0.5 flex shrink-0 -space-x-1.5">@foreach ($t->assignees->take(2) as $a)<x-employee-avatar :employee="$a" class="size-5 text-[0.5rem] ring-2 ring-white dark:ring-zinc-950" />@endforeach</span>
                            @endif
                        </li>
                    @empty
                        <li class="rounded-lg bg-zinc-50 px-3 py-3 text-center text-xs text-zinc-500 dark:bg-white/[0.03]">{{ __('No tasks yet') }}</li>
                    @endforelse
                    @if ($total > 4)
                        <li>
                            <button type="button" @click="all = ! all" class="mt-1 text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                                <span x-show="! all">{{ trans_choice('Show :count more task|Show :count more tasks', $total - 4) }}</span><span x-show="all" x-cloak>{{ __('Show less') }}</span>
                            </button>
                        </li>
                    @endif
                </ul>

                {{-- Quick add (opens on click) --}}
                <form x-show="adding" x-cloak wire:submit="quickAdd({{ $b->id }})" @submit="$nextTick(() => $refs.quick.focus())" class="mt-2">
                    <input x-ref="quick" type="text" wire:model="quick.{{ $b->id }}" @keydown.escape="adding = false" placeholder="{{ __('Task name, then Enter') }}" class="h-9 w-full rounded-lg border-0 bg-white px-3 text-sm text-zinc-900 ring-1 ring-zinc-300 placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 dark:bg-white/5 dark:text-white dark:ring-white/15" data-test="quick-{{ $b->id }}">
                </form>
                @error("quick.{$b->id}")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror

                {{-- Footer --}}
                <div class="mt-4 flex items-center gap-2 border-t border-zinc-100 pt-4 dark:border-white/5">
                    @if ($people->isNotEmpty())
                        <span class="flex -space-x-2" title="{{ $people->pluck('name')->implode(', ') }}">
                            @foreach ($people->take(4) as $e)
                                <x-employee-avatar :employee="$e" class="size-7 text-[0.625rem] ring-2 ring-white dark:ring-zinc-950" />
                            @endforeach
                            @if ($people->count() > 4)<span class="flex size-7 items-center justify-center rounded-full bg-zinc-100 text-[0.625rem] font-semibold text-zinc-600 ring-2 ring-white dark:bg-white/10 dark:text-zinc-300 dark:ring-zinc-950">+{{ $people->count() - 4 }}</span>@endif
                        </span>
                    @else
                        <span class="text-xs text-zinc-400">{{ __('No one assigned') }}</span>
                    @endif
                    <button type="button" @click="adding = ! adding; $nextTick(() => adding && $refs.quick.focus())" class="ms-auto inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/10" data-test="quick-open-{{ $b->id }}">
                        <flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add task') }}
                    </button>
                </div>
            </article>
        @endforeach

        <button type="button" wire:click="newBox" class="flex min-h-48 flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-zinc-200 text-zinc-500 transition hover:border-brand-300 hover:bg-white hover:text-brand-600 dark:border-white/10 dark:hover:bg-white/5">
            <span class="flex size-10 items-center justify-center rounded-xl bg-zinc-100 dark:bg-white/5"><flux:icon.plus variant="mini" class="size-5" /></span>
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
