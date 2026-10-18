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
    <section class="grid items-start gap-5 md:grid-cols-2 2xl:grid-cols-3">
        @foreach ($boxes as $b)
            @php
                $tone = $palette[$b->color] ?? $palette['violet'];
                $total = $b->tasks->count();
                $done = $b->tasks->whereNotNull('completed_at')->count();
                $pct = $total ? (int) round($done / $total * 100) : 0;
                $overdue = $b->tasks->filter->isOverdue()->count();
                $people = $b->tasks->flatMap->assignees->unique('id')->values();
            @endphp
            <article wire:key="box-{{ $b->id }}" class="{{ $panel }} flex flex-col overflow-hidden" x-data="{ all: false }" data-test="box-{{ $b->id }}">
                <div class="{{ $tone['dot'] }} h-1.5"></div>
                <div class="flex flex-col gap-4 p-5">
                    {{-- Title --}}
                    <div class="flex items-start gap-3">
                        <span class="{{ $tone['dot'] }} flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow-md"><flux:icon.archive-box variant="mini" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate text-base font-semibold text-zinc-900 dark:text-white">{{ $b->name }}</h3>
                            <p class="flex items-center gap-1 truncate text-xs text-zinc-500">
                                @if ($b->client)
                                    <flux:icon.building-office-2 variant="micro" class="size-3 shrink-0" /><a href="{{ route('clients.show', $b->client) }}" wire:navigate class="truncate hover:text-brand-600">{{ $b->client->displayName() }}</a>
                                @else
                                    <flux:icon.home variant="micro" class="size-3 shrink-0" />{{ __('Internal') }}
                                @endif
                                <span class="text-zinc-300 dark:text-zinc-600">·</span>{{ trans_choice(':count task|:count tasks', $total) }}
                            </p>
                        </div>
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Box actions')" />
                            <flux:menu>
                                <flux:menu.item icon="plus" wire:click="newTaskIn({{ $b->id }})">{{ __('Add a detailed task') }}</flux:menu.item>
                                <flux:menu.item icon="pencil-square" wire:click="editBox({{ $b->id }})">{{ __('Edit box') }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteBox({{ $b->id }})">{{ __('Delete box') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    {{-- Progress --}}
                    <div class="rounded-xl bg-zinc-50 p-3.5 dark:bg-white/[0.03]">
                        <div class="flex items-end justify-between gap-2">
                            <p class="text-2xl leading-none font-bold text-zinc-900 tabular-nums dark:text-white" data-test="box-progress-{{ $b->id }}">{{ $pct }}%</p>
                            <p class="text-xs text-zinc-500">
                                {{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}
                                @if ($overdue)<span class="font-semibold text-rose-600 dark:text-rose-400"> · {{ trans_choice(':count overdue|:count overdue', $overdue) }}</span>@endif
                            </p>
                        </div>
                        <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-zinc-200/70 dark:bg-white/10">
                            <div class="{{ $pct === 100 ? 'bg-emerald-500' : $tone['dot'] }} h-full rounded-full transition-all duration-500" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>

                    {{-- People --}}
                    <div class="flex items-center gap-2.5">
                        @if ($people->isNotEmpty())
                            <span class="flex -space-x-2">
                                @foreach ($people->take(5) as $e)
                                    <x-employee-avatar :employee="$e" class="size-8 text-[0.6875rem] ring-2 ring-white dark:ring-zinc-950" />
                                @endforeach
                                @if ($people->count() > 5)<span class="flex size-8 items-center justify-center rounded-full bg-zinc-100 text-[0.6875rem] font-semibold text-zinc-600 ring-2 ring-white dark:bg-white/10 dark:text-zinc-300 dark:ring-zinc-950">+{{ $people->count() - 5 }}</span>@endif
                            </span>
                            <p class="min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-300">
                                {{ $people->take(2)->pluck('name')->implode(', ') }}@if ($people->count() > 2) {{ __('and :count more', ['count' => $people->count() - 2]) }}@endif
                            </p>
                        @else
                            <span class="flex size-8 items-center justify-center rounded-full border-2 border-dashed border-zinc-300 text-zinc-400 dark:border-white/20"><flux:icon.user variant="micro" class="size-4" /></span>
                            <p class="text-xs text-zinc-500">{{ __('Nobody assigned yet') }}</p>
                        @endif
                    </div>

                    {{-- Tasks --}}
                    <div class="flex flex-col gap-0.5 border-t border-zinc-100 pt-3 dark:border-white/5">
                        @forelse ($b->tasks as $i => $t)
                            @php $d = $due($t); @endphp
                            <div wire:key="bt-{{ $t->id }}" @if ($i >= 5) x-show="all" x-cloak @endif class="group flex items-center gap-2.5 rounded-lg px-1.5 py-1.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <button type="button" wire:click="toggleDone({{ $t->id }})" @class([
                                    'flex size-5 shrink-0 items-center justify-center rounded-md ring-1 transition',
                                    'bg-emerald-500 text-white ring-emerald-500' => $t->completed_at,
                                    'ring-zinc-300 hover:ring-brand-500 dark:ring-white/20' => ! $t->completed_at,
                                ]) aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}" data-test="box-task-toggle-{{ $t->id }}">@if ($t->completed_at)<flux:icon.check variant="micro" class="size-3.5" />@endif</button>
                                <button type="button" wire:click="editTask({{ $t->id }})" class="min-w-0 flex-1 text-start">
                                    <span @class(['block truncate text-sm', 'text-zinc-400 line-through' => $t->completed_at, 'font-medium text-zinc-800 group-hover:text-brand-600 dark:text-zinc-100' => ! $t->completed_at])>{{ $t->title }}</span>
                                </button>
                                @if ($d && ! $t->completed_at)<span class="{{ $d[0] }} shrink-0 text-[0.6875rem] font-medium">{{ $d[1] }}</span>@endif
                                @if ($t->assignees->isNotEmpty())
                                    <span class="flex shrink-0 -space-x-1.5">
                                        @foreach ($t->assignees->take(2) as $a)<x-employee-avatar :employee="$a" class="size-6 text-[0.5625rem] ring-2 ring-white dark:ring-zinc-950" />@endforeach
                                    </span>
                                @endif
                            </div>
                        @empty
                            <p class="px-1.5 py-2 text-sm text-zinc-500">{{ __('No tasks in this box yet. Add the first one below.') }}</p>
                        @endforelse
                        @if ($total > 5)
                            <button type="button" @click="all = ! all" class="mt-1 self-start rounded-md px-1.5 py-1 text-xs font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">
                                <span x-show="! all">{{ __('Show :count more', ['count' => $total - 5]) }}</span><span x-show="all" x-cloak>{{ __('Show less') }}</span>
                            </button>
                        @endif
                    </div>

                    {{-- Quick add --}}
                    <form wire:submit="quickAdd({{ $b->id }})" class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <flux:icon.plus variant="micro" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400" />
                            <input type="text" wire:model="quick.{{ $b->id }}" placeholder="{{ __('Add a task and press Enter') }}" class="h-9 w-full rounded-lg border-0 bg-zinc-50 ps-9 pe-3 text-sm text-zinc-900 ring-1 ring-zinc-200/70 placeholder:text-zinc-400 focus:bg-white focus:ring-2 focus:ring-brand-500 dark:bg-white/5 dark:text-white dark:ring-white/10" data-test="quick-{{ $b->id }}">
                        </div>
                        <flux:button size="sm" variant="ghost" icon="adjustments-horizontal" wire:click="newTaskIn({{ $b->id }})" :aria-label="__('Add with details')" :tooltip="__('Add with date, people and more')" />
                    </form>
                    <flux:error name="quick.{{ $b->id }}" />
                </div>
            </article>
        @endforeach

        <button type="button" wire:click="newBox" class="flex min-h-64 flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-zinc-200 text-zinc-400 transition hover:border-brand-300 hover:bg-brand-50/40 hover:text-brand-600 dark:border-white/10 dark:hover:bg-brand-500/5">
            <span class="flex size-12 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-white/5"><flux:icon.plus class="size-6" /></span>
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
