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
            + (float) SalaryPayment::whereBetween('paid_on', [$from, $to])->sum('amount');

        return ['period' => $period, 'income' => $income, 'spent' => $spent];
    }

    /** The last six BS months, oldest first. */
    #[Computed]
    public function flow(): array
    {
        return array_map(fn ($p) => $this->monthTotals($p), array_reverse(NepaliDate::recentPeriods(6)));
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

    /** The latest money movements: client payments, other income, expenses and salaries (same as Finance). */
    #[Computed]
    public function recentTransactions()
    {
        $limit = 8;
        $payments = Payment::with('client')->whereNotNull('paid_at')->latest('paid_at')->latest('id')->limit($limit)->get()
            ->map(fn ($p) => ['key' => 'p'.$p->id, 'kind' => 'income', 'date' => $p->paid_at, 'title' => $p->client?->displayName() ?? __('Client payment'),
                'category' => __('Client payment'), 'icon' => 'building-office-2', 'amount' => (float) $p->amount, 'method' => $p->method,
                'link' => $p->client ? route('clients.show', ['client' => $p->client_id, 'tab' => 'payments']) : null]);
        $salaries = SalaryPayment::with('employee')->latest('paid_on')->latest('id')->limit($limit)->get()
            ->map(fn ($x) => ['key' => 's'.$x->id, 'kind' => 'expense', 'date' => $x->paid_on, 'title' => $x->employee?->name ?? __('Salary'),
                'category' => __('Salary'), 'icon' => 'user-group', 'amount' => (float) $x->amount, 'method' => null,
                'link' => $x->employee ? route('employees.show', ['employee' => $x->employee_id, 'tab' => 'salary']) : null]);
        $manual = Transaction::latest('date')->latest('id')->limit($limit)->get()
            ->map(fn ($t) => ['key' => 't'.$t->id, 'kind' => $t->kind, 'date' => $t->date, 'title' => $t->title,
                'category' => $t->categoryLabel(), 'icon' => Transaction::categories($t->kind)[$t->category]['icon'] ?? 'tag', 'amount' => (float) $t->amount, 'method' => $t->method,
                'link' => route('finance.index')]);

        return $payments->concat($salaries)->concat($manual)
            ->sortByDesc(fn ($e) => $e['date']->copy()->startOfDay()->timestamp.'-'.$e['key'])
            ->take($limit)->values();
    }

    #[Computed]
    public function endingSoon()
    {
        return Client::where('status', 'active')->whereBetween('contract_end', [today(), today()->addDays(30)])->orderBy('contract_end')->limit(4)->get();
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($v) => Client::money($v);
    $s = $this->stats;
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
    $max = max(1, collect($this->flow)->flatMap(fn ($f) => [$f['income'], $f['spent']])->max());
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

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
            {{-- Cash flow --}}
            <section class="{{ $panel }} p-5 sm:p-6">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div class="flex-1">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Cash flow') }}</h2>
                        <p class="text-sm text-zinc-500">{{ __('Money in and out, last 6 months') }}</p>
                    </div>
                    <div class="flex items-center gap-4 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-500"></span>{{ __('Income') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-rose-400"></span>{{ __('Spent') }}</span>
                    </div>
                </div>

                <div class="relative mt-6 grid h-56 grid-cols-6 items-end gap-3 sm:gap-6">
                    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                        @foreach (range(1, 4) as $_)<div class="border-t border-dashed border-zinc-100 dark:border-white/5"></div>@endforeach
                    </div>
                    @foreach ($this->flow as $f)
                        <div class="group relative flex h-full flex-col justify-end">
                            <div class="flex h-full items-end justify-center gap-1 sm:gap-1.5">
                                <div class="w-full max-w-7 rounded-t-md bg-linear-to-t from-emerald-500 to-emerald-400 transition-[height] duration-700" style="height: {{ max(2, $f['income'] / $max * 100) }}%"></div>
                                <div class="w-full max-w-7 rounded-t-md bg-linear-to-t from-rose-400 to-rose-300 transition-[height] duration-700" style="height: {{ max(2, $f['spent'] / $max * 100) }}%"></div>
                            </div>
                            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 hidden w-max -translate-x-1/2 rounded-lg bg-zinc-900 px-3 py-2 text-xs text-white shadow-lg group-hover:block">
                                <p class="font-semibold">{{ NepaliDate::periodLabel($f['period']) }}</p>
                                <p class="text-emerald-300">{{ __('In') }} {{ $money($f['income']) }}</p>
                                <p class="text-rose-300">{{ __('Out') }} {{ $money($f['spent']) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 grid grid-cols-6 gap-3 text-center text-xs text-zinc-500 sm:gap-6">
                    @foreach ($this->flow as $f)
                        <span @class(['truncate', 'font-semibold text-zinc-900 dark:text-white' => $loop->last])>{{ str(NepaliDate::periodLabel($f['period']))->before(' ') }}</span>
                    @endforeach
                </div>
            </section>

            {{-- Recent transactions --}}
            <section class="{{ $panel }} overflow-hidden" data-test="recent-transactions">
                <div class="flex items-center gap-3 px-5 pt-5 pb-3">
                    <div class="flex-1">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Recent transactions') }}</h2>
                        <p class="text-sm text-zinc-500">{{ __('Latest money in and out') }}</p>
                    </div>
                    <a href="{{ route('finance.index') }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">{{ __('View all') }}<flux:icon.arrow-right variant="micro" class="size-4" /></a>
                </div>
                <div class="px-2 pb-2">
                    @forelse ($this->recentTransactions as $t)
                        @php $in = $t['kind'] === 'income'; @endphp
                        <a href="{{ $t['link'] ?? '#' }}" wire:navigate wire:key="tx-{{ $t['key'] }}" class="group flex items-center gap-4 rounded-xl px-3 py-3 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span @class([
                                'relative flex size-11 shrink-0 items-center justify-center rounded-xl',
                                'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => $in,
                                'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' => ! $in,
                            ])>
                                <flux:icon :icon="$t['icon']" variant="mini" class="size-5" />
                                <span @class(['absolute -right-1 -bottom-1 flex size-5 items-center justify-center rounded-full text-white ring-2 ring-white dark:ring-zinc-950', 'bg-emerald-500' => $in, 'bg-rose-500' => ! $in])>
                                    <flux:icon :icon="$in ? 'arrow-down-left' : 'arrow-up-right'" variant="micro" class="size-3" />
                                </span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $t['title'] }}</p>
                                <p class="truncate text-xs text-zinc-500">{{ $t['category'] }}@if ($t['method']) · {{ $t['method'] }}@endif</p>
                            </div>
                            <span class="hidden w-28 shrink-0 text-end text-xs text-zinc-500 sm:block">{{ $t['date']->isToday() ? __('Today') : ($t['date']->isYesterday() ? __('Yesterday') : $t['date']->toNepali()) }}</span>
                            <span @class(['w-32 shrink-0 text-end text-sm font-bold tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $in, 'text-zinc-900 dark:text-white' => ! $in])>{{ $in ? '+' : '−' }} {{ $money($t['amount']) }}</span>
                        </a>
                    @empty
                        <div class="flex flex-col items-center gap-2 px-5 py-10 text-center">
                            <span class="flex size-11 items-center justify-center rounded-xl bg-zinc-100 text-zinc-400 dark:bg-white/5"><flux:icon.banknotes variant="mini" class="size-5" /></span>
                            <p class="text-sm text-zinc-500">{{ __('No money movements yet.') }}</p>
                        </div>
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="flex min-w-0 flex-col gap-6">
            {{-- Payments due --}}
            <section class="{{ $panel }} overflow-hidden">
                <div class="flex items-center gap-2 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <flux:icon.bell-alert variant="mini" class="size-4.5 text-amber-500" />
                    <h2 class="flex-1 font-semibold text-zinc-900 dark:text-white">{{ __('Payments due') }}</h2>
                </div>
                @forelse ($this->duePayments as $payment)
                    @php
                        $days = (int) today()->diffInDays($payment->due_date, false);
                        $tone = $days < 0 ? 'rose' : ($days <= Payment::remindDays() ? 'amber' : 'zinc');
                    @endphp
                    <a href="{{ route('clients.show', ['client' => $payment->client_id, 'tab' => 'payments']) }}" wire:navigate class="flex items-center gap-3 border-b border-zinc-100 px-5 py-3 transition last:border-0 hover:bg-zinc-50 dark:border-white/5 dark:hover:bg-white/[0.03]">
                        @if ($payment->client?->avatarUrl())
                            <img src="{{ $payment->client->avatarUrl() }}" alt="" class="size-9 shrink-0 rounded-full object-cover">
                        @else
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">{{ $payment->client?->initials() }}</span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $payment->client?->displayName() }}</p>
                            <p @class(['text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $tone === 'rose', 'font-medium text-amber-600 dark:text-amber-400' => $tone === 'amber', 'text-zinc-500' => $tone === 'zinc'])>
                                {{ $days < 0 ? trans_choice(':count day late|:count days late', -$days) : ($days === 0 ? __('Due today') : $payment->due_date->toNepali()) }}
                            </p>
                        </div>
                        <span class="text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money($payment->amount) }}</span>
                    </a>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-zinc-500">{{ __('No payments waiting.') }}</p>
                @endforelse
            </section>

            {{-- Agreements ending --}}
            <section class="{{ $panel }} p-5">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Agreements ending soon') }}</h2>
                <div class="mt-3 flex flex-col gap-2">
                    @forelse ($this->endingSoon as $client)
                        @php $left = (int) today()->diffInDays($client->contract_end); @endphp
                        <a href="{{ route('clients.show', $client) }}" wire:navigate class="flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span @class(['flex size-10 shrink-0 flex-col items-center justify-center rounded-lg leading-none', 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' => $left <= 7, 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300' => $left > 7])>
                                <span class="text-sm font-bold tabular-nums">{{ $left }}</span><span class="text-[0.6rem] font-medium">{{ __('days') }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $client->displayName() }}</p>
                                <p class="text-xs text-zinc-500">{{ __('Ends :date', ['date' => $client->contract_end->toNepali()]) }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500">{{ __('No agreements end in the next 30 days.') }}</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>
