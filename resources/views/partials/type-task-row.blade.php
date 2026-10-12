{{-- A task row inside a work-type box. Needs $t, $statusTones and $range from the profile page. --}}
@php
    $stage = $t->stage();
    $done = $stage === 'done';
    $late = $t->isOverdue();
    $dates = $range($t);
    $people = $t->assignees;
@endphp
<li wire:key="{{ $prefix ?? 'row' }}-{{ $t->id }}" wire:click="openTask({{ $t->id }})" class="group flex cursor-pointer items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]" role="button" tabindex="0" @keydown.enter="$wire.openTask({{ $t->id }})">
    <button type="button" wire:click.stop="toggleTask({{ $t->id }})" @class([
        'flex size-5 shrink-0 items-center justify-center rounded-full border-[1.5px] transition',
        'border-emerald-500 bg-emerald-500 text-white' => $done,
        'border-zinc-300 text-transparent hover:border-emerald-500 hover:bg-emerald-50 hover:text-emerald-500 dark:border-zinc-600 dark:hover:bg-emerald-500/10' => ! $done,
    ]) title="{{ $done ? __('Mark as not done') : __('Mark as done') }}" aria-label="{{ $done ? __('Mark as not done') : __('Mark as done') }}"><flux:icon.check variant="micro" class="size-3" /></button>

    <div class="min-w-0 flex-1">
        <p class="flex items-center gap-1.5">
            <span @class(['truncate text-sm', 'text-zinc-400 line-through decoration-zinc-300' => $done, 'font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white' => ! $done])>{{ $t->title }}</span>
            @if (! $done && in_array($t->priority, ['high', 'urgent']))
                <flux:icon.flag variant="micro" @class(['size-3.5 shrink-0', 'text-orange-500' => $t->priority === 'high', 'text-rose-500' => $t->priority === 'urgent']) />
            @endif
        </p>
        <p class="mt-0.5 flex min-w-0 items-center gap-2 overflow-hidden text-xs whitespace-nowrap text-zinc-500">
            @if ($done)
                <span class="text-emerald-600 dark:text-emerald-400">{{ __('Done :date', ['date' => $t->completed_at->toNepali(withYear: false)]) }}</span>
            @else
                <span class="inline-flex shrink-0 items-center gap-1"><span class="{{ $statusTones[$stage]['dot'] }} size-1.5 rounded-full"></span>{{ __(\App\Models\Task::STATUSES[$stage]['label']) }}</span>
                @if ($dates)
                    <span @class(['inline-flex items-center gap-1', 'font-medium text-rose-600 dark:text-rose-400' => $late])>
                        <flux:icon :icon="$late ? 'exclamation-circle' : 'calendar'" variant="micro" class="size-3" />{{ $dates }}
                    </span>
                @endif
            @endif
        </p>
    </div>

    @if ($people->isNotEmpty())
        <div @class(['flex shrink-0 -space-x-1.5', 'opacity-50' => $done])>
            @foreach ($people->take(2) as $person)
                <x-employee-avatar :employee="$person" class="size-7 text-[0.625rem] ring-2 ring-white dark:ring-zinc-950" />
            @endforeach
            @if ($people->count() > 2)
                <span class="flex size-7 items-center justify-center rounded-full bg-zinc-100 text-[0.625rem] font-semibold text-zinc-600 ring-2 ring-white dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-950">+{{ $people->count() - 2 }}</span>
            @endif
        </div>
    @endif
    <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-zinc-500" />
</li>
