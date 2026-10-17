<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Support\NepaliDate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Invoices & Quotations')] class extends Component {
    #[Url(except: 'invoice')]
    public string $type = 'invoice';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->type = $this->type === 'quotation' ? 'quotation' : 'invoice';
    }

    public function setType(string $type): void
    {
        $this->type = $type === 'quotation' ? 'quotation' : 'invoice';
        $this->status = '';
    }

    #[Computed]
    public function documents()
    {
        return Invoice::with(['items', 'client'])->where('type', $this->type)->latest('issue_date')->latest('id')->get();
    }

    #[Computed]
    public function visible()
    {
        $q = mb_strtolower(trim($this->search));

        return $this->documents
            ->when($this->status !== '', fn ($c) => $c->filter(fn ($d) => $d->displayStatus() === $this->status))
            ->when($q !== '', fn ($c) => $c->filter(fn ($d) => str_contains(mb_strtolower($d->number.' '.$d->bill_name.' '.$d->bill_company), $q)))
            ->values();
    }

    #[Computed]
    public function stats(): array
    {
        [$monthStart] = NepaliDate::monthRange(today());
        $docs = $this->documents->map(fn ($d) => ['status' => $d->displayStatus(), 'total' => $d->totals()['total'], 'issue' => $d->issue_date]);

        return [
            'month' => $docs->filter(fn ($d) => $d['issue']->gte($monthStart))->sum('total'),
            'monthCount' => $docs->filter(fn ($d) => $d['issue']->gte($monthStart))->count(),
            'unpaid' => $docs->whereIn('status', ['draft', 'sent', 'overdue'])->sum('total'),
            'unpaidCount' => $docs->whereIn('status', ['draft', 'sent', 'overdue'])->count(),
            'overdue' => $docs->where('status', 'overdue')->count(),
            'paid' => $docs->where('status', 'paid')->sum('total'),
            'open' => $docs->whereIn('status', ['draft', 'sent'])->sum('total'),
            'openCount' => $docs->whereIn('status', ['draft', 'sent'])->count(),
            'accepted' => $docs->where('status', 'accepted')->count(),
            'declined' => $docs->whereIn('status', ['declined', 'expired'])->count(),
        ];
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($v) => Client::money(round((float) $v));
    $s = $this->stats;
    $quote = $type === 'quotation';
    $hth = 'px-3 py-2.5 text-start text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-500 uppercase';
    $statusOptions = $quote ? ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'] : ['draft' => 'Draft', 'sent' => 'Sent', 'overdue' => 'Overdue', 'paid' => 'Paid'];
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Invoices & Quotations') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Send professional quotes and invoices, and see what is paid.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button icon="document-text" :href="route('invoices.create', ['type' => 'quotation'])" wire:navigate data-test="new-quotation">{{ __('New quotation') }}</flux:button>
            <flux:button variant="primary" icon="plus" :href="route('invoices.create')" wire:navigate class="shadow-lg shadow-brand-500/25" data-test="new-invoice">{{ __('New invoice') }}</flux:button>
        </div>
    </header>

    <nav class="flex w-fit gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
        @foreach (['invoice' => ['receipt-percent', __('Invoices')], 'quotation' => ['document-text', __('Quotations')]] as $key => [$icon, $label])
            <button type="button" wire:click="setType('{{ $key }}')" @class([
                'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition',
                'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $type === $key,
                'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => $type !== $key,
            ])><flux:icon :icon="$icon" variant="micro" class="size-4" />{{ $label }}</button>
        @endforeach
    </nav>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = $quote ? [
                [__('Quoted this month'), $money($s['month']), trans_choice(':count quotation|:count quotations', $s['monthCount']), 'document-text', 'bg-brand-500'],
                [__('Waiting for answer'), $money($s['open']), trans_choice(':count open quote|:count open quotes', $s['openCount']), 'clock', 'bg-amber-500'],
                [__('Accepted'), $s['accepted'], __('Turned into work'), 'hand-thumb-up', 'bg-emerald-500'],
                [__('Declined or expired'), $s['declined'], __('Follow up or improve the offer'), 'hand-thumb-down', 'bg-rose-500'],
            ] : [
                [__('Invoiced this month'), $money($s['month']), trans_choice(':count invoice|:count invoices', $s['monthCount']), 'receipt-percent', 'bg-brand-500'],
                [__('Waiting for payment'), $money($s['unpaid']), trans_choice(':count unpaid|:count unpaid', $s['unpaidCount']), 'clock', 'bg-amber-500'],
                [__('Overdue'), $s['overdue'], __('Past the due date'), 'exclamation-triangle', 'bg-rose-500'],
                [__('Paid'), $money($s['paid']), __('All time'), 'check-badge', 'bg-emerald-500'],
            ];
        @endphp
        @foreach ($cards as [$label, $value, $sub, $icon, $tile])
            <div class="{{ $panel }} flex flex-col gap-4 p-5">
                <div class="flex items-center gap-3">
                    <span class="{{ $tile }} flex size-9 items-center justify-center rounded-lg text-white shadow-md"><flux:icon :icon="$icon" variant="mini" class="size-4.5" /></span>
                    <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $label }}</p>
                </div>
                <p class="text-[1.75rem] leading-none font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $value }}</p>
                <p class="text-xs text-zinc-500">{{ $sub }}</p>
            </div>
        @endforeach
    </section>

    <section class="{{ $panel }} overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 px-5 py-4">
            <div class="min-w-48 flex-1">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" size="sm" :placeholder="__('Search number or client')" />
            </div>
            <flux:select wire:model.live="status" size="sm" class="w-44">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach ($statusOptions as $key => $label)<flux:select.option :value="$key">{{ __($label) }}</flux:select.option>@endforeach
            </flux:select>
        </div>

        @if ($this->visible->isEmpty())
            <div class="flex flex-col items-center gap-4 border-t border-zinc-100 px-6 py-14 text-center dark:border-white/5">
                <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon :icon="$quote ? 'document-text' : 'receipt-percent'" class="size-8" /></span>
                <div>
                    <p class="font-semibold text-zinc-900 dark:text-white">{{ $this->documents->isEmpty() ? ($quote ? __('No quotations yet') : __('No invoices yet')) : __('Nothing matches these filters') }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('Pick a template, add items and send a clean PDF in a minute.') }}</p>
                </div>
                @if ($this->documents->isEmpty())
                    <flux:button variant="primary" icon="plus" :href="route('invoices.create', $quote ? ['type' => 'quotation'] : [])" wire:navigate>{{ $quote ? __('New quotation') : __('New invoice') }}</flux:button>
                @endif
            </div>
        @else
            <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                        <tr>
                            <th class="{{ $hth }} ps-5">{{ __('Number') }}</th>
                            <th class="{{ $hth }}">{{ $quote ? __('For') : __('Bill to') }}</th>
                            <th class="{{ $hth }}">{{ __('Date') }}</th>
                            <th class="{{ $hth }}">{{ $quote ? __('Valid until') : __('Due') }}</th>
                            <th class="{{ $hth }}">{{ __('Status') }}</th>
                            <th class="{{ str_replace('text-start', 'text-end', $hth) }} pe-5">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($this->visible as $d)
                            @php $st = $d->displayStatus(); @endphp
                            <tr wire:key="doc-{{ $d->id }}" class="cursor-pointer transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]" onclick="Livewire.navigate('{{ route('invoices.show', $d) }}')">
                                <td class="px-3 py-3 ps-5">
                                    <span class="flex items-center gap-3">
                                        <span class="flex size-9 items-center justify-center rounded-lg text-white" style="background: {{ Invoice::ACCENTS[$d->accent]['hex'] ?? '#3151fe' }}"><flux:icon :icon="$quote ? 'document-text' : 'receipt-percent'" variant="micro" class="size-4" /></span>
                                        <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $d->number }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $d->bill_company ?: $d->bill_name }}</p>
                                    @if ($d->bill_company)<p class="text-xs text-zinc-500">{{ $d->bill_name }}</p>@endif
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $d->issue_date->toNepali() }}</td>
                                <td @class(['px-3 py-3 whitespace-nowrap', 'font-medium text-rose-600 dark:text-rose-400' => in_array($st, ['overdue', 'expired'], true), 'text-zinc-700 dark:text-zinc-300' => ! in_array($st, ['overdue', 'expired'], true)])>{{ $d->due_date?->toNepali() ?? '—' }}</td>
                                <td class="px-3 py-3"><span class="{{ Invoice::statusTone($st) }} inline-flex rounded-md px-2 py-1 text-xs font-semibold capitalize ring-1 ring-inset">{{ __(ucfirst($st)) }}</span></td>
                                <td class="px-3 py-3 pe-5 text-end text-base font-bold whitespace-nowrap text-zinc-900 tabular-nums dark:text-white">{{ $money($d->totals()['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
