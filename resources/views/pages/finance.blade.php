<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Models\Transaction;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Finance')] class extends Component {
    use WithFileUploads;

    /** BS month shown, e.g. "2083-06". */
    #[Url(as: 'month')]
    public string $period = '';

    #[Url(except: 'all')]
    public string $show = 'all';

    public string $search = '';

    // Add income / expense
    public string $kind = 'expense';
    public string $title = '';
    public string $category = '';
    public string $amount = '';
    public string $date = '';
    public string $method = '';
    public string $note = '';
    public $receipt;

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->period)) {
            $this->period = NepaliDate::period(today());
        }
    }

    public function shiftMonth(int $by): void
    {
        [$y, $m] = array_map('intval', explode('-', $this->period));
        $index = $y * 12 + $m - 1 + max(-1, min(1, $by));
        $this->period = sprintf('%d-%02d', intdiv($index, 12), $index % 12 + 1);
    }

    public function goToday(): void
    {
        $this->period = NepaliDate::period(today());
    }

    /** First and last AD day of a BS month key. */
    private function range(string $period): array
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return [NepaliDate::toAd($y, $m, 1)->startOfDay(), NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))->endOfDay()];
    }

    /** Totals for one BS month. */
    private function totals(string $period): array
    {
        [$from, $to] = $this->range($period);

        $clientIncome = (float) Payment::whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])->sum('amount');
        $otherIncome = (float) Transaction::where('kind', 'income')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $expenses = (float) Transaction::where('kind', 'expense')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $salaries = (float) SalaryPayment::whereBetween('paid_on', [$from, $to])->sum('amount');

        return [
            'clientIncome' => $clientIncome,
            'otherIncome' => $otherIncome,
            'income' => $clientIncome + $otherIncome,
            'expenses' => $expenses,
            'salaries' => $salaries,
            'spent' => $expenses + $salaries,
        ];
    }

    #[Computed]
    public function summary(): array
    {
        $now = $this->totals($this->period);
        $prev = $this->totals($this->previousPeriod());
        $unpaid = Payment::whereNull('paid_at')->get();

        return $now + [
            'net' => $now['income'] - $now['spent'],
            'prevNet' => $prev['income'] - $prev['spent'],
            'prevIncome' => $prev['income'],
            'prevSpent' => $prev['spent'],
            'outstanding' => (float) $unpaid->sum('amount'),
            'overdue' => $unpaid->filter(fn ($p) => $p->status() === 'overdue')->count(),
        ];
    }

    private function previousPeriod(): string
    {
        [$y, $m] = array_map('intval', explode('-', $this->period));

        return $m === 1 ? sprintf('%d-12', $y - 1) : sprintf('%d-%02d', $y, $m - 1);
    }

    /** The six months ending with the shown one, oldest first. */
    #[Computed]
    public function trend(): array
    {
        [$y, $m] = array_map('intval', explode('-', $this->period));

        return collect(NepaliDate::recentPeriods(6, NepaliDate::toAd($y, $m, 1)))
            ->reverse()
            ->map(fn ($p) => ['period' => $p] + $this->totals($p))
            ->values()
            ->all();
    }

    /** Where the money went this month, biggest first. */
    #[Computed]
    public function breakdown(): array
    {
        [$from, $to] = $this->range($this->period);

        $rows = Transaction::where('kind', 'expense')->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, sum(amount) as total')->groupBy('category')->pluck('total', 'category')
            ->mapWithKeys(fn ($total, $cat) => [Transaction::EXPENSE_CATEGORIES[$cat]['label'] ?? 'Other expense' => ['total' => (float) $total, 'icon' => Transaction::EXPENSE_CATEGORIES[$cat]['icon'] ?? 'tag']]);

        if ($this->summary['salaries'] > 0) {
            $rows['Salaries'] = ['total' => $this->summary['salaries'], 'icon' => 'user-group'];
        }

        return $rows->sortByDesc('total')->all();
    }

    /** Every money movement of the month: client payments, other income, expenses and salaries. */
    #[Computed]
    public function entries()
    {
        [$from, $to] = $this->range($this->period);

        $payments = Payment::with('client')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])->get()
            ->map(fn ($p) => [
                'key' => 'p'.$p->id, 'kind' => 'income', 'source' => 'client', 'date' => $p->paid_at->copy()->startOfDay(),
                'title' => $p->client?->displayName() ?? __('Client payment'), 'category' => $p->typeLabel(), 'icon' => 'building-office-2',
                'amount' => (float) $p->amount, 'method' => $p->method, 'note' => $p->reference, 'link' => $p->client ? route('clients.show', ['client' => $p->client_id, 'tab' => 'payments']) : null,
                'proof' => $p->proof_path ? route('payments.proof', $p) : null, 'id' => null,
            ]);

        $salaries = SalaryPayment::with('employee')->whereBetween('paid_on', [$from, $to])->get()
            ->map(fn ($s) => [
                'key' => 's'.$s->id, 'kind' => 'expense', 'source' => 'salary', 'date' => $s->paid_on,
                'title' => $s->employee?->name ?? __('Salary'), 'category' => __('Salary · :month', ['month' => NepaliDate::periodLabel($s->period)]), 'icon' => 'user-group',
                'amount' => (float) $s->amount, 'method' => null, 'note' => $s->note, 'link' => $s->employee ? route('employees.show', ['employee' => $s->employee_id, 'tab' => 'salary']) : null,
                'proof' => null, 'id' => null,
            ]);

        $manual = Transaction::whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->map(fn ($t) => [
                'key' => 't'.$t->id, 'kind' => $t->kind, 'source' => 'manual', 'date' => $t->date,
                'title' => $t->title, 'category' => $t->categoryLabel(), 'icon' => Transaction::categories($t->kind)[$t->category]['icon'] ?? 'tag',
                'amount' => (float) $t->amount, 'method' => $t->method, 'note' => $t->note, 'link' => null,
                'proof' => $t->receipt_path ? route('transactions.receipt', $t) : null, 'id' => $t->id,
            ]);

        return $payments->concat($salaries)->concat($manual)->sortByDesc(fn ($e) => $e['date']->timestamp.$e['key'])->values();
    }

    // Add / delete

    public function newEntry(string $kind): void
    {
        $this->resetErrorBag();
        $this->reset('title', 'amount', 'method', 'note', 'receipt');
        $this->kind = $kind === 'income' ? 'income' : 'expense';
        $this->category = array_key_first(Transaction::categories($this->kind));
        [$from, $to] = $this->range($this->period);
        $this->date = today()->between($from, $to) ? today()->toDateString() : $from->toDateString();

        Flux::modal('entry-form')->show();
    }

    public function updatedKind(): void
    {
        $this->category = array_key_first(Transaction::categories($this->kind === 'income' ? 'income' : 'expense'));
    }

    public function saveEntry(): void
    {
        $data = $this->validate([
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(Transaction::categories($this->kind)))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'date' => ['required', 'date'],
            'method' => ['nullable', Rule::in(Payment::METHODS)],
            'note' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        Transaction::create([
            'kind' => $data['kind'],
            'title' => $data['title'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'date' => $data['date'],
            'method' => $data['method'] ?: null,
            'note' => $data['note'] ?: null,
            'receipt_path' => $this->receipt?->store('receipts', 'local'),
        ]);

        // Show the month the entry belongs to.
        $this->period = NepaliDate::period(Carbon::parse($data['date']));
        $this->reset('receipt');
        Flux::modal('entry-form')->close();
        Flux::toast(variant: 'success', text: $data['kind'] === 'income' ? __('Income added.') : __('Expense added.'));
    }

    public function deleteEntry(int $id): void
    {
        Transaction::findOrFail($id)->delete();
        Flux::toast(text: __('Entry deleted.'));
    }
}; ?>

@php
    $money = fn ($v) => Client::money(round($v));
    $sum = $this->summary;
    $trend = $this->trend;
    $breakdown = $this->breakdown;
    $entries = $this->entries;
    $label = NepaliDate::periodLabel($period);
    $isNow = $period === NepaliDate::period(today());
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $iconTile = 'flex size-9 shrink-0 items-center justify-center rounded-lg';
    $change = fn (float $now, float $before) => $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    $maxBar = max(1, collect($trend)->max(fn ($t) => max($t['income'], $t['spent'])));
    $spentTotal = max(1, collect($breakdown)->sum('total'));
    $methodIcons = ['Bank' => 'building-library', 'eSewa' => 'device-phone-mobile', 'Khalti' => 'wallet', 'Cash' => 'banknotes', 'Cheque' => 'document-text', 'Other' => 'ellipsis-horizontal-circle'];
    // Chart colours: brand blue (income) and amber (spending), validated for colour-blind separation.
    $incomeColor = 'bg-[#3151FE]';
    $spentColor = 'bg-[#D97706]';
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Finance') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Money in, money out and what is left, month by month.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center rounded-lg bg-white ring-1 ring-zinc-200 dark:bg-zinc-950 dark:ring-white/10">
                <button type="button" wire:click="shiftMonth(-1)" class="flex size-10 items-center justify-center rounded-s-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Previous month') }}"><flux:icon.chevron-left variant="mini" class="size-4" /></button>
                <button type="button" wire:click="goToday" class="h-10 min-w-36 border-x border-zinc-200 px-3 text-sm font-semibold text-zinc-800 dark:border-white/10 dark:text-zinc-100" title="{{ __('Back to this month') }}">{{ $label }}</button>
                <button type="button" wire:click="shiftMonth(1)" class="flex size-10 items-center justify-center rounded-e-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Next month') }}"><flux:icon.chevron-right variant="mini" class="size-4" /></button>
            </div>
            <flux:button icon="arrow-down-left" wire:click="newEntry('income')" data-test="add-income">{{ __('Add income') }}</flux:button>
            <flux:button variant="primary" icon="arrow-up-right" wire:click="newEntry('expense')" class="shadow-lg shadow-brand-500/25" data-test="add-expense">{{ __('Add expense') }}</flux:button>
        </div>
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['label' => __('Money in'), 'value' => $sum['income'], 'icon' => 'arrow-down-left', 'tile' => 'bg-brand-500 text-white shadow-md shadow-brand-500/30', 'sub' => __(':client from clients · :other other', ['client' => $money($sum['clientIncome']), 'other' => $money($sum['otherIncome'])]), 'delta' => $change($sum['income'], $sum['prevIncome']), 'good' => 'up'],
                ['label' => __('Money out'), 'value' => $sum['spent'], 'icon' => 'arrow-up-right', 'tile' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'sub' => __(':exp expenses · :sal salaries', ['exp' => $money($sum['expenses']), 'sal' => $money($sum['salaries'])]), 'delta' => $change($sum['spent'], $sum['prevSpent']), 'good' => 'down'],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="{{ $panel }} flex flex-col gap-4 p-5">
                <div class="flex items-center gap-3">
                    <span class="{{ $iconTile }} {{ $card['tile'] }}"><flux:icon :icon="$card['icon']" variant="mini" class="size-4.5" /></span>
                    <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $card['label'] }}</p>
                    @if ($card['delta'] !== null)
                        @php $better = $card['good'] === 'up' ? $card['delta'] >= 0 : $card['delta'] <= 0; @endphp
                        <span @class(['inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $better, 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => ! $better]) title="{{ __('Compared with last month') }}">
                            <flux:icon :icon="$card['delta'] >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" variant="micro" class="size-3.5" />{{ abs($card['delta']) }}%
                        </span>
                    @endif
                </div>
                <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($card['value']) }}</p>
                <p class="mt-auto truncate text-xs text-zinc-500">{{ $card['sub'] }}</p>
            </div>
        @endforeach

        <div @class([
            'flex flex-col gap-4 rounded-xl p-5 ring-1',
            'bg-linear-to-br from-emerald-50 to-white ring-emerald-200/80 dark:from-emerald-500/10 dark:to-zinc-950 dark:ring-emerald-500/20' => $sum['net'] >= 0,
            'bg-linear-to-br from-rose-50 to-white ring-rose-200/80 dark:from-rose-500/10 dark:to-zinc-950 dark:ring-rose-500/20' => $sum['net'] < 0,
        ])>
            <div class="flex items-center gap-3">
                <span @class([$iconTile, 'bg-emerald-500 text-white' => $sum['net'] >= 0, 'bg-rose-500 text-white' => $sum['net'] < 0])><flux:icon :icon="$sum['net'] >= 0 ? 'scale' : 'exclamation-triangle'" variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $sum['net'] >= 0 ? __('Profit') : __('Loss') }}</p>
            </div>
            <p @class(['text-[1.625rem] leading-none font-semibold tracking-tight tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $sum['net'] >= 0, 'text-rose-700 dark:text-rose-300' => $sum['net'] < 0])>{{ $sum['net'] < 0 ? '− ' : '' }}{{ $money(abs($sum['net'])) }}</p>
            <p class="mt-auto text-xs text-zinc-500">
                @if ($sum['income'] > 0)
                    {{ __(':pct% of money in kept', ['pct' => (int) round($sum['net'] / $sum['income'] * 100)]) }}
                @else
                    {{ __('No money in yet this month') }}
                @endif
            </p>
        </div>

        <a href="{{ route('clients.index') }}" wire:navigate class="{{ $panel }} group flex flex-col gap-4 p-5 transition hover:ring-rose-300">
            <div class="flex items-center gap-3">
                <span class="{{ $iconTile }} bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.clock variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Still to collect') }}</p>
                @if ($sum['overdue'])
                    <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $sum['overdue']) }}</span>
                @endif
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($sum['outstanding']) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ __('Unpaid client payments, all months') }}</p>
        </a>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        {{-- Trend --}}
        <section class="{{ $panel }} overflow-hidden" x-data="{ table: false }">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Money in and out') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Last 6 months') }}</p>
                </div>
                <div class="flex items-center gap-4 text-xs text-zinc-600 dark:text-zinc-300">
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $incomeColor }} size-2.5 rounded-sm"></span>{{ __('Money in') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $spentColor }} size-2.5 rounded-sm"></span>{{ __('Money out') }}</span>
                    <button type="button" @click="table = ! table" class="rounded-md px-2 py-1 font-medium text-zinc-500 ring-1 ring-zinc-200 hover:text-zinc-800 dark:ring-white/10" x-text="table ? @js(__('Chart')) : @js(__('Table'))"></button>
                </div>
            </div>

            <div x-show="! table" class="flex h-64 items-end gap-3 border-t border-zinc-100 px-5 pt-8 pb-4 sm:gap-5 dark:border-white/5">
                @foreach ($trend as $t)
                    @php
                        [, $mo] = explode('-', $t['period']);
                        $current = $t['period'] === $period;
                    @endphp
                    <div class="group relative flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2">
                        {{-- Hover tooltip --}}
                        <div class="pointer-events-none absolute -top-6 left-1/2 z-10 hidden w-44 -translate-x-1/2 rounded-lg bg-zinc-900 px-3 py-2 text-xs text-white shadow-lg group-hover:block dark:bg-white dark:text-zinc-900">
                            <p class="font-semibold">{{ NepaliDate::periodLabel($t['period']) }}</p>
                            <p class="mt-1 flex justify-between gap-2"><span class="opacity-75">{{ __('In') }}</span><span class="tabular-nums">{{ $money($t['income']) }}</span></p>
                            <p class="flex justify-between gap-2"><span class="opacity-75">{{ __('Out') }}</span><span class="tabular-nums">{{ $money($t['spent']) }}</span></p>
                            <p class="mt-1 flex justify-between gap-2 border-t border-white/20 pt-1 dark:border-zinc-200"><span class="opacity-75">{{ __('Net') }}</span><span class="font-semibold tabular-nums">{{ $money($t['income'] - $t['spent']) }}</span></p>
                        </div>
                        <div class="flex h-full w-full items-end justify-center gap-0.5">
                            <div class="{{ $incomeColor }} w-full max-w-7 rounded-t-[4px] transition-[height] duration-700 group-hover:opacity-90" style="height: {{ max(1, round($t['income'] / $maxBar * 100)) }}%"></div>
                            <div class="{{ $spentColor }} w-full max-w-7 rounded-t-[4px] transition-[height] duration-700 group-hover:opacity-90" style="height: {{ max(1, round($t['spent'] / $maxBar * 100)) }}%"></div>
                        </div>
                        <button type="button" wire:click="$set('period', '{{ $t['period'] }}')" @class(['text-[0.6875rem] whitespace-nowrap', 'font-semibold text-brand-600 dark:text-brand-300' => $current, 'text-zinc-500 hover:text-zinc-800' => ! $current])>{{ mb_substr(NepaliDate::MONTHS[(int) $mo - 1], 0, 3) }}</button>
                    </div>
                @endforeach
            </div>

            <div x-show="table" x-cloak class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                <table class="w-full text-sm">
                    <thead><tr class="text-xs text-zinc-500"><th class="px-5 py-2 text-start font-medium">{{ __('Month') }}</th><th class="px-5 py-2 text-end font-medium">{{ __('Money in') }}</th><th class="px-5 py-2 text-end font-medium">{{ __('Money out') }}</th><th class="px-5 py-2 text-end font-medium">{{ __('Net') }}</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach (array_reverse($trend) as $t)
                            <tr>
                                <td class="px-5 py-2.5 font-medium text-zinc-800 dark:text-zinc-100">{{ NepaliDate::periodLabel($t['period']) }}</td>
                                <td class="px-5 py-2.5 text-end tabular-nums">{{ $money($t['income']) }}</td>
                                <td class="px-5 py-2.5 text-end tabular-nums">{{ $money($t['spent']) }}</td>
                                <td @class(['px-5 py-2.5 text-end font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $t['income'] >= $t['spent'], 'text-rose-700 dark:text-rose-300' => $t['income'] < $t['spent']])>{{ $money($t['income'] - $t['spent']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Where it went --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="px-5 py-4">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Where the money went') }}</h2>
                <p class="text-xs text-zinc-500">{{ $label }} · {{ $money($sum['spent']) }}</p>
            </div>
            @if ($breakdown === [])
                <div class="flex flex-col items-center gap-2 border-t border-zinc-100 px-5 py-10 text-center dark:border-white/5">
                    <p class="text-sm text-zinc-500">{{ __('No spending recorded this month.') }}</p>
                    <flux:button size="sm" icon="plus" wire:click="newEntry('expense')">{{ __('Add expense') }}</flux:button>
                </div>
            @else
                <ul class="flex flex-col gap-3.5 border-t border-zinc-100 px-5 py-4 dark:border-white/5">
                    @foreach ($breakdown as $name => $row)
                        @php $pct = (int) round($row['total'] / $spentTotal * 100); @endphp
                        <li wire:key="cat-{{ \Illuminate\Support\Str::slug($name) }}">
                            <div class="flex items-center gap-2.5 text-sm">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"><flux:icon :icon="$row['icon']" variant="micro" class="size-3.5" /></span>
                                <span class="min-w-0 flex-1 truncate font-medium text-zinc-800 dark:text-zinc-100">{{ __($name) }}</span>
                                <span class="text-zinc-500 tabular-nums">{{ $pct }}%</span>
                                <span class="w-28 text-end font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money($row['total']) }}</span>
                            </div>
                            <div class="ms-9.5 mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="{{ $spentColor }} h-full rounded-full" style="width: {{ max(2, $pct) }}%"></div></div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- Transactions --}}
    @php
        $q = mb_strtolower(trim($search));
        $visible = $entries
            ->filter(fn ($e) => $show === 'all' || $e['kind'] === $show)
            ->filter(fn ($e) => $q === '' || str_contains(mb_strtolower($e['title'].' '.$e['category'].' '.$e['note']), $q));
    @endphp
    <section class="{{ $panel }} overflow-hidden">
        <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Transactions') }}</h2>
                <p class="text-xs text-zinc-500">{{ $label }} · {{ trans_choice(':count entry|:count entries', $entries->count()) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex gap-1 rounded-xl bg-zinc-100 p-1 text-xs font-medium dark:bg-white/5">
                    @foreach (['all' => [__('All'), $entries->count()], 'income' => [__('Money in'), $entries->where('kind', 'income')->count()], 'expense' => [__('Money out'), $entries->where('kind', 'expense')->count()]] as $key => [$flabel, $fcount])
                        <button type="button" wire:click="$set('show', '{{ $key }}')" @class(['flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' => $show === $key, 'text-zinc-500 hover:text-zinc-800 dark:hover:text-white' => $show !== $key])>
                            {{ $flabel }} <span @class(['tabular-nums', 'text-brand-600 dark:text-brand-300' => $show === $key, 'text-zinc-400' => $show !== $key])>{{ $fcount }}</span>
                        </button>
                    @endforeach
                </div>
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search')" clearable size="sm" class="w-56" />
            </div>
        </div>

        @if ($visible->isEmpty())
            <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.receipt-percent class="size-6" /></span>
                <p class="text-sm text-zinc-500">{{ $entries->isEmpty() ? __('Nothing recorded in :month yet.', ['month' => $label]) : __('Nothing matches.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                @foreach ($visible as $e)
                    @php
                        $in = $e['kind'] === 'income';
                        $bs = NepaliDate::fromAd($e['date']);
                        $sourceLabel = ['client' => __('Client payment'), 'salary' => __('Salary'), 'manual' => $in ? __('Income') : __('Expense')][$e['source']];
                    @endphp
                    <li wire:key="entry-{{ $e['key'] }}" class="group flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition hover:bg-zinc-50/70 sm:flex-nowrap dark:hover:bg-white/[0.02]">
                        <div class="flex w-40 shrink-0 items-center gap-3" title="{{ $e['date']->format('l, j M Y') }}">
                            <span @class(['flex size-10 shrink-0 items-center justify-center rounded-xl', 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300' => $in, 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ! $in])>
                                <flux:icon :icon="$in ? 'arrow-down-left' : 'arrow-up-right'" variant="mini" class="size-5" />
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $bs ? $bs[2].' '.NepaliDate::MONTHS[$bs[1] - 1] : $e['date']->format('j M') }}</p>
                                <p class="text-xs text-zinc-500">{{ $e['date']->format('D, j M') }}</p>
                            </div>
                        </div>

                        <div class="min-w-0 flex-1">
                            @if ($e['link'])
                                <a href="{{ $e['link'] }}" wire:navigate class="block truncate text-sm font-semibold text-zinc-900 hover:text-brand-600 dark:text-white">{{ $e['title'] }}</a>
                            @else
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $e['title'] }}</p>
                            @endif
                            <div class="mt-1 flex min-w-0 flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-700 dark:bg-white/10 dark:text-zinc-200">
                                    <flux:icon :icon="$e['icon']" variant="micro" class="size-3.5" />{{ $e['category'] }}
                                </span>
                                <span class="text-xs text-zinc-400">{{ $sourceLabel }}</span>
                                @if ($e['note'])
                                    <span class="truncate text-xs text-zinc-500">· {{ $e['note'] }}</span>
                                @endif
                                @if ($e['proof'])
                                    <a href="{{ $e['proof'] }}" target="_blank" class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.paper-clip variant="micro" class="size-3.5" />{{ __('Receipt') }}</a>
                                @endif
                            </div>
                        </div>

                        <div class="hidden w-28 shrink-0 md:block">
                            @if ($e['method'])
                                <span class="inline-flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$methodIcons[$e['method']] ?? 'credit-card'" variant="micro" class="size-4 text-zinc-400" />{{ __($e['method']) }}</span>
                            @endif
                        </div>

                        <p @class(['w-32 shrink-0 text-end text-base font-semibold tabular-nums', 'text-brand-700 dark:text-brand-300' => $in, 'text-zinc-900 dark:text-white' => ! $in])>{{ $in ? '+' : '−' }} {{ $money($e['amount']) }}</p>

                        <div class="w-8 shrink-0 text-end">
                            @if ($e['id'])
                                <button type="button" wire:click="deleteEntry({{ $e['id'] }})" wire:confirm="{{ __('Delete this entry?') }}" class="rounded-md p-1.5 text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-500 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete') }}"><flux:icon.trash variant="micro" class="size-4" /></button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Add income / expense --}}
    <flux:modal name="entry-form" class="w-full max-w-xl overflow-visible p-0!" :closable="false">
        <form wire:submit="saveEntry" class="flex flex-col">
            <div class="flex items-center gap-4 border-b border-zinc-100 px-6 py-5 dark:border-white/5">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow-md" :class="$wire.kind === 'income' ? 'bg-brand-500 shadow-brand-500/30' : 'bg-amber-500 shadow-amber-500/30'">
                    <flux:icon.arrows-up-down variant="mini" class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg" x-text="$wire.kind === 'income' ? @js(__('Add income')) : @js(__('Add expense'))"></flux:heading>
                    <p class="text-sm text-zinc-500">{{ __('Client payments and salaries are added on their own pages.') }}</p>
                </div>
                <flux:modal.close><flux:button variant="ghost" size="sm" icon="x-mark" :aria-label="__('Close')" /></flux:modal.close>
            </div>

            <div class="flex flex-col gap-5 px-6 py-5">
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                    <button type="button" wire:click="$set('kind', 'income')" class="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition" :class="$wire.kind === 'income' ? 'bg-white text-brand-700 shadow-sm dark:bg-zinc-800 dark:text-brand-300' : 'text-zinc-500'"><flux:icon.arrow-down-left variant="micro" class="size-4" />{{ __('Money in') }}</button>
                    <button type="button" wire:click="$set('kind', 'expense')" class="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition" :class="$wire.kind === 'expense' ? 'bg-white text-amber-700 shadow-sm dark:bg-zinc-800 dark:text-amber-300' : 'text-zinc-500'"><flux:icon.arrow-up-right variant="micro" class="size-4" />{{ __('Money out') }}</button>
                </div>

                <flux:input wire:model="title" :label="__('What was it for')" :placeholder="$kind === 'income' ? __('e.g. Logo design for a friend') : __('e.g. Office rent for Kartik')" required />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="entry-amount" class="mb-1.5 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Amount') }}</label>
                        <div class="flex items-center rounded-lg bg-white ring-1 ring-zinc-200 focus-within:ring-2 focus-within:ring-brand-500 dark:bg-white/5 dark:ring-white/10">
                            <span class="ps-3 text-sm font-semibold text-zinc-400">{{ config('app.currency') }}</span>
                            <input id="entry-amount" wire:model="amount" type="number" min="0" step="0.01" inputmode="decimal" required placeholder="0" class="w-full border-0 bg-transparent px-2.5 py-2 text-sm font-semibold text-zinc-900 tabular-nums focus:ring-0 focus:outline-none dark:text-white" />
                        </div>
                        <flux:error name="amount" />
                    </div>
                    <x-nepali-date wire:model="date" :label="__('Date')" />
                </div>

                <div>
                    <span class="mb-2 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Category') }}</span>
                    <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                        @foreach (Transaction::categories($kind) as $key => $cat)
                            <button type="button" wire:key="cat-opt-{{ $kind }}-{{ $key }}" @click="$wire.category = '{{ $key }}'" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-start text-xs font-medium ring-1 transition" :class="$wire.category === '{{ $key }}' ? 'bg-brand-50 text-brand-700 ring-2 ring-brand-500 dark:bg-brand-500/10 dark:text-brand-200' : 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:hover:bg-white/5'">
                                <flux:icon :icon="$cat['icon']" variant="micro" class="size-4 shrink-0" /><span class="truncate">{{ __($cat['label']) }}</span>
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="category" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="method" :label="__('Paid with / received in')">
                        <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                        @foreach (Payment::METHODS as $m)
                            <flux:select.option :value="$m">{{ __($m) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="note" :label="__('Note')" :placeholder="__('Optional')" />
                </div>

                <div>
                    <span class="mb-1.5 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Receipt') }} <span class="font-normal text-zinc-400">{{ __('optional') }}</span></span>
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed border-zinc-200 px-4 py-3 transition hover:border-brand-300 hover:bg-brand-50/30 dark:border-white/10">
                        <flux:icon :icon="$receipt ? 'document-check' : 'camera'" variant="mini" @class(['size-5', 'text-emerald-500' => $receipt, 'text-zinc-400' => ! $receipt]) />
                        <span class="min-w-0 flex-1 truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $receipt ? $receipt->getClientOriginalName() : __('Add a photo or PDF of the bill') }}</span>
                        <input type="file" wire:model="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*" class="sr-only">
                    </label>
                    <div wire:loading wire:target="receipt" class="mt-1 text-xs text-zinc-500">{{ __('Uploading…') }}</div>
                    <flux:error name="receipt" />
                </div>
            </div>

            <div class="flex justify-end gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-entry">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
