@props(['employee'])

@php
    $current = \App\Support\NepaliDate::period(today());
    $salary = (float) $employee->salary;
    $paidBy = $employee->paidByPeriod();
    $choices = collect(\App\Models\SalaryPayment::periodChoices())->map(function ($p, $i) use ($paidBy, $salary, $current) {
        [$y, $m] = array_map('intval', explode('-', $p));
        $paid = $paidBy[$p] ?? 0;

        return [
            'key' => $p,
            'month' => \App\Support\NepaliDate::MONTHS[$m - 1],
            'year' => $y,
            'state' => match (true) {
                $salary > 0 && $paid >= $salary => 'paid',
                $salary > 0 && $paid > 0 => 'part',
                $p === $current => 'current',
                default => 'open',
            },
            'when' => $p === $current ? __('This month') : trans_choice('In :count month|In :count months', $i),
            'left' => $salary > 0 && $paid > 0 ? $salary - $paid : null,
        ];
    });
    $tiles = [
        'paid' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300',
        'part' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200 ring-inset dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'current' => 'bg-brand-500 text-white shadow-sm shadow-brand-500/30',
        'open' => 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300',
    ];
@endphp

{{-- Salary month picker: this month and the next two. Fully paid months are locked. Binds to $wire.payPeriod. --}}
<div {{ $attributes->class('relative grid gap-2') }} x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false">
    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('For month') }}</span>

    <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="listbox" data-test="salary-month-button"
        class="flex h-10 w-full items-center gap-2.5 rounded-lg border border-zinc-200 bg-white px-2 text-start text-sm shadow-xs transition hover:border-zinc-300 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none dark:border-white/10 dark:bg-white/5"
        :class="open && 'border-brand-400 ring-2 ring-brand-500/20'">
        <template x-if="! $wire.payPeriod">
            <span class="flex items-center gap-2.5 ps-1 text-zinc-400"><flux:icon.calendar variant="micro" class="size-4" />{{ __('Choose a month') }}</span>
        </template>
        @foreach ($choices as $c)
            <span x-show="$wire.payPeriod === @js($c['key'])" x-cloak class="flex min-w-0 items-center gap-2.5">
                <span class="{{ $tiles[$c['state']] }} flex h-7 w-9 shrink-0 items-center justify-center rounded-md text-[0.625rem] font-bold uppercase">{{ mb_substr($c['month'], 0, 3) }}</span>
                <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $c['month'] }} {{ $c['year'] }}</span>
            </span>
        @endforeach
        <flux:icon.chevron-down variant="micro" class="ms-auto size-4 shrink-0 text-zinc-400 transition" ::class="open && 'rotate-180'" />
    </button>

    <div x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1 scale-95" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-100" x-transition:leave-end="opacity-0 scale-95" x-cloak role="listbox"
        class="absolute start-0 top-full z-30 mt-2 w-[min(22rem,calc(100vw-3rem))] origin-top-left overflow-hidden rounded-2xl bg-white shadow-[0_24px_60px_-16px_rgb(15_23_42/0.4)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10">
        <div class="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50/80 px-4 py-2.5 dark:border-white/5 dark:bg-white/[0.03]">
            <flux:icon.calendar-days variant="micro" class="size-4 text-brand-500" />
            <p class="flex-1 text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Salary month') }}</p>
            <p class="text-[0.6875rem] text-zinc-400">{{ __('Next 3 months') }}</p>
        </div>

        <div class="flex flex-col gap-0.5 p-1.5">
            @foreach ($choices as $c)
                @php $locked = $c['state'] === 'paid'; @endphp
                <button type="button" role="option" @if ($locked) disabled title="{{ __('Already paid in full') }}" @else @click="$wire.payPeriod = @js($c['key']); open = false" @endif
                    :aria-selected="$wire.payPeriod === @js($c['key'])"
                    @class(['group flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-start transition', 'cursor-not-allowed opacity-55' => $locked, 'hover:bg-zinc-50 dark:hover:bg-white/5' => ! $locked])
                    :class="$wire.payPeriod === @js($c['key']) && 'bg-brand-50/80 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/30'">
                    <span class="{{ $tiles[$c['state']] }} flex size-10 shrink-0 flex-col items-center justify-center rounded-lg leading-none">
                        @if ($locked)
                            <flux:icon.check variant="mini" class="size-4.5" />
                        @else
                            <span class="text-[0.625rem] font-bold uppercase">{{ mb_substr($c['month'], 0, 3) }}</span>
                            <span class="mt-0.5 text-[0.625rem] font-semibold opacity-70">{{ $c['year'] }}</span>
                        @endif
                    </span>
                    <span class="min-w-0 flex-1">
                        <span @class(['block truncate text-sm font-semibold', 'text-zinc-500 line-through decoration-zinc-300' => $locked, 'text-zinc-900 dark:text-white' => ! $locked])>{{ $c['month'] }} {{ $c['year'] }}</span>
                        <span class="block truncate text-xs text-zinc-500">{{ $c['when'] }}</span>
                    </span>
                    @if ($locked)
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[0.6875rem] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('Paid') }}</span>
                    @elseif ($c['state'] === 'part')
                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[0.6875rem] font-semibold whitespace-nowrap text-amber-700 tabular-nums dark:bg-amber-500/10 dark:text-amber-300">{{ __(':amount left', ['amount' => \App\Models\Client::money($c['left'])]) }}</span>
                    @elseif ($c['state'] === 'current')
                        <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[0.6875rem] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ __('Now') }}</span>
                    @endif
                    <flux:icon.check-circle variant="mini" class="size-5 shrink-0 text-brand-600" x-show="$wire.payPeriod === @js($c['key'])" x-cloak />
                </button>
            @endforeach
        </div>
    </div>
    <flux:error name="payPeriod" />
</div>
