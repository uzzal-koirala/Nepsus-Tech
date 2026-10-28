<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Models\Transaction;
use App\Support\NepaliDate;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overview')] class extends Component {
    /** Income and spending for a BS month key, e.g. "2083-06". */
    private function monthTotals(string $period): array
    {
        [$y, $m] = array_map('intval', explode('-', $period));
        $from = NepaliDate::toAd($y, $m, 1)->startOfDay();
        $to = NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))->endOfDay();
        $days = [$from->toDateString(), $to->toDateString()];

        $income = (float) Payment::whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])->sum('amount')
            + (float) Transaction::where('kind', 'income')->whereBetween('date', $days)->sum('amount');
        $spent = (float) Transaction::where('kind', 'expense')->whereBetween('date', $days)->sum('amount')
            + (float) SalaryPayment::forMonthsIn($from, $to)->sum('amount');

        return ['period' => $period, 'income' => $income, 'spent' => $spent];
    }

    /** The last six BS months, oldest first. */
    #[Computed]
    public function flow(): array
    {
        return array_map(fn ($p) => $this->monthTotals($p), array_reverse(NepaliDate::recentPeriods(6)));
    }

    /**
     * Money in and out for the last 12 weeks (Sunday to Saturday), oldest first.
     * Four queries in total, bucketed here, instead of one set per week.
     */
    #[Computed]
    public function weekly(): array
    {
        $weeks = 12;
        $start = today()->subDays(today()->dayOfWeek)->subWeeks($weeks - 1);
        $end = today()->endOfDay();
        $week = fn ($date) => intdiv((int) $start->diffInDays($date->copy()->startOfDay()), 7);
        $in = array_fill(0, $weeks, 0.0);
        $out = array_fill(0, $weeks, 0.0);

        foreach (Payment::whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end])->get(['amount', 'paid_at']) as $p) {
            $in[$week($p->paid_at)] += (float) $p->amount;
        }
        foreach (Transaction::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get(['kind', 'amount', 'date']) as $t) {
            $t->kind === 'income' ? $in[$week($t->date)] += (float) $t->amount : $out[$week($t->date)] += (float) $t->amount;
        }
        foreach (SalaryPayment::whereBetween('paid_on', [$start, $end])->get(['amount', 'paid_on']) as $sp) {
            $out[$week($sp->paid_on)] += (float) $sp->amount;
        }

        return array_map(function ($i) use ($start, $in, $out) {
            $from = $start->copy()->addWeeks($i);

            return ['from' => $from, 'to' => $from->copy()->addDays(6), 'income' => $in[$i], 'spent' => $out[$i]];
        }, range(0, $weeks - 1));
    }

    #[Computed]
    public function stats(): array
    {
        [$prev, $now] = array_slice($this->flow, -2);
        [$monthStart] = NepaliDate::monthRange(today());
        $unpaid = Payment::whereNull('paid_at')->get();

        return [
            'clients' => Client::where('status', 'active')->count(),
            'newClients' => Client::where('created_at', '>=', $monthStart)->count(),
            'income' => $now['income'],
            'incomeChange' => $prev['income'] > 0 ? (int) round(($now['income'] - $prev['income']) / $prev['income'] * 100) : null,
            'spent' => $now['spent'],
            'net' => $now['income'] - $now['spent'],
            'outstanding' => (float) $unpaid->sum('amount'),
            'overdue' => $unpaid->filter(fn ($p) => $p->status() === 'overdue')->count(),
        ];
    }

    #[Computed]
    public function duePayments()
    {
        return Payment::with('client')->whereNull('paid_at')->whereNotNull('due_date')->orderBy('due_date')->limit(5)->get();
    }

    /** Totals for the Payments due panel. */
    #[Computed]
    public function dueSummary(): array
    {
        $unpaid = Payment::whereNull('paid_at')->whereNotNull('due_date');

        return ['count' => (clone $unpaid)->count(), 'total' => (float) $unpaid->sum('amount')];
    }

    /** The latest money movements: client payments, other income, expenses and salaries (same as Finance). */
    #[Computed]
    public function recentTransactions()
    {
        $limit = 5;
        $payments = Payment::with('client')->whereNotNull('paid_at')->latest('paid_at')->latest('id')->limit($limit)->get()
            ->map(fn ($p) => ['key' => 'p'.$p->id, 'kind' => 'income', 'date' => $p->paid_at, 'title' => $p->client?->displayName() ?? __('Client payment'),
                'category' => __(Client::SERVICE_TYPES[$p->client?->service_type]['label'] ?? 'Client'), 'icon' => 'building-office-2', 'amount' => (float) $p->amount, 'method' => $p->method,
                'link' => $p->client ? route('clients.show', ['client' => $p->client_id, 'tab' => 'payments']) : null]);
        $salaries = SalaryPayment::with('employee')->latest('paid_on')->latest('id')->limit($limit)->get()
            ->map(fn ($x) => ['key' => 's'.$x->id, 'kind' => 'expense', 'date' => $x->paid_on, 'title' => $x->employee?->name ?? __('Salary'),
                'category' => __('Salary'), 'icon' => 'user-group', 'amount' => (float) $x->amount, 'method' => $x->method,
                'link' => $x->employee ? route('employees.show', ['employee' => $x->employee_id, 'tab' => 'salary']) : null]);
        $manual = Transaction::latest('date')->latest('id')->limit($limit)->get()
            ->map(fn ($t) => ['key' => 't'.$t->id, 'kind' => $t->kind, 'date' => $t->date, 'title' => $t->title,
                'category' => $t->categoryLabel(), 'icon' => Transaction::categories($t->kind)[$t->category]['icon'] ?? 'tag', 'amount' => (float) $t->amount, 'method' => $t->method,
                'link' => route('finance.index')]);

        return $payments->concat($salaries)->concat($manual)
            ->sortByDesc(fn ($e) => $e['date']->copy()->startOfDay()->timestamp.'-'.$e['key'])
            ->take($limit)->values();
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($v) => Client::money($v);
    $s = $this->stats;
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
@endphp

<div class="stagger flex w-full flex-col gap-6">
    {{-- Hero --}}
    <section class="grain relative isolate overflow-hidden rounded-2xl bg-brand-500 p-6 text-white sm:p-8">
        <div class="absolute inset-0 -z-10 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_right,black,transparent_70%)]"></div>
        <div class="absolute -right-16 -bottom-28 -z-10 hidden size-72 rounded-full bg-sun sm:block lg:right-10"></div>
        <img src="{{ asset('images/auth-crew.webp') }}" alt="" class="absolute right-0 -bottom-6 -z-10 hidden h-[135%] w-auto lg:block lg:right-8">

        <p class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/20 backdrop-blur">
            <flux:icon.calendar variant="micro" class="size-3.5" />{{ today()->toNepali() }} · {{ now()->translatedFormat('l') }}
        </p>
        <h1 class="mt-4 text-2xl font-semibold tracking-[-0.02em] sm:text-3xl">{{ $greeting }}, {{ str(auth()->user()->name)->before(' ') }}</h1>
        <p class="mt-2 max-w-lg text-sm leading-relaxed text-brand-100">
            {{ trans_choice(':count payment overdue|:count payments overdue', $s['overdue']) }} · {{ __(':amount in this month', ['amount' => $money($s['income'])]) }}
        </p>
        <div class="mt-6 flex flex-wrap gap-2">
            <a href="{{ route('clients.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-brand-600 shadow-sm transition hover:bg-brand-50"><flux:icon.plus variant="micro" class="size-4" />{{ __('New client') }}</a>
            <a href="{{ route('finance.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3.5 py-2 text-sm font-semibold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25"><flux:icon.banknotes variant="micro" class="size-4" />{{ __('Finance') }}</a>
            <a href="{{ route('employees.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3.5 py-2 text-sm font-semibold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25"><flux:icon.user-group variant="micro" class="size-4" />{{ __('Team') }}</a>
        </div>
    </section>

    {{-- Stats --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Active clients'), 'value' => $s['clients'], 'icon' => 'building-office-2', 'tile' => 'bg-brand-500 shadow-brand-500/30', 'glow' => 'bg-brand-100/70 dark:bg-brand-500/10', 'sub' => trans_choice(':count new this month|:count new this month', $s['newClients']), 'href' => route('clients.index')],
            ['label' => __('Income this month'), 'value' => $money($s['income']), 'icon' => 'arrow-down-left', 'tile' => 'bg-emerald-500 shadow-emerald-500/30', 'glow' => 'bg-emerald-100/70 dark:bg-emerald-500/10', 'sub' => $s['incomeChange'] === null ? __('No income last month') : __(':change% vs last month', ['change' => ($s['incomeChange'] >= 0 ? '+' : '').$s['incomeChange']]), 'href' => route('finance.index')],
            ['label' => __('Spent this month'), 'value' => $money($s['spent']), 'icon' => 'arrow-up-right', 'tile' => 'bg-rose-500 shadow-rose-500/30', 'glow' => 'bg-rose-100/70 dark:bg-rose-500/10', 'sub' => __('Net :amount', ['amount' => ($s['net'] < 0 ? '−' : '').$money(abs($s['net']))]), 'href' => route('finance.index')],
            ['label' => __('Outstanding'), 'value' => $money($s['outstanding']), 'icon' => 'clock', 'tile' => 'bg-amber-500 shadow-amber-500/30', 'glow' => 'bg-amber-100/70 dark:bg-amber-500/10', 'sub' => $s['overdue'] ? trans_choice(':count payment overdue|:count payments overdue', $s['overdue']) : __('Nothing overdue'), 'href' => route('finance.index')],
        ] as $card)
            <a href="{{ $card['href'] }}" wire:navigate class="{{ $panel }} group relative flex flex-col gap-4 overflow-hidden p-5 transition hover:-translate-y-0.5 hover:shadow-lg">
                <div class="{{ $card['glow'] }} absolute -top-12 -right-12 size-32 rounded-full"></div>
                <div class="relative flex items-center gap-3">
                    <span class="{{ $card['tile'] }} flex size-9 items-center justify-center rounded-lg text-white shadow-md"><flux:icon :icon="$card['icon']" variant="mini" class="size-4.5" /></span>
                    <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $card['label'] }}</p>
                    <flux:icon.arrow-up-right variant="micro" class="size-4 text-zinc-300 transition group-hover:text-zinc-500" />
                </div>
                <p class="relative text-[1.75rem] leading-none font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $card['value'] }}</p>
                <p @class(['relative text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $loop->last && $s['overdue'], 'text-zinc-500' => ! ($loop->last && $s['overdue'])])>{{ $card['sub'] }}</p>
            </a>
        @endforeach
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
            {{-- Cash flow: smooth area chart, money in vs out --}}
            @php
                $flow = $this->weekly;
                $n = count($flow);
                $peak = max(1, collect($flow)->flatMap(fn ($f) => [$f['income'], $f['spent']])->max());
                // A round top value so the grid reads 0 / 25k / 50k / 75k / 100k.
                $mag = 10 ** max(0, floor(log10($peak)) - 1);
                $top = ceil($peak * 1.08 / ($mag * 4)) * $mag * 4;
                $short = fn ($v) => $v >= 100000 ? rtrim(rtrim(number_format($v / 100000, 1), '0'), '.').'L' : ($v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : (string) (int) $v);
                $xs = array_map(fn ($i) => $n > 1 ? 3 + $i * 94 / ($n - 1) : 50, range(0, $n - 1));
                $y = fn ($v) => 100 - $v / $top * 100;
                $curve = fn (array $vals) => \App\Support\SmoothLine::path(array_map(fn ($i) => [$xs[$i], $y($vals[$i])], range(0, $n - 1)));
                $in = array_column($flow, 'income');
                $out = array_column($flow, 'spent');
                $inLine = $curve($in);
                $outLine = $curve($out);
                $close = ' L'.end($xs).',100 L'.$xs[0].',100 Z';
                $points = array_map(fn ($i) => [
                    'x' => $xs[$i],
                    'month' => __('Week of :from – :to', ['from' => NepaliDate::format($flow[$i]['from'], false), 'to' => NepaliDate::format(min($flow[$i]['to'], today()), false)]),
                    // Axis: the month name where a new month starts, otherwise just the day.
                    'tick' => $i === 0 || NepaliDate::fromAd($flow[$i]['from'])[1] !== NepaliDate::fromAd($flow[$i - 1]['from'])[1] ? NepaliDate::format($flow[$i]['from'], false) : (string) NepaliDate::fromAd($flow[$i]['from'])[2],
                    'in' => $money($in[$i]), 'out' => $money($out[$i]), 'net' => $money(abs($in[$i] - $out[$i])), 'up' => $in[$i] >= $out[$i],
                    'yi' => $y($in[$i]), 'yo' => $y($out[$i]),
                ], range(0, $n - 1));
                $totalIn = array_sum($in);
                $totalOut = array_sum($out);
            @endphp
            <section class="{{ $panel }} flex h-full flex-col p-5 sm:p-6 [--c-in:#0e9f6e] [--c-out:#e8590c] dark:[--c-in:#1fa576] dark:[--c-out:#e9622a]" data-test="cash-flow">
                <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
                    <div class="flex-1">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Cash flow') }}</h2>
                        <p class="text-sm text-zinc-500">{{ __('Money in and out, week by week (last 12 weeks)') }}</p>
                    </div>
                    <div class="flex items-center gap-5">
                        @foreach ([[__('Money in'), $totalIn, 'var(--c-in)', false], [__('Money out'), $totalOut, 'var(--c-out)', true]] as [$label, $total, $color, $dashed])
                            <div>
                                <p class="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                                    <svg class="h-2 w-4" viewBox="0 0 16 8" aria-hidden="true"><line x1="1" y1="4" x2="15" y2="4" stroke="{{ $color }}" stroke-width="2.5" stroke-linecap="round" @if ($dashed) stroke-dasharray="4 3" @endif /></svg>
                                    {{ $label }}
                                </p>
                                <p class="mt-0.5 text-base font-bold text-zinc-900 tabular-nums dark:text-white">{{ $money($total) }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-6 flex min-h-64 flex-1 gap-3" x-data="{ i: null, pts: @js($points) }">
                    {{-- Amount axis --}}
                    <div class="flex w-10 shrink-0 flex-col text-end text-[0.6875rem] text-zinc-400 tabular-nums" aria-hidden="true">
                        <div class="relative flex-1">
                            @foreach ([1, 0.75, 0.5, 0.25, 0] as $f)
                                <span class="absolute right-0 -translate-y-1/2" style="top: {{ (1 - $f) * 100 }}%">{{ $short($top * $f) }}</span>
                            @endforeach
                        </div>
                        <div class="h-6 shrink-0"></div>{{-- lines up with the week labels --}}
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <div class="relative min-h-48 flex-1" data-plot @mouseleave="i = null"
                            @mousemove="const r = $el.getBoundingClientRect(); const x = ($event.clientX - r.left) / r.width * 100; i = pts.reduce((best, p, k) => Math.abs(p.x - x) < Math.abs(pts[best].x - x) ? k : best, 0)">
                            {{-- Grid --}}
                            @foreach ([0, 0.25, 0.5, 0.75, 1] as $f)
                                <div @class(['pointer-events-none absolute inset-x-0 border-t', 'border-zinc-200 dark:border-white/10' => $f === 1, 'border-dashed border-zinc-100 dark:border-white/5' => $f !== 1]) style="top: {{ $f * 100 }}%"></div>
                            @endforeach

                            <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                <defs>
                                    <linearGradient id="cf-in" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--c-in)" stop-opacity=".22" /><stop offset="100%" stop-color="var(--c-in)" stop-opacity="0" /></linearGradient>
                                    <linearGradient id="cf-out" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--c-out)" stop-opacity=".16" /><stop offset="100%" stop-color="var(--c-out)" stop-opacity="0" /></linearGradient>
                                </defs>
                                <path d="{{ $outLine.$close }}" fill="url(#cf-out)" />
                                <path d="{{ $inLine.$close }}" fill="url(#cf-in)" />
                                <path d="{{ $outLine }}" fill="none" stroke="var(--c-out)" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                <path d="{{ $inLine }}" fill="none" stroke="var(--c-in)" stroke-width="2.5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                            </svg>

                            {{-- Latest month, always marked --}}
                            @php $last = end($points); @endphp
                            <span x-show="i === null" class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" style="left: {{ $last['x'] }}%; top: {{ $last['yi'] }}%; background: var(--c-in)"></span>
                            <span x-show="i === null" class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" style="left: {{ $last['x'] }}%; top: {{ $last['yo'] }}%; background: var(--c-out)"></span>

                            {{-- Hover: guide line, points and the month's numbers --}}
                            <template x-if="i !== null">
                                <div class="pointer-events-none absolute inset-0">
                                    <div class="absolute inset-y-0 border-l border-zinc-300 dark:border-white/20" :style="`left: ${pts[i].x}%`"></div>
                                    <span class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" :style="`left: ${pts[i].x}%; top: ${pts[i].yi}%; background: var(--c-in)`"></span>
                                    <span class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-950" :style="`left: ${pts[i].x}%; top: ${pts[i].yo}%; background: var(--c-out)`"></span>
                                    <div class="absolute top-1 w-52 rounded-xl bg-white p-3 text-xs shadow-[0_12px_32px_-10px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10"
                                        :style="pts[i].x > 60 ? `right: ${100 - pts[i].x + 2}%` : `left: ${pts[i].x + 2}%`">
                                        <p class="font-semibold text-zinc-900 dark:text-white" x-text="pts[i].month"></p>
                                        <p class="mt-1.5 flex items-center justify-between gap-2 text-zinc-500"><span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded" style="background: var(--c-in)"></span>{{ __('In') }}</span><span class="font-semibold text-zinc-900 tabular-nums dark:text-white" x-text="pts[i].in"></span></p>
                                        <p class="mt-1 flex items-center justify-between gap-2 text-zinc-500"><span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded" style="background: var(--c-out)"></span>{{ __('Out') }}</span><span class="font-semibold text-zinc-900 tabular-nums dark:text-white" x-text="pts[i].out"></span></p>
                                        <p class="mt-1.5 flex items-center justify-between gap-2 border-t border-zinc-100 pt-1.5 text-zinc-500 dark:border-white/10"><span x-text="pts[i].up ? @js(__('Saved')) : @js(__('Overspent'))"></span><span class="font-semibold tabular-nums" :class="pts[i].up ? 'text-zinc-900 dark:text-white' : 'text-rose-600 dark:text-rose-400'" x-text="pts[i].net"></span></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                        {{-- Weeks (start day); the month name where a new month begins --}}
                        <div class="relative mt-2 h-4 text-[0.6875rem] text-zinc-500">
                            @foreach ($points as $k => $p)
                                <span @class(['absolute whitespace-nowrap', '-translate-x-1/2' => ! $loop->first && ! $loop->last, '-translate-x-full font-semibold text-zinc-900 dark:text-white' => $loop->last, 'hidden' => ! $loop->first && ! $loop->last && (! str_contains($p['tick'], ' ') || $p['x'] > 80 || $p['x'] < 20), 'max-sm:hidden' => ! $loop->first && ! $loop->last]) style="left: {{ $p['x'] }}%">{{ $loop->last ? __('This week') : $p['tick'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- The same numbers for screen readers --}}
                <div class="sr-only"><table>
                    <caption>{{ __('Cash flow, last 12 weeks') }}</caption>
                    <tr><th>{{ __('Week') }}</th><th>{{ __('Money in') }}</th><th>{{ __('Money out') }}</th></tr>
                    @foreach ($points as $p)<tr><td>{{ $p['month'] }}</td><td>{{ $p['in'] }}</td><td>{{ $p['out'] }}</td></tr>@endforeach
                </table></div>
            </section>

        </div>

        <aside class="flex min-w-0 flex-col gap-6">
            {{-- Payments due --}}
            @php $due = $this->dueSummary; @endphp
            <section class="{{ $panel }} h-full overflow-hidden" data-test="payments-due">
                <div class="flex items-start gap-3 px-5 pt-5">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white shadow-md shadow-amber-500/25"><flux:icon.bell-alert variant="mini" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Payments due') }}</h2>
                        <p class="text-xs text-zinc-500">{{ $due['count'] ? trans_choice(':count payment waiting|:count payments waiting', $due['count']).' · '.$money($due['total']) : __('Nothing waiting') }}</p>
                    </div>
                    <a href="{{ route('clients.index') }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">{{ __('Clients') }}</a>
                </div>


                <div class="flex flex-col p-2 pt-3">
                    @forelse ($this->duePayments as $payment)
                        @php
                            $days = (int) today()->diffInDays($payment->due_date, false);
                            $tone = $days < 0 ? 'late' : ($days <= max(7, Payment::remindDays()) ? 'soon' : 'later');
                            $styles = [
                                'late' => ['tile' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', 'pill' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20'],
                                'soon' => ['tile' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'pill' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20'],
                                'later' => ['tile' => 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300', 'pill' => 'bg-zinc-50 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10'],
                            ][$tone];
                            $when = $days < 0 ? trans_choice(':count day late|:count days late', -$days) : ($days === 0 ? __('Due today') : ($days === 1 ? __('Tomorrow') : ($days <= 7 ? trans_choice('In :count day|In :count days', $days) : $payment->due_date->toNepali(false))));
                        @endphp
                        <a href="{{ route('clients.show', ['client' => $payment->client_id, 'tab' => 'payments']) }}" wire:navigate wire:key="due-{{ $payment->id }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            @if ($payment->client?->avatarUrl())
                                <img src="{{ $payment->client->avatarUrl() }}" alt="" class="size-10 shrink-0 rounded-xl object-cover">
                            @else
                                <span class="{{ $styles['tile'] }} flex size-10 shrink-0 items-center justify-center rounded-xl text-xs font-bold">{{ $payment->client?->initials() }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $payment->client?->displayName() }}</p>
                                <span class="{{ $styles['pill'] }} mt-1 inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset">{{ $when }}</span>
                            </div>
                            <span class="text-sm font-bold text-zinc-900 tabular-nums dark:text-white">{{ $money($payment->amount) }}</span>
                            <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-zinc-500" />
                        </a>
                    @empty
                        <div class="flex flex-col items-center gap-2 px-5 py-10 text-center">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge class="size-6" /></span>
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('All paid up') }}</p>
                            <p class="text-xs text-zinc-500">{{ __('No client payments are waiting.') }}</p>
                        </div>
                    @endforelse
                </div>
            </section>

        </aside>
    </div>

    {{-- Recent transactions: the same list as Finance --}}
    @php
        $th = 'px-3 py-2.5 text-start text-[0.6875rem] font-semibold tracking-wide text-zinc-500 uppercase whitespace-nowrap';
        $methodIcons = ['Bank' => 'building-library', 'eSewa' => 'device-phone-mobile', 'Khalti' => 'wallet', 'Cash' => 'banknotes', 'Cheque' => 'document-text', 'Other' => 'ellipsis-horizontal-circle'];
        $recent = $this->recentTransactions;
    @endphp
    <section class="{{ $panel }} overflow-hidden" data-test="recent-transactions">
        <div class="flex items-center gap-3 px-5 py-4">
            <span class="flex size-9 items-center justify-center rounded-lg bg-brand-500 text-white shadow-md shadow-brand-500/25"><flux:icon.arrows-right-left variant="mini" class="size-4.5" /></span>
            <div class="flex-1">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Recent transactions') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('The latest money in and out, from Finance') }}</p>
            </div>
        </div>

        @if ($recent->isEmpty())
            <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.receipt-percent class="size-6" /></span>
                <p class="text-sm text-zinc-500">{{ __('No money movements yet.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                        <tr>
                            <th class="{{ $th }} ps-5">{{ __('Title') }}</th>
                            <th class="{{ $th }}">{{ __('Date') }}</th>
                            <th class="{{ $th }}">{{ __('Category') }}</th>
                            <th class="{{ $th }}">{{ __('Payment type') }}</th>
                            <th class="{{ $th }}">{{ __('Method') }}</th>
                            <th class="{{ str_replace('text-start', 'text-end', $th) }} pe-5">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($recent as $e)
                            @php $in = $e['kind'] === 'income'; @endphp
                            <tr wire:key="tx-{{ $e['key'] }}" class="cursor-pointer transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]" onclick="Livewire.navigate('{{ $e['link'] ?? route('finance.index') }}')">
                                <td class="max-w-80 px-3 py-2.5 ps-5">
                                    <div class="flex items-center gap-3">
                                        <span @class(['flex size-8 shrink-0 items-center justify-center rounded-lg', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => $in, 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' => ! $in])>
                                            <flux:icon :icon="$in ? 'arrow-down-left' : 'arrow-up-right'" variant="micro" class="size-4" />
                                        </span>
                                        <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $e['title'] }}</p>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 font-medium whitespace-nowrap text-zinc-900 dark:text-white">{{ NepaliDate::format($e['date']) }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium whitespace-nowrap text-zinc-700 dark:bg-white/10 dark:text-zinc-200"><flux:icon :icon="$e['icon']" variant="micro" class="size-3.5" />{{ $e['category'] }}</span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <span @class(['inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset', 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $in, 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => ! $in])>
                                        <flux:icon :icon="$in ? 'arrow-down-left' : 'arrow-up-right'" variant="micro" class="size-3" />{{ $in ? __('Income') : __('Expense') }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-zinc-700 dark:text-zinc-300">
                                    @if ($e['method'])
                                        <span class="inline-flex items-center gap-1.5"><flux:icon :icon="$methodIcons[$e['method']] ?? 'credit-card'" variant="micro" class="size-4 text-zinc-400" />{{ __($e['method']) }}</span>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 pe-5 text-end whitespace-nowrap">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-sm font-bold tabular-nums ring-1 ring-inset',
                                        'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $in,
                                        'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => ! $in,
                                    ])>{{ $in ? '+' : '−' }} {{ $money($e['amount']) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <a href="{{ route('finance.index') }}" wire:navigate class="group flex items-center justify-center gap-1.5 border-t border-zinc-100 py-3 text-sm font-medium text-brand-600 transition hover:bg-brand-50/60 dark:border-white/5 dark:text-brand-300 dark:hover:bg-brand-500/10" data-test="view-all-transactions">
                {{ __('View all transactions') }}<flux:icon.arrow-right variant="micro" class="size-4 transition group-hover:translate-x-0.5" />
            </a>
        @endif
    </section>
</div>
