@props(['employee'])

@php
    $current = \App\Support\NepaliDate::period(today());
    $salary = (float) $employee->salary;
    $paidBy = $employee->paidByPeriod();
    $choices = collect(\App\Models\SalaryPayment::periodChoices())->map(function ($p, $i) use ($paidBy, $salary, $current) {
        [$y, $m] = array_map('intval', explode('-', $p));

        return [
            'key' => $p,
            'month' => \App\Support\NepaliDate::MONTHS[$m - 1],
            'year' => $y,
            'paid' => $salary > 0 && ($paidBy[$p] ?? 0) >= $salary,
            'hint' => $p === $current ? __('This month') : trans_choice('In :count month|In :count months', $i),
            'left' => $salary > 0 && ($paidBy[$p] ?? 0) > 0 ? $salary - $paidBy[$p] : null,
        ];
    });
@endphp

{{-- Salary month picker: this month and the next six; months already paid are locked. Binds to $wire.payPeriod. --}}
<div {{ $attributes->class('relative') }} x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false">
    <span class="mb-1.5 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('For month') }}</span>
    <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="listbox" data-test="salary-month-button"
        class="flex w-full items-center gap-3 rounded-xl bg-white px-3 py-2.5 text-start ring-1 ring-zinc-200 transition hover:ring-zinc-300 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:ring-white/10">
        <template x-if="! $wire.payPeriod">
            <span class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-lg border border-dashed border-zinc-300 text-zinc-400 dark:border-white/20"><flux:icon.calendar variant="mini" class="size-4.5" /></span>
                <span class="text-sm text-zinc-400">{{ __('Choose a month') }}</span>
            </span>
        </template>
        @foreach ($choices as $c)
            <span x-show="$wire.payPeriod === @js($c['key'])" x-cloak class="flex items-center gap-3">
                <span class="flex size-10 flex-col items-center justify-center rounded-lg bg-brand-500 leading-none text-white shadow-sm shadow-brand-500/30">
                    <span class="text-[0.6rem] font-semibold uppercase opacity-80">{{ mb_substr($c['month'], 0, 3) }}</span>
                    <span class="mt-0.5 text-[0.7rem] font-bold">{{ substr($c['year'], -2) }}</span>
                </span>
                <span>
                    <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ $c['month'] }} {{ $c['year'] }}</span>
                    <span class="block text-xs text-zinc-500">{{ $c['hint'] }}@if ($c['left'] !== null) · <span class="font-medium text-amber-600 dark:text-amber-400">{{ __(':amount left', ['amount' => \App\Models\Client::money($c['left'])]) }}</span>@endif</span>
                </span>
            </span>
        @endforeach
        <flux:icon.chevron-up-down variant="mini" class="ms-auto size-4 text-zinc-400" />
    </button>

    <div x-show="open" x-transition.origin.top x-cloak role="listbox"
        class="absolute inset-x-0 top-full z-30 mt-1.5 grid grid-cols-2 gap-1 rounded-xl bg-white p-1.5 shadow-[0_20px_50px_-12px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 sm:grid-cols-3 dark:bg-zinc-900 dark:ring-white/10">
        @foreach ($choices as $c)
            @if ($c['paid'])
                <div class="flex cursor-not-allowed items-center gap-2.5 rounded-lg px-2.5 py-2 opacity-60" title="{{ __('Already paid') }}">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check variant="micro" class="size-4" /></span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-zinc-500 line-through decoration-zinc-300">{{ $c['month'] }}</span>
                        <span class="block text-[0.6875rem] font-medium text-emerald-600 dark:text-emerald-400">{{ __('Paid') }}</span>
                    </span>
                </div>
            @else
                <button type="button" role="option" @click="$wire.payPeriod = @js($c['key']); open = false" :aria-selected="$wire.payPeriod === @js($c['key'])"
                    class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-start transition hover:bg-zinc-50 dark:hover:bg-white/5"
                    :class="$wire.payPeriod === @js($c['key']) && 'bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/30'">
                    <span @class([
                        'flex size-9 shrink-0 flex-col items-center justify-center rounded-lg leading-none',
                        'bg-brand-500 text-white' => $loop->first,
                        'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => ! $loop->first,
                    ])>
                        <span class="text-[0.6rem] font-semibold uppercase">{{ mb_substr($c['month'], 0, 3) }}</span>
                        <span class="mt-0.5 text-[0.6rem] font-bold opacity-70">{{ substr($c['year'], -2) }}</span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $c['month'] }}</span>
                        @if ($c['left'] !== null)
                            <span class="block truncate text-[0.6875rem] font-medium text-amber-600 dark:text-amber-400">{{ __(':amount left', ['amount' => \App\Models\Client::money($c['left'])]) }}</span>
                        @else
                            <span class="block truncate text-[0.6875rem] text-zinc-500">{{ $c['hint'] }}</span>
                        @endif
                    </span>
                </button>
            @endif
        @endforeach
    </div>
    <flux:error name="payPeriod" />
</div>
