<?php

use App\Models\Task;
use App\Models\WorkType;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Work types')] class extends Component {
    public string $newName = '';

    /** @var array<int, string> */
    public array $names = [];

    public function mount(): void
    {
        $this->names = WorkType::pluck('name', 'id')->all();
    }

    #[Computed]
    public function types()
    {
        $counts = Task::selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        return WorkType::orderBy('position')->orderBy('id')->get()->each(fn ($t) => $t->uses = (int) ($counts[$t->slug] ?? 0));
    }

    public function add(): void
    {
        $this->validate(['newName' => ['required', 'string', 'max:40']], attributes: ['newName' => __('type name')]);
        $type = WorkType::add(trim($this->newName));
        $this->names[$type->id] = $type->name;
        $this->reset('newName');
        unset($this->types);
        Flux::toast(variant: 'success', text: __('Work type added.'));
    }

    public function rename(int $id): void
    {
        $this->validate(["names.$id" => ['required', 'string', 'max:40']], attributes: ["names.$id" => __('name')]);
        WorkType::findOrFail($id)->update(['name' => trim($this->names[$id])]);
        unset($this->types);
        Flux::toast(variant: 'success', text: __('Renamed.'));
    }

    public function recolor(int $id, string $color): void
    {
        abort_unless(in_array($color, [...WorkType::COLORS, 'zinc']), 422);
        WorkType::findOrFail($id)->update(['color' => $color]);
        unset($this->types);
    }

    public function move(int $id, int $by): void
    {
        $ids = $this->types->pluck('id')->values();
        $from = $ids->search($id);
        $to = max(0, min($ids->count() - 1, $from + $by));
        $ids->splice($from, 1);
        $ids->splice($to, 0, [$id]);
        foreach ($ids as $i => $typeId) {
            WorkType::whereKey($typeId)->update(['position' => $i]);
        }
        unset($this->types);
    }

    public function remove(int $id): void
    {
        if (WorkType::count() <= 1) {
            Flux::toast(variant: 'warning', text: __('Keep at least one type of work.'));

            return;
        }
        WorkType::findOrFail($id)->delete();
        unset($this->names[$id], $this->types);
        Flux::toast(text: __('Work type deleted. Tasks keep their name.'));
    }
}; ?>

@php
    $dots = [
        'sky' => 'bg-sky-500', 'fuchsia' => 'bg-fuchsia-500', 'amber' => 'bg-amber-400', 'rose' => 'bg-rose-500', 'orange' => 'bg-orange-500',
        'emerald' => 'bg-emerald-500', 'violet' => 'bg-violet-500', 'teal' => 'bg-teal-500', 'indigo' => 'bg-indigo-500', 'lime' => 'bg-lime-500',
        'pink' => 'bg-pink-500', 'cyan' => 'bg-cyan-500', 'zinc' => 'bg-zinc-400',
    ];
    $tiles = [
        'sky' => 'bg-sky-50 text-sky-600', 'fuchsia' => 'bg-fuchsia-50 text-fuchsia-600', 'amber' => 'bg-amber-50 text-amber-600', 'rose' => 'bg-rose-50 text-rose-600',
        'orange' => 'bg-orange-50 text-orange-600', 'emerald' => 'bg-emerald-50 text-emerald-600', 'violet' => 'bg-violet-50 text-violet-600', 'teal' => 'bg-teal-50 text-teal-600',
        'indigo' => 'bg-indigo-50 text-indigo-600', 'lime' => 'bg-lime-50 text-lime-700', 'pink' => 'bg-pink-50 text-pink-600', 'cyan' => 'bg-cyan-50 text-cyan-600', 'zinc' => 'bg-zinc-100 text-zinc-600',
    ];
    $types = $this->types;
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Work types')" :subheading="__('The kinds of work you plan for clients. The first four get their own box on every client\'s Tasks tab.')">
        {{-- Add --}}
        <form wire:submit="add" class="flex gap-2">
            <flux:input wire:model="newName" :placeholder="__('Add a work type, e.g. Blog article')" icon="plus" class="flex-1" data-test="new-work-type" />
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </form>
        <flux:error name="newName" />

        {{-- List --}}
        <ul class="mt-6 divide-y divide-zinc-100 overflow-hidden rounded-xl ring-1 ring-zinc-200/70 dark:divide-white/5 dark:ring-white/10">
            @foreach ($types as $i => $type)
                <li wire:key="wt-{{ $type->id }}" class="group flex flex-wrap items-center gap-3 bg-white px-4 py-3 transition hover:bg-zinc-50/70 sm:flex-nowrap dark:bg-zinc-950 dark:hover:bg-white/[0.02]">
                    <span class="w-5 text-center text-xs font-semibold text-zinc-400 tabular-nums">{{ $i + 1 }}</span>
                    <span class="{{ $tiles[$type->color] ?? $tiles['zinc'] }} flex size-9 shrink-0 items-center justify-center rounded-lg"><flux:icon :icon="$type->icon" variant="mini" class="size-4.5" /></span>

                    <form wire:submit="rename({{ $type->id }})" class="min-w-0 flex-1">
                        <input wire:model="names.{{ $type->id }}" wire:blur="rename({{ $type->id }})" type="text" maxlength="40" class="w-full rounded-lg border-0 bg-transparent px-2 py-1.5 text-sm font-semibold text-zinc-900 ring-1 ring-transparent transition hover:ring-zinc-200 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none dark:text-white dark:hover:ring-white/10 dark:focus:bg-zinc-900" aria-label="{{ __('Name of :type', ['type' => $type->name]) }}" />
                    </form>

                    @if ($i < 4)
                        <span class="hidden rounded-full bg-brand-50 px-2 py-0.5 text-[0.6875rem] font-semibold text-brand-700 sm:inline dark:bg-brand-500/15 dark:text-brand-200">{{ __('Main box') }}</span>
                    @endif
                    <span class="w-20 shrink-0 text-end text-xs text-zinc-500 tabular-nums">{{ trans_choice(':count task|:count tasks', $type->uses) }}</span>

                    {{-- Colour --}}
                    <flux:dropdown align="end">
                        <button type="button" class="flex size-8 items-center justify-center rounded-lg ring-1 ring-zinc-200 transition hover:ring-zinc-300 dark:ring-white/10" aria-label="{{ __('Colour') }}" title="{{ __('Colour') }}"><span class="{{ $dots[$type->color] ?? $dots['zinc'] }} size-4 rounded-full"></span></button>
                        <flux:menu>
                            <div class="grid grid-cols-7 gap-1.5 p-1.5">
                                @foreach ($dots as $c => $dot)
                                    <button type="button" wire:click="recolor({{ $type->id }}, '{{ $c }}')" class="flex size-7 items-center justify-center rounded-full transition hover:scale-110" aria-label="{{ $c }}"><span @class([$dot, 'size-5 rounded-full', 'ring-2 ring-zinc-900 ring-offset-2 dark:ring-white dark:ring-offset-zinc-900' => $type->color === $c])></span></button>
                                @endforeach
                            </div>
                        </flux:menu>
                    </flux:dropdown>

                    {{-- Order + delete --}}
                    <div class="flex items-center">
                        <button type="button" wire:click="move({{ $type->id }}, -1)" @disabled($i === 0) class="flex size-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 disabled:opacity-30 dark:hover:bg-white/10" aria-label="{{ __('Move up') }}"><flux:icon.chevron-up variant="micro" class="size-4" /></button>
                        <button type="button" wire:click="move({{ $type->id }}, 1)" @disabled($loop->last) class="flex size-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 disabled:opacity-30 dark:hover:bg-white/10" aria-label="{{ __('Move down') }}"><flux:icon.chevron-down variant="micro" class="size-4" /></button>
                        <button type="button" wire:click="remove({{ $type->id }})" wire:confirm="{{ __('Delete “:name”? Tasks already using it keep the name.', ['name' => $type->name]) }}" class="flex size-8 items-center justify-center rounded-lg text-zinc-300 transition hover:bg-rose-50 hover:text-rose-500 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete :name', ['name' => $type->name]) }}"><flux:icon.trash variant="micro" class="size-4" /></button>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 flex items-center gap-1.5 text-xs text-zinc-500"><flux:icon.information-circle variant="micro" class="size-4" />{{ __('Click a name to rename it. Use the arrows to change the order.') }}</p>
    </x-pages::settings.layout>
</section>
