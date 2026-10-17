<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoice')] class extends Component {
    public Invoice $invoice;

    // Mark as paid
    public string $paidOn = '';
    public string $method = 'Bank';

    public function setStatus(string $status): void
    {
        abort_unless(array_key_exists($status, Invoice::STATUSES[$this->invoice->type]) && $status !== 'paid', 422);
        $this->invoice->update(['status' => $status]);
        Flux::toast(variant: 'success', text: __('Marked as :status.', ['status' => __(Invoice::STATUSES[$this->invoice->type][$status])]));
    }

    public function openPaid(): void
    {
        $this->paidOn = today()->toDateString();
        $this->resetErrorBag();
        Flux::modal('invoice-paid')->show();
    }

    /** Marks the invoice paid and records the payment on the client, so it counts in Finance. */
    public function markPaid(): void
    {
        abort_if($this->invoice->isQuote() || $this->invoice->status === 'paid', 422);
        $data = $this->validate(['paidOn' => ['required', 'date'], 'method' => ['required', Rule::in(Payment::METHODS)]], attributes: ['paidOn' => __('paid date')]);

        DB::transaction(function () use ($data) {
            $payment = $this->invoice->client?->payments()->create([
                'amount' => $this->invoice->totals()['total'],
                'type' => 'full',
                'due_date' => $this->invoice->due_date,
                'paid_at' => $data['paidOn'],
                'method' => $data['method'],
                'reference' => $this->invoice->number,
                'note' => __('Invoice :number', ['number' => $this->invoice->number]),
            ]);
            $this->invoice->update(['status' => 'paid', 'payment_id' => $payment?->id]);
        });

        Flux::modal('invoice-paid')->close();
        Flux::toast(variant: 'success', text: $this->invoice->client ? __('Paid. The payment is recorded for the client and in Finance.') : __('Marked as paid.'));
    }

    /** Quotation accepted: make an invoice with the same items. */
    public function convert(): void
    {
        abort_unless($this->invoice->isQuote() && ! $this->invoice->converted_to_id, 422);

        $new = DB::transaction(function () {
            $new = $this->invoice->replicate(['number', 'type', 'status', 'payment_id', 'converted_to_id']);
            $new->fill(['type' => 'invoice', 'number' => Invoice::nextNumber('invoice'), 'status' => 'draft', 'issue_date' => today(), 'due_date' => today()->addDays(15), 'terms' => \App\Models\Setting::get('invoice_terms', $this->invoice->terms)])->save();
            foreach ($this->invoice->items as $item) {
                $new->items()->create($item->only(['description', 'details', 'quantity', 'price', 'position']));
            }
            $this->invoice->update(['status' => 'accepted', 'converted_to_id' => $new->id]);

            return $new;
        });

        Flux::toast(variant: 'success', text: __('Invoice :number created from the quotation.', ['number' => $new->number]));
        $this->redirectRoute('invoices.show', $new, navigate: true);
    }

    public function duplicate(): void
    {
        $copy = DB::transaction(function () {
            $copy = $this->invoice->replicate(['number', 'status', 'payment_id', 'converted_to_id']);
            $copy->fill(['number' => Invoice::nextNumber($this->invoice->type), 'status' => 'draft', 'issue_date' => today(), 'due_date' => today()->addDays($this->invoice->isQuote() ? 30 : 15)])->save();
            foreach ($this->invoice->items as $item) {
                $copy->items()->create($item->only(['description', 'details', 'quantity', 'price', 'position']));
            }

            return $copy;
        });

        $this->redirectRoute('invoices.edit', $copy, navigate: true);
    }

    public function delete(): void
    {
        $type = $this->invoice->type;
        $this->invoice->delete();
        Flux::toast(text: __('Deleted.'));
        $this->redirectRoute('invoices.index', $type === 'quotation' ? ['type' => 'quotation'] : [], navigate: true);
    }
}; ?>

@php
    $inv = $invoice->fresh(['items', 'client', 'payment', 'convertedTo']);
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($v) => Client::money($v);
    $doc = $inv->toPaper();
    $st = $inv->displayStatus();
    $quote = $inv->isQuote();
@endphp

<div class="flex w-full flex-col gap-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('invoices.index', $quote ? ['type' => 'quotation'] : [])" wire:navigate>{{ $quote ? __('Quotations') : __('Invoices') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $inv->number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="grid items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        {{-- Summary and actions --}}
        <aside class="flex flex-col gap-5 xl:sticky xl:top-6">
            <section class="{{ $panel }} overflow-hidden">
                <div class="p-5 text-white" style="background: linear-gradient(135deg, #232b3a 55%, {{ $doc['accent_hex'] }})">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold tracking-wider text-white/70 uppercase">{{ $quote ? __('Quotation') : __('Invoice') }}</span>
                        <span class="{{ Invoice::statusTone($st) }} rounded-md px-2 py-0.5 text-xs font-bold capitalize ring-1 ring-inset">{{ __(ucfirst($st)) }}</span>
                    </div>
                    <p class="mt-2 font-mono text-2xl font-bold">{{ $inv->number }}</p>
                    <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums">{{ $money($doc['totals']['total']) }}</p>
                    <p class="mt-1 text-sm text-white/75">{{ $inv->bill_company ?: $inv->bill_name }}</p>
                </div>
                <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                    <div class="flex justify-between px-5 py-3"><dt class="text-zinc-500">{{ __('Date') }}</dt><dd class="font-medium">{{ $inv->issue_date->toNepali() }}</dd></div>
                    <div class="flex justify-between px-5 py-3"><dt class="text-zinc-500">{{ $quote ? __('Valid until') : __('Due') }}</dt><dd @class(['font-medium', 'text-rose-600' => in_array($st, ['overdue', 'expired'], true)])>{{ $inv->due_date?->toNepali() ?? '—' }}</dd></div>
                    <div class="flex justify-between px-5 py-3"><dt class="text-zinc-500">{{ __('Client') }}</dt><dd class="font-medium">
                        @if ($inv->client)<a href="{{ route('clients.show', $inv->client) }}" wire:navigate class="text-brand-600 hover:underline dark:text-brand-300">{{ $inv->client->displayName() }}</a>@else<span class="text-zinc-400">{{ __('Not a client') }}</span>@endif
                    </dd></div>
                    @if ($inv->payment)
                        <div class="flex justify-between px-5 py-3"><dt class="text-zinc-500">{{ __('Paid') }}</dt><dd class="font-medium text-emerald-600">{{ $inv->payment->paid_at?->toNepali() }} · {{ $inv->payment->method }}</dd></div>
                    @endif
                    @if ($inv->convertedTo)
                        <div class="flex justify-between px-5 py-3"><dt class="text-zinc-500">{{ __('Invoice') }}</dt><dd><a href="{{ route('invoices.show', $inv->convertedTo) }}" wire:navigate class="font-mono font-medium text-brand-600 hover:underline dark:text-brand-300">{{ $inv->convertedTo->number }}</a></dd></div>
                    @endif
                </dl>
            </section>

            <section class="{{ $panel }} flex flex-col gap-2 p-4">
                <flux:button variant="primary" icon="printer" :href="route('invoices.print', $inv)" target="_blank" data-test="print-invoice">{{ __('Print or save as PDF') }}</flux:button>
                @if (! $quote && $inv->status !== 'paid')
                    <flux:button icon="check-badge" wire:click="openPaid" class="text-emerald-700!" data-test="mark-paid">{{ __('Mark as paid') }}</flux:button>
                @endif
                @if ($quote && ! $inv->converted_to_id)
                    <flux:button icon="arrow-right-circle" wire:click="convert" wire:confirm="{{ __('Create an invoice from this quotation?') }}" data-test="convert-quote">{{ __('Convert to invoice') }}</flux:button>
                @endif
                <div class="grid grid-cols-2 gap-2">
                    <flux:button icon="pencil-square" :href="route('invoices.edit', $inv)" wire:navigate>{{ __('Edit') }}</flux:button>
                    <flux:button icon="document-duplicate" wire:click="duplicate">{{ __('Duplicate') }}</flux:button>
                </div>
                <div class="mt-1 border-t border-zinc-100 pt-3 dark:border-white/5">
                    <p class="mb-2 text-xs font-medium text-zinc-500">{{ __('Status') }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (Invoice::STATUSES[$inv->type] as $key => $label)
                            @continue($key === 'paid')
                            <button type="button" wire:click="setStatus('{{ $key }}')" @disabled($inv->status === $key || $inv->status === 'paid') @class(['rounded-full px-3 py-1 text-xs font-semibold ring-1 transition disabled:cursor-default', Invoice::statusTone($key).' ring-inset' => $inv->status === $key, 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 disabled:opacity-40 dark:text-zinc-300 dark:ring-white/10' => $inv->status !== $key])>{{ __($label) }}</button>
                        @endforeach
                    </div>
                </div>
                <button type="button" x-on:click="$flux.modal('invoice-delete').show()" class="mt-1 text-start text-xs font-medium text-rose-600 hover:underline">{{ __('Delete') }}</button>
            </section>
        </aside>

        {{-- Paper --}}
        <div class="min-w-0 rounded-2xl bg-zinc-200/60 p-4 sm:p-8 dark:bg-white/[0.03]">
            <div class="mx-auto max-w-[794px]">
                @include('partials.invoice-preview', ['doc' => $doc])
            </div>
        </div>
    </div>

    {{-- Mark as paid --}}
    <flux:modal name="invoice-paid" class="w-full max-w-md">
        <form wire:submit="markPaid" class="flex flex-col gap-5">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-white"><flux:icon.check-badge variant="mini" class="size-5" /></span>
                <div>
                    <flux:heading size="lg">{{ __('Mark :number as paid', ['number' => $inv->number]) }}</flux:heading>
                    <flux:text class="mt-1">{{ $inv->client ? __(':amount is recorded as a payment from :client and shows in Finance.', ['amount' => $money($doc['totals']['total']), 'client' => $inv->client->displayName()]) : __('This is not linked to a client, so it will not be added to Finance.') }}</flux:text>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-nepali-date wire:model="paidOn" :label="__('Paid date')" />
                <flux:select wire:model="method" :label="__('Received in')">
                    @foreach (Payment::METHODS as $m)<flux:select.option :value="$m">{{ __($m) }}</flux:select.option>@endforeach
                </flux:select>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="confirm-paid">{{ __('Mark as paid') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="invoice-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Delete :number?', ['number' => $inv->number]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('A payment already recorded for the client is kept.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
