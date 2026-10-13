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

    /** Date range shown (AD, Y-m-d). Defaults to the current Nepali month. */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    // Filter panel (applied with the Apply button)
    public string $filterFrom = '';
    public string $filterTo = '';

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
        $valid = fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
        if (! $valid($this->from) || ! $valid($this->to) || $this->from > $this->to) {
            $this->setMonth(NepaliDate::period(today()));
        }
        [$this->filterFrom, $this->filterTo] = [$this->from, $this->to];
    }

    public function openFilter(): void
    {
        [$this->filterFrom, $this->filterTo] = [$this->from, $this->to];
        $this->resetErrorBag();
    }

    public function applyFilter(): void
    {
        $data = $this->validate([
            'filterFrom' => ['required', 'date'],
            'filterTo' => ['required', 'date'],
        ], attributes: ['filterFrom' => __('from date'), 'filterTo' => __('to date')]);

        [$this->from, $this->to] = [$data['filterFrom'], $data['filterTo']];
        $this->updated('from');
        $this->dispatch('filter-applied');
    }

    public function resetFilter(): void
    {
        $this->setMonth(NepaliDate::period(today()));
        [$this->filterFrom, $this->filterTo] = [$this->from, $this->to];
        $this->dispatch('filter-applied');
    }

    /** Picking a date: ignore blanks and keep From on or before To. */
    public function updated($property): void
    {
        if (! in_array($property, ['from', 'to'])) {
            return;
        }

        foreach (['from', 'to'] as $field) {
            $this->{$field} = strtotime($this->{$field}) ? Carbon::parse($this->{$field})->toDateString() : today()->toDateString();
        }
        if ($this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }
    }

    /** Shows one whole BS month, e.g. "2083-06". */
    private function setMonth(string $period): void
    {
        [$y, $m] = array_map('intval', explode('-', $period));
        $this->from = NepaliDate::toAd($y, $m, 1)->toDateString();
        $this->to = NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))->toDateString();
    }

    /** The BS month key when the range is exactly one whole month, otherwise null. */
    #[Computed]
    public function monthKey(): ?string
    {
        $period = NepaliDate::period(Carbon::parse($this->from));
        [$from, $to] = $this->monthRange($period);

        return $from->toDateString() === $this->from && $to->toDateString() === $this->to ? $period : null;
    }

    /** First and last AD day of a BS month key. */
    private function monthRange(string $period): array
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return [NepaliDate::toAd($y, $m, 1)->startOfDay(), NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))->endOfDay()];
    }

    /** The shown range as start/end of day. */
    private function range(): array
    {
        return [Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay()];
    }

    /** Totals for a date range. */
    private function totals(Carbon $from, Carbon $to): array
    {
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
        [$from, $to] = $this->range();
        $now = $this->totals($from, $to);
        // Compare with the month before, or the same number of days before a custom range.
        $prevRange = $this->monthKey
            ? $this->monthRange(NepaliDate::period($from->copy()->subDay()))
            : [$from->copy()->subDays($from->diffInDays($to) + 1)->startOfDay(), $from->copy()->subDay()->endOfDay()];
        $prev = $this->totals(...$prevRange);
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

    /** Every money movement of the month: client payments, other income, expenses and salaries. */
    #[Computed]
    public function entries()
    {
        [$from, $to] = $this->range();

        $payments = Payment::with('client')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])->get()
            ->map(fn ($p) => [
                'key' => 'p'.$p->id, 'kind' => 'income', 'source' => 'client', 'date' => $p->paid_at->copy()->startOfDay(),
                'title' => $p->client?->displayName() ?? __('Client payment'), 'category' => __(Client::SERVICE_TYPES[$p->client?->service_type]['label'] ?? 'Client'), 'icon' => 'building-office-2',
                'amount' => (float) $p->amount, 'method' => $p->method, 'reference' => $p->reference, 'note' => $p->note, 'recorded' => $p->created_at->toNepali(), 'link' => $p->client ? route('clients.show', ['client' => $p->client_id, 'tab' => 'payments']) : null,
                'proof' => $p->proof_path ? route('payments.proof', $p) : null, 'id' => null,
            ]);

        $salaries = SalaryPayment::with('employee')->whereBetween('paid_on', [$from, $to])->get()
            ->map(fn ($s) => [
                'key' => 's'.$s->id, 'kind' => 'expense', 'source' => 'salary', 'date' => $s->paid_on,
                'title' => $s->employee?->name ?? __('Salary'), 'category' => __('Salary'), 'icon' => 'user-group',
                'amount' => (float) $s->amount, 'method' => null, 'reference' => null, 'note' => $s->note, 'recorded' => $s->created_at->toNepali(), 'link' => $s->employee ? route('employees.show', ['employee' => $s->employee_id, 'tab' => 'salary']) : null,
                'proof' => null, 'id' => null,
            ]);

        $manual = Transaction::whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->map(fn ($t) => [
                'key' => 't'.$t->id, 'kind' => $t->kind, 'source' => 'manual', 'date' => $t->date,
                'title' => $t->title, 'category' => $t->categoryLabel(), 'icon' => Transaction::categories($t->kind)[$t->category]['icon'] ?? 'tag',
                'amount' => (float) $t->amount, 'method' => $t->method, 'reference' => null, 'note' => $t->note, 'recorded' => $t->created_at->toNepali(), 'link' => null,
                'proof' => $t->receipt_path ? route('transactions.receipt', $t) : null, 'id' => $t->id,
            ]);

        return $payments->concat($salaries)->concat($manual)->sortByDesc(fn ($e) => $e['date']->timestamp.$e['key'])->values();
    }

    /** Entries after the In/Out filter and search. */
    #[Computed]
    public function visible()
    {
        $q = mb_strtolower(trim($this->search));

        return $this->entries
            ->filter(fn ($e) => $this->show === 'all' || $e['kind'] === $this->show)
            ->filter(fn ($e) => $q === '' || str_contains(mb_strtolower($e['title'].' '.$e['category'].' '.$e['note'].' '.$e['reference'].' '.$e['method']), $q))
            ->values();
    }

    public function export()
    {
        $rows = $this->visible;
        $name = 'transactions-'.str_replace(' ', '-', strtolower($this->rangeLabel())).'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads Nepali month names correctly
            fputcsv($out, ['Title', 'Date (BS)', 'Date (AD)', 'Category', 'Payment type', 'Method', 'Reference', 'Note', 'Amount', 'In / Out']);
            foreach ($rows as $e) {
                fputcsv($out, [
                    $e['title'], NepaliDate::format($e['date']), $e['date']->toDateString(), $e['category'],
                    $e['kind'] === 'income' ? 'Income' : 'Expense', $e['method'], $e['reference'], $e['note'],
                    ($e['kind'] === 'income' ? '' : '-').number_format($e['amount'], 2, '.', ''), $e['kind'] === 'income' ? 'In' : 'Out',
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "Ashoj 2083" for a whole month, otherwise "1 Bhadra – 26 Ashoj 2083". */
    public function rangeLabel(): string
    {
        if ($this->monthKey) {
            return NepaliDate::periodLabel($this->monthKey);
        }

        [$from, $to] = $this->range();
        $sameYear = NepaliDate::fromAd($from)[0] === NepaliDate::fromAd($to)[0];

        return NepaliDate::format($from, withYear: ! $sameYear).' – '.NepaliDate::format($to);
    }

    // Add / delete

    public function newEntry(string $kind): void
    {
        $this->resetErrorBag();
        $this->reset('title', 'amount', 'method', 'note', 'receipt');
        $this->kind = $kind === 'income' ? 'income' : 'expense';
        $this->category = array_key_first(Transaction::categories($this->kind));
        [$from, $to] = $this->range();
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

        // Show the month the entry belongs to when it falls outside the range.
        if (! Carbon::parse($data['date'])->between(...$this->range())) {
            $this->setMonth(NepaliDate::period(Carbon::parse($data['date'])));
        }
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
    $entries = $this->entries;
    $label = $this->rangeLabel();
    $isMonth = $this->monthKey !== null;
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $iconTile = 'flex size-9 shrink-0 items-center justify-center rounded-lg';
    $change = fn (float $now, float $before) => $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    $methodIcons = ['Bank' => 'building-library', 'eSewa' => 'device-phone-mobile', 'Khalti' => 'wallet', 'Cash' => 'banknotes', 'Cheque' => 'document-text', 'Other' => 'ellipsis-horizontal-circle'];
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Finance') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Money in, money out and what is left.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- Date filter --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" @filter-applied.window="open = false">
                <button type="button" @click="open = ! open" class="inline-flex h-10 items-center gap-2 rounded-lg bg-white px-3.5 text-sm font-medium text-zinc-700 shadow-xs ring-1 ring-zinc-200 transition hover:bg-zinc-50 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10" :class="open && 'ring-2 ring-brand-500'" :aria-expanded="open" data-test="date-filter">
                    <flux:icon.funnel variant="micro" class="size-4 text-zinc-500" />
                    {{ __('Filter') }}
                    <span class="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ $label }}</span>
                    <flux:icon.chevron-down variant="micro" class="size-4 text-zinc-400 transition" ::class="open && 'rotate-180'" />
                </button>

                <div x-show="open" x-transition.origin.top.right x-cloak class="absolute right-0 z-40 mt-2 w-[22rem] rounded-xl bg-white p-4 shadow-[0_20px_50px_-12px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Filter by date') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('Show transactions between two dates.') }}</p>
                    <form wire:submit="applyFilter" class="mt-4 flex flex-col gap-3">
                        <x-nepali-date wire:model="filterFrom" :label="__('From')" />
                        <x-nepali-date wire:model="filterTo" :label="__('To')" />
                        <flux:error name="filterFrom" />
                        <flux:error name="filterTo" />
                        <div class="mt-1 flex items-center justify-between gap-2">
                            <button type="button" wire:click="resetFilter" class="text-sm font-medium text-zinc-500 hover:text-brand-600">{{ __('This month') }}</button>
                            <div class="flex gap-2">
                                <flux:button size="sm" variant="ghost" @click="open = false">{{ __('Cancel') }}</flux:button>
                                <flux:button size="sm" type="submit" variant="primary" icon="funnel" data-test="apply-filter">{{ __('Apply') }}</flux:button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Export --}}
            <flux:button icon="arrow-down-tray" wire:click="export" data-test="export">{{ __('Export') }}</flux:button>

            <flux:button variant="primary" icon="plus" wire:click="newEntry('expense')" class="shadow-lg shadow-brand-500/25" data-test="add-transaction">{{ __('Add transaction') }}</flux:button>
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
                        <span @class(['inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $better, 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => ! $better]) title="{{ $isMonth ? __('Compared with last month') : __('Compared with the same number of days before') }}">
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

    {{-- Transactions --}}
    @php
        $sourceLabels = ['client' => __('Client payment'), 'salary' => __('Salary'), 'manual' => __('Manual entry')];
        $visible = $this->visible;
        $th = 'px-3 py-2.5 text-start text-[0.6875rem] font-semibold tracking-wide text-zinc-500 uppercase whitespace-nowrap';
        $detailData = $visible->mapWithKeys(fn ($e) => [$e['key'] => [
            'kind' => $e['kind'],
            'title' => $e['title'],
            'category' => $e['category'],
            'source' => $sourceLabels[$e['source']],
            'bs' => NepaliDate::format($e['date']),
            'amount' => $money($e['amount']),
            'method' => $e['method'] ? __($e['method']) : null,
            'reference' => $e['reference'],
            'note' => $e['note'],
            'link' => $e['link'],
            'linkLabel' => ['client' => __('Open client'), 'salary' => __('Open employee')][$e['source']] ?? null,
            'proof' => $e['proof'],
            'id' => $e['id'],
            'recorded' => $e['recorded'],
        ]]);
    @endphp
    <section class="{{ $panel }} overflow-hidden" x-data="{ open: null, rows: @js($detailData) }" @keydown.escape.window="open = null">
        <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Transactions') }}</h2>
                <p class="text-xs text-zinc-500">{{ $label }} · {{ trans_choice(':count entry|:count entries', $entries->count()) }} · {{ __('click a row for details') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                {{-- In / out filter --}}
                @php
                    $tabsDef = [
                        'all' => ['label' => __('All'), 'count' => $entries->count(), 'icon' => 'squares-2x2', 'on' => 'bg-zinc-900 text-white shadow-md shadow-zinc-900/20 dark:bg-white dark:text-zinc-900', 'badge' => 'bg-white/20 dark:bg-zinc-900/10'],
                        'income' => ['label' => __('Money in'), 'count' => $entries->where('kind', 'income')->count(), 'icon' => 'arrow-down-left', 'on' => 'bg-emerald-500 text-white shadow-md shadow-emerald-500/30', 'badge' => 'bg-white/25'],
                        'expense' => ['label' => __('Money out'), 'count' => $entries->where('kind', 'expense')->count(), 'icon' => 'arrow-up-right', 'on' => 'bg-rose-500 text-white shadow-md shadow-rose-500/30', 'badge' => 'bg-white/25'],
                    ];
                @endphp
                <div class="flex gap-1 rounded-xl bg-zinc-100/80 p-1 ring-1 ring-zinc-200/60 dark:bg-white/5 dark:ring-white/10" role="tablist">
                    @foreach ($tabsDef as $key => $t)
                        <button type="button" wire:click="$set('show', '{{ $key }}')" role="tab" aria-selected="{{ $show === $key ? 'true' : 'false' }}" @class([
                            'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                            $t['on'] => $show === $key,
                            'text-zinc-600 hover:bg-white hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white' => $show !== $key,
                        ])>
                            <flux:icon :icon="$t['icon']" variant="micro" class="size-4" />
                            {{ $t['label'] }}
                            <span @class(['min-w-5 rounded-full px-1.5 text-center text-xs font-semibold tabular-nums', $t['badge'] => $show === $key, 'bg-zinc-200/80 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => $show !== $key])>{{ $t['count'] }}</span>
                        </button>
                    @endforeach
                </div>

                {{-- Search --}}
                <label class="group relative flex h-10 w-72 items-center rounded-xl bg-zinc-50 ring-1 ring-zinc-200/80 transition focus-within:bg-white focus-within:ring-2 focus-within:ring-brand-500 dark:bg-white/5 dark:ring-white/10">
                    <flux:icon.magnifying-glass variant="mini" class="pointer-events-none absolute start-3 size-4.5 text-zinc-400 transition group-focus-within:text-brand-500" />
                    <input wire:model.live.debounce.300ms="search" type="search" @keydown.window="if ($event.key === '/' && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { $event.preventDefault(); $el.focus() }" placeholder="{{ __('Search transactions') }}" class="h-full w-full rounded-xl border-0 bg-transparent ps-10 pe-9 text-sm text-zinc-900 placeholder:text-zinc-400 focus:ring-0 focus:outline-none dark:text-white [&::-webkit-search-cancel-button]:hidden" aria-label="{{ __('Search transactions') }}" />
                    @if ($search !== '')
                        <button type="button" wire:click="$set('search', '')" class="absolute end-2 flex size-6 items-center justify-center rounded-md text-zinc-400 hover:bg-zinc-200/70 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Clear search') }}"><flux:icon.x-mark variant="micro" class="size-4" /></button>
                    @else
                        <kbd class="pointer-events-none absolute end-2.5 hidden rounded border border-zinc-200 bg-white px-1.5 text-[0.625rem] font-medium text-zinc-400 sm:block dark:border-white/10 dark:bg-white/5">/</kbd>
                    @endif
                </label>
            </div>
        </div>

        @if ($visible->isEmpty())
            <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.receipt-percent class="size-6" /></span>
                <p class="text-sm text-zinc-500">{{ $entries->isEmpty() ? __('Nothing recorded in :month yet.', ['month' => $label]) : __('Nothing matches.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                <table class="w-full min-w-[58rem] text-sm">
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
                        @foreach ($visible->values() as $e)
                            @php
                                $in = $e['kind'] === 'income';
                                $bs = NepaliDate::fromAd($e['date']);
                            @endphp
                            <tr wire:key="entry-{{ $e['key'] }}" @click="open = '{{ $e['key'] }}'" class="cursor-pointer transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]" tabindex="0" @keydown.enter="open = '{{ $e['key'] }}'">
                                {{-- Title --}}
                                <td class="max-w-80 px-3 py-2.5 ps-5">
                                    <div class="flex items-center gap-3">
                                        <span @class(['flex size-8 shrink-0 items-center justify-center rounded-lg', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => $in, 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' => ! $in])>
                                            <flux:icon :icon="$in ? 'arrow-down-left' : 'arrow-up-right'" variant="micro" class="size-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $e['title'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                {{-- Date --}}
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $bs ? $bs[2].' '.NepaliDate::MONTHS[$bs[1] - 1].' '.$bs[0] : '' }}</p>
                                </td>
                                {{-- Category --}}
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium whitespace-nowrap text-zinc-700 dark:bg-white/10 dark:text-zinc-200"><flux:icon :icon="$e['icon']" variant="micro" class="size-3.5" />{{ $e['category'] }}</span>
                                </td>
                                {{-- Payment type --}}
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <span @class(['inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset', 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $in, 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => ! $in])>
                                        <flux:icon :icon="$in ? 'arrow-down-left' : 'arrow-up-right'" variant="micro" class="size-3" />{{ $in ? __('Income') : __('Expense') }}
                                    </span>
                                </td>
                                {{-- Method --}}
                                <td class="px-3 py-2.5 whitespace-nowrap text-zinc-700 dark:text-zinc-300">
                                    @if ($e['method'])
                                        <span class="inline-flex items-center gap-1.5"><flux:icon :icon="$methodIcons[$e['method']] ?? 'credit-card'" variant="micro" class="size-4 text-zinc-400" />{{ __($e['method']) }}</span>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                                {{-- Amount --}}
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
        @endif

        {{-- Transaction detail --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/40 p-4 backdrop-blur-sm" x-transition.opacity @click.self="open = null">
                <template x-if="open && rows[open]">
                    <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-zinc-900 dark:ring-white/10" role="dialog" aria-modal="true">
                        <div class="relative px-6 pt-6 pb-5 text-white" :class="rows[open].kind === 'income' ? 'bg-linear-to-br from-emerald-500 to-emerald-700' : 'bg-linear-to-br from-rose-500 to-rose-700'">
                            <button type="button" @click="open = null" class="absolute top-4 right-4 flex size-8 items-center justify-center rounded-lg text-white/80 hover:bg-white/15 hover:text-white" aria-label="{{ __('Close') }}"><flux:icon.x-mark variant="mini" class="size-5" /></button>
                            <p class="text-xs font-semibold tracking-wider text-white/75 uppercase" x-text="rows[open].kind === 'income' ? @js(__('Money in')) : @js(__('Money out'))"></p>
                            <p class="mt-1 text-3xl font-bold tracking-tight tabular-nums"><span x-text="rows[open].kind === 'income' ? '+ ' : '− '"></span><span x-text="rows[open].amount"></span></p>
                            <p class="mt-1.5 truncate text-sm font-medium text-white/90" x-text="rows[open].title"></p>
                        </div>
                        <dl class="divide-y divide-zinc-100 px-6 text-sm dark:divide-white/5">
                            <div class="flex justify-between gap-4 py-3"><dt class="text-zinc-500">{{ __('Date') }}</dt><dd class="font-medium text-zinc-900 dark:text-white" x-text="rows[open].bs"></dd></div>
                            <div class="flex justify-between gap-4 py-3"><dt class="text-zinc-500">{{ __('Category') }}</dt><dd class="font-medium text-zinc-900 dark:text-white" x-text="rows[open].category"></dd></div>
                            <div class="flex justify-between gap-4 py-3"><dt class="text-zinc-500">{{ __('Payment type') }}</dt><dd class="font-semibold" :class="rows[open].kind === 'income' ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300'" x-text="rows[open].kind === 'income' ? @js(__('Income')) : @js(__('Expense'))"></dd></div>
                            <div class="flex justify-between gap-4 py-3"><dt class="text-zinc-500">{{ __('Source') }}</dt><dd class="font-medium text-zinc-900 dark:text-white" x-text="rows[open].source"></dd></div>
                            <div class="flex justify-between gap-4 py-3"><dt class="text-zinc-500">{{ __('Method') }}</dt><dd class="font-medium text-zinc-900 dark:text-white" x-text="rows[open].method || '—'"></dd></div>
                            <div class="flex justify-between gap-4 py-3" x-show="rows[open].reference"><dt class="text-zinc-500">{{ __('Reference') }}</dt><dd class="font-medium text-zinc-900 tabular-nums dark:text-white" x-text="rows[open].reference"></dd></div>
                            <div class="flex justify-between gap-4 py-3" x-show="rows[open].note"><dt class="text-zinc-500">{{ __('Note') }}</dt><dd class="text-end text-zinc-800 dark:text-zinc-100" x-text="rows[open].note"></dd></div>
                            <div class="flex justify-between gap-4 py-3"><dt class="text-zinc-500">{{ __('Recorded') }}</dt><dd class="text-zinc-600 dark:text-zinc-300" x-text="rows[open].recorded"></dd></div>
                        </dl>
                        <div class="flex flex-wrap items-center gap-2 border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                            <template x-if="rows[open].id">
                                <button type="button" @click="if (confirm(@js(__('Delete this entry?')))) { $wire.deleteEntry(rows[open].id); open = null }" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10"><flux:icon.trash variant="micro" class="size-4" />{{ __('Delete') }}</button>
                            </template>
                            <div class="ms-auto flex gap-2">
                                <template x-if="rows[open].proof">
                                    <a :href="rows[open].proof" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10"><flux:icon.paper-clip variant="micro" class="size-4" />{{ __('View receipt') }}</a>
                                </template>
                                <template x-if="rows[open].link">
                                    <a :href="rows[open].link" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600"><span x-text="rows[open].linkLabel"></span><flux:icon.arrow-right variant="micro" class="size-4" /></a>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </section>

    {{-- Add transaction --}}
    <flux:modal name="entry-form" class="w-full max-w-xl overflow-visible p-0!" :closable="false">
        <form wire:submit="saveEntry" class="flex flex-col">
            <div class="flex items-center gap-4 border-b border-zinc-100 px-6 py-5 dark:border-white/5">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow-md" :class="$wire.kind === 'income' ? 'bg-brand-500 shadow-brand-500/30' : 'bg-amber-500 shadow-amber-500/30'">
                    <flux:icon.arrows-up-down variant="mini" class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg">{{ __('Add transaction') }}</flux:heading>
                    <p class="text-sm text-zinc-500">{{ __('Client payments and salaries are added on their own pages.') }}</p>
                </div>
                <flux:modal.close><flux:button variant="ghost" size="sm" icon="x-mark" :aria-label="__('Close')" /></flux:modal.close>
            </div>

            <div class="flex flex-col gap-5 px-6 py-5">
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                    <button type="button" wire:click="$set('kind', 'income')" class="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition" :class="$wire.kind === 'income' ? 'bg-white text-brand-700 shadow-sm dark:bg-zinc-800 dark:text-brand-300' : 'text-zinc-500'"><flux:icon.arrow-down-left variant="micro" class="size-4" />{{ __('Money in') }}</button>
                    <button type="button" wire:click="$set('kind', 'expense')" class="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition" :class="$wire.kind === 'expense' ? 'bg-white text-amber-700 shadow-sm dark:bg-zinc-800 dark:text-amber-300' : 'text-zinc-500'"><flux:icon.arrow-up-right variant="micro" class="size-4" />{{ __('Money out') }}</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="title" :label="__('What was it for')" :placeholder="$kind === 'income' ? __('e.g. Logo design for a friend') : __('e.g. Office rent for Kartik')" required />
                    <flux:select wire:model="category" :label="__('Category')" wire:key="category-{{ $kind }}" data-test="entry-category">
                        @foreach (Transaction::categories($kind) as $key => $cat)
                            <flux:select.option :value="$key">{{ __($cat['label']) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

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
