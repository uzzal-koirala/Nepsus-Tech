<div wire:key="{{ $prefix ?? 'task' }}-{{ $task->id }}" class="group flex items-center gap-3 border-b border-zinc-100 px-5 py-3 transition last:border-0 hover:bg-zinc-50/70 dark:border-white/5 dark:hover:bg-white/[0.02]">
    <button type="button" wire:click="toggleTask({{ $task->id }})" @class([
        'flex size-5 shrink-0 items-center justify-center rounded-md ring-1 transition',
        'bg-emerald-500 text-white ring-emerald-500' => $task->completed_at,
        'text-transparent ring-zinc-300 hover:ring-brand-500 hover:text-brand-300 dark:ring-zinc-600' => ! $task->completed_at,
    ]) aria-label="{{ $task->completed_at ? __('Mark as not done') : __('Mark as done') }}">
        <flux:icon.check variant="micro" class="size-3.5" />
    </button>
    <p @class(['min-w-0 flex-1 truncate text-sm', 'text-zinc-400 line-through' => $task->completed_at, 'text-zinc-800 dark:text-zinc-100' => ! $task->completed_at])>{{ $task->title }}</p>
    @if ($task->due_date)
        <span @class([
            'inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs',
            'bg-rose-50 font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $task->isOverdue(),
            'text-zinc-400' => ! $task->isOverdue(),
        ]) title="{{ $task->due_date->format('j M Y') }}">
            <flux:icon.calendar variant="micro" class="size-3" />
            {{ $task->due_date->toNepali(withYear: false) }}
        </span>
    @endif
    <button type="button" wire:click="deleteTask({{ $task->id }})" class="text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:text-rose-500 focus:opacity-100" aria-label="{{ __('Delete task') }}">
        <flux:icon.trash variant="micro" class="size-4" />
    </button>
</div>
