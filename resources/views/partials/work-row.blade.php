{{-- One piece of work (task) or a payment, as a row with a Nepali date tile. Pass task or payment. --}}
@php
    $isPay = ($payment ?? null) !== null;
    $m = $isPay ? $payment : $task;
    $date = $m->due_date;
    $done = $isPay ? (bool) $m->paid_at : (bool) $m->completed_at;
    $late = $isPay ? $m->status() === 'overdue' : $m->isOverdue();
    $isToday = $date?->isToday() && ! $done;
    $bs = $date ? \App\Support\NepaliDate::fromAd($date) : null;
    $tone = match (true) {
        $done => 'bg-zinc-50 text-zinc-300 dark:bg-white/[0.03] dark:text-zinc-600',
        $late => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300',
        $isToday => 'bg-brand-500 text-white shadow-md shadow-brand-500/30',
        $date !== null => 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-200',
        default => 'bg-zinc-100 text-zinc-400 dark:bg-white/5',
    };
    $check = 'flex size-6 shrink-0 items-center justify-center rounded-full border-[1.5px] transition';
    $type = $isPay ? null : (\App\Models\Task::TYPES[$m->type] ?? \App\Models\Task::TYPES['task']);
@endphp
<li wire:key="{{ $prefix ?? 'work' }}-{{ $isPay ? 'p' : 't' }}-{{ $m->id }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
    <span class="{{ $tone }} flex size-11 shrink-0 flex-col items-center justify-center rounded-xl leading-none" title="{{ $date ? \App\Support\NepaliDate::format($date).' · '.$date->format('l, j M Y') : __('No date') }}">
        @if ($bs)
            <span class="text-[0.9375rem] font-semibold tabular-nums">{{ $bs[2] }}</span>
            <span class="mt-1 text-[0.5625rem] font-semibold tracking-wide uppercase opacity-80">{{ mb_substr(\App\Support\NepaliDate::MONTHS[$bs[1] - 1], 0, 3) }}</span>
        @else
            <flux:icon.calendar variant="micro" class="size-4" />
        @endif
    </span>

    <div class="min-w-0 flex-1">
        @if ($isPay)
            <p @class(['truncate text-sm font-semibold tabular-nums', 'text-zinc-400' => $done, 'text-zinc-900 dark:text-white' => ! $done])>{{ \App\Models\Client::money($m->amount) }}</p>
            <p class="mt-0.5 flex items-center gap-1.5 truncate text-xs text-zinc-500">
                <flux:icon.banknotes variant="micro" class="size-3.5 shrink-0 text-emerald-500" />
                {{ __(\App\Models\Payment::TYPES[$m->type]['label'] ?? 'Payment') }}
                <span @class(['font-medium', 'text-emerald-600 dark:text-emerald-400' => $done, 'text-rose-600 dark:text-rose-400' => $late, 'text-zinc-400' => ! $done && ! $late])>· {{ $done ? __('Received') : ($late ? __('Overdue') : __('Due')) }}</span>
            </p>
        @else
            <p @class(['truncate text-sm', 'text-zinc-400 line-through decoration-zinc-300' => $done, 'font-semibold text-zinc-900 dark:text-white' => ! $done])>{{ $m->title }}</p>
            <p class="mt-0.5 flex items-center gap-1.5 truncate text-xs text-zinc-500">
                <span class="{{ $workTones[$m->type]['dot'] ?? 'bg-zinc-400' }} size-1.5 shrink-0 rounded-full"></span>
                {{ __($type['label']) }}
                @if ($done)
                    <span class="text-emerald-600 dark:text-emerald-400">· {{ __('Done') }}</span>
                @elseif ($late)
                    <span class="font-medium text-rose-600 dark:text-rose-400">· {{ trans_choice(':count day late|:count days late', (int) $date->diffInDays(today())) }}</span>
                @elseif ($isToday)
                    <span class="font-medium text-brand-600 dark:text-brand-300">· {{ __('Today') }}</span>
                @elseif ($date && $date->diffInDays(today()) <= 7)
                    <span>· {{ trans_choice('tomorrow|in :count days', (int) today()->diffInDays($date)) }}</span>
                @endif
            </p>
        @endif
    </div>

    @if ($isPay)
        <button type="button" @if (! $done) wire:click="togglePaid({{ $m->id }})" @endif @class([
            $check,
            'border-emerald-500 bg-emerald-500 text-white' => $done,
            'border-zinc-300 text-transparent hover:border-emerald-500 hover:bg-emerald-50 hover:text-emerald-500 dark:border-zinc-600 dark:hover:bg-emerald-500/10' => ! $done,
        ]) title="{{ $done ? __('Received') : __('Mark as received') }}"><flux:icon.check variant="micro" class="size-3.5" /></button>
    @else
        <button type="button" wire:click="deleteTask({{ $m->id }})" wire:confirm="{{ __('Delete this work?') }}" class="shrink-0 rounded-md p-1 text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-500 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete') }}">
            <flux:icon.trash variant="micro" class="size-3.5" />
        </button>
        <button type="button" wire:click="toggleTask({{ $m->id }})" @class([
            $check,
            'border-emerald-500 bg-emerald-500 text-white' => $done,
            'border-zinc-300 text-transparent hover:border-brand-500 hover:bg-brand-50 hover:text-brand-500 dark:border-zinc-600 dark:hover:bg-brand-500/10' => ! $done,
        ]) aria-label="{{ $done ? __('Mark as not done') : __('Mark as done') }}"><flux:icon.check variant="micro" class="size-3.5" /></button>
    @endif
</li>
