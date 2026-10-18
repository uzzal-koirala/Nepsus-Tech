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
    <section class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
        @foreach ($boxes as $b)
            @php
                $tone = $palette[$b->color] ?? $palette['violet'];
                $total = $b->tasks->count();
                $done = $b->tasks->whereNotNull('completed_at')->count();
                $pct = $total ? (int) round($done / $total * 100) : 0;
                $overdue = $b->tasks->filter->isOverdue()->count();
                $people = $b->tasks->flatMap->assignees->unique('id')->values();
                $ringColor = $pct === 100 ? '#10b981' : $tone['hex'];
            @endphp
            <article wire:key="box-{{ $b->id }}" x-data="{ all: false }" data-test="box-{{ $b->id }}"
                class="group/box relative flex flex-col overflow-hidden rounded-2xl bg-white shadow-[0_1px_2px_rgb(15_23_42/0.04)] ring-1 ring-zinc-200/70 transition hover:-translate-y-0.5 hover:shadow-[0_12px_32px_-12px_rgb(15_23_42/0.18)] dark:bg-zinc-950 dark:ring-white/10">
                {{-- Soft colour wash behind the header --}}
                <div class="pointer-events-none absolute inset-x-0 top-0 h-20 opacity-[0.09] dark:opacity-20" style="background: linear-gradient(180deg, {{ $tone['hex'] }}, transparent)"></div>

                <div class="relative flex items-center gap-3 px-4 pt-4">
                    {{-- Progress ring --}}
                    <div class="relative size-11 shrink-0" title="{{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}">
                        <svg viewBox="0 0 36 36" class="size-11 -rotate-90">
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" class="stroke-zinc-100 dark:stroke-white/10" />
                            @if ($pct > 0)<circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" stroke-linecap="round" stroke="{{ $ringColor }}" stroke-dasharray="{{ round(97.4 * $pct / 100, 2) }} 97.4" class="transition-all duration-500" />@endif
                        </svg>
                        <span class="absolute inset-0 flex items-center justify-center text-[0.6875rem] font-bold text-zinc-900 tabular-nums dark:text-white" data-test="box-progress-{{ $b->id }}">{{ $pct }}%</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $b->name }}</h3>
                        <p class="flex items-center gap-1 truncate text-[0.6875rem] text-zinc-500">
                            @if ($b->client)
                                <a href="{{ route('clients.show', $b->client) }}" wire:navigate class="truncate hover:text-brand-600">{{ $b->client->displayName() }}</a>
                            @else
                                {{ __('Internal') }}
                            @endif
                            <span class="text-zinc-300 dark:text-zinc-600">·</span><span class="shrink-0">{{ __(':done/:total done', ['done' => $done, 'total' => $total]) }}</span>
                        </p>
                    </div>
                    <flux:dropdown align="end">
                        <button type="button" class="rounded-md p-1 text-zinc-400 opacity-60 transition group-hover/box:opacity-100 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Box actions') }}"><flux:icon.ellipsis-horizontal variant="micro" class="size-4" /></button>
                        <flux:menu>
                            <flux:menu.item icon="plus" wire:click="newTaskIn({{ $b->id }})">{{ __('Add a detailed task') }}</flux:menu.item>
                            <flux:menu.item icon="pencil-square" wire:click="editBox({{ $b->id }})">{{ __('Edit box') }}</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteBox({{ $b->id }})">{{ __('Delete box') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                {{-- People and warnings --}}
                <div class="relative flex min-h-6 items-center gap-2 px-4 pt-3">
                    @if ($people->isNotEmpty())
                        <span class="flex -space-x-1.5">
                            @foreach ($people->take(4) as $e)
                                <x-employee-avatar :employee="$e" class="size-6 text-[0.5625rem] ring-2 ring-white dark:ring-zinc-950" />
                            @endforeach
                            @if ($people->count() > 4)<span class="flex size-6 items-center justify-center rounded-full bg-zinc-100 text-[0.5625rem] font-semibold text-zinc-600 ring-2 ring-white dark:bg-white/10 dark:text-zinc-300 dark:ring-zinc-950">+{{ $people->count() - 4 }}</span>@endif
                        </span>
                        <span class="min-w-0 truncate text-[0.6875rem] text-zinc-500">{{ $people->count() === 1 ? $people->first()->name : trans_choice(':count people|:count people', $people->count()) }}</span>
                    @else
                        <span class="text-[0.6875rem] text-zinc-400">{{ __('Nobody assigned') }}</span>
                    @endif
                    @if ($overdue)
                        <span class="ms-auto shrink-0 rounded-full bg-rose-50 px-1.5 py-0.5 text-[0.625rem] font-semibold text-rose-600 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">{{ trans_choice(':count late|:count late', $overdue) }}</span>
                    @endif
                </div>

                {{-- Tasks --}}
                <div class="relative mt-3 flex flex-col border-t border-zinc-100 px-2 py-1.5 dark:border-white/5">
                    @forelse ($b->tasks as $i => $t)
                        @php $d = $due($t); @endphp
                        <div wire:key="bt-{{ $t->id }}" @if ($i >= 4) x-show="all" x-cloak @endif class="group flex items-center gap-2 rounded-md px-2 py-1 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <button type="button" wire:click="toggleDone({{ $t->id }})" @class([
                                'flex size-4 shrink-0 items-center justify-center rounded-full ring-1 transition',
                                'bg-emerald-500 text-white ring-emerald-500' => $t->completed_at,
                                'ring-zinc-300 hover:ring-brand-500 dark:ring-white/20' => ! $t->completed_at,
                            ]) aria-label="{{ $t->completed_at ? __('Mark as not done') : __('Mark as done') }}" data-test="box-task-toggle-{{ $t->id }}">@if ($t->completed_at)<flux:icon.check variant="micro" class="size-3" />@endif</button>
                            <button type="button" wire:click="editTask({{ $t->id }})" class="min-w-0 flex-1 text-start">
                                <span @class(['block truncate text-[0.8125rem]', 'text-zinc-400 line-through' => $t->completed_at, 'text-zinc-700 group-hover:text-zinc-950 dark:text-zinc-200 dark:group-hover:text-white' => ! $t->completed_at])>{{ $t->title }}</span>
                            </button>
                            @if ($d && ! $t->completed_at)<span class="{{ $d[0] }} shrink-0 text-[0.625rem] font-medium">{{ $d[1] }}</span>@endif
                            @if ($t->assignees->isNotEmpty())<x-employee-avatar :employee="$t->assignees->first()" class="size-5 text-[0.5rem]" />@endif
                        </div>
                    @empty
                        <p class="px-2 py-1.5 text-xs text-zinc-400">{{ __('No tasks yet') }}</p>
                    @endforelse
                    @if ($total > 4)
                        <button type="button" @click="all = ! all" class="mx-2 mt-0.5 self-start text-[0.6875rem] font-medium text-zinc-500 hover:text-brand-600">
                            <span x-show="! all">{{ __('+ :count more', ['count' => $total - 4]) }}</span><span x-show="all" x-cloak>{{ __('Show less') }}</span>
                        </button>
                    @endif
                </div>

                {{-- Quick add --}}
                <form wire:submit="quickAdd({{ $b->id }})" class="relative flex items-center gap-1 border-t border-zinc-100 px-2 py-1.5 dark:border-white/5">
                    <flux:icon.plus variant="micro" class="pointer-events-none absolute top-1/2 left-4 size-3.5 -translate-y-1/2 text-zinc-400" />
                    <input type="text" wire:model="quick.{{ $b->id }}" placeholder="{{ __('Add a task...') }}" class="h-8 min-w-0 flex-1 rounded-md border-0 bg-transparent ps-7 pe-2 text-[0.8125rem] text-zinc-900 placeholder:text-zinc-400 focus:bg-zinc-50 focus:ring-0 focus:outline-none dark:text-white dark:focus:bg-white/5" data-test="quick-{{ $b->id }}">
                    <button type="button" wire:click="newTaskIn({{ $b->id }})" class="rounded-md p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" title="{{ __('Add with date, people and more') }}" aria-label="{{ __('Add with details') }}"><flux:icon.adjustments-horizontal variant="micro" class="size-4" /></button>
                </form>
                @error("quick.{$b->id}")<p class="px-4 pb-2 text-xs text-rose-600">{{ $message }}</p>@enderror
            </article>
        @endforeach

        <button type="button" wire:click="newBox" class="flex min-h-40 flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-zinc-200 text-zinc-400 transition hover:border-brand-300 hover:bg-brand-50/40 hover:text-brand-600 dark:border-white/10 dark:hover:bg-brand-500/5">
            <span class="flex size-9 items-center justify-center rounded-xl bg-zinc-100 dark:bg-white/5"><flux:icon.plus class="size-5" /></span>
            <span class="text-xs font-medium">{{ __('New box') }}</span>
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
