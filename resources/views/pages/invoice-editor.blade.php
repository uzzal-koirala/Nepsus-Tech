<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoice editor')] class extends Component {
    public ?Invoice $invoice = null;

    public string $type = 'invoice';
    public string $clientId = '';
    public string $billName = '';
    public string $billCompany = '';
    public string $billPhone = '';
    public string $billEmail = '';
    public string $billAddress = '';
    public string $issueDate = '';
    public string $dueDate = '';
    public string $discount = '';
    public string $taxRate = '';
    public string $notes = '';
    public string $terms = '';
    public string $paymentInfo = '';
    public string $template = 'classic';
    public string $accent = 'green';

    /** @var list<array{description: string, details: string, quantity: string, price: string}> */
    public array $items = [];

    public function mount(?Invoice $invoice = null): void
    {
        if ($invoice?->exists) {
            $this->invoice = $invoice;
            $this->type = $invoice->type;
            $this->clientId = (string) $invoice->client_id;
            [$this->billName, $this->billCompany, $this->billPhone, $this->billEmail, $this->billAddress] = [(string) $invoice->bill_name, (string) $invoice->bill_company, (string) $invoice->bill_phone, (string) $invoice->bill_email, (string) $invoice->bill_address];
            $this->issueDate = $invoice->issue_date->toDateString();
            $this->dueDate = (string) $invoice->due_date?->toDateString();
            $this->discount = (float) $invoice->discount ? (string) (float) $invoice->discount : '';
            $this->taxRate = (float) $invoice->tax_rate ? (string) (float) $invoice->tax_rate : '';
            [$this->notes, $this->terms, $this->paymentInfo] = [(string) $invoice->notes, (string) $invoice->terms, (string) $invoice->payment_info];
            [$this->template, $this->accent] = [$invoice->template, $invoice->accent];
            $this->items = $invoice->items->map(fn ($i) => ['description' => $i->description, 'details' => (string) $i->details, 'quantity' => (string) (float) $i->quantity, 'price' => (string) (float) $i->price])->all();

            return;
        }

        // New: start from the last used look, payment details and terms.
        $this->type = request()->query('type') === 'quotation' ? 'quotation' : 'invoice';
        $this->issueDate = today()->toDateString();
        $this->dueDate = today()->addDays($this->type === 'quotation' ? 30 : 15)->toDateString();
        $this->template = (string) Setting::get('invoice_template', 'classic');
        $this->accent = (string) Setting::get('invoice_accent', 'green');
        $this->paymentInfo = (string) Setting::get('invoice_payment_info', '');
        $this->terms = (string) Setting::get($this->type === 'quotation' ? 'quotation_terms' : 'invoice_terms', $this->type === 'quotation' ? 'This quotation is valid until the date above. 50% advance to start the work.' : 'Please pay by the due date. Thank you for working with us.');
        $this->items = [['description' => '', 'details' => '', 'quantity' => '1', 'price' => '']];

        if ($id = (int) request()->query('client')) {
            $this->clientId = (string) $id;
            $this->updatedClientId();
        }
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    /** Picking a client fills in who the document is for. */
    public function updatedClientId(): void
    {
        $this->billName = '';
        if ($client = Client::find((int) $this->clientId)) {
            $this->billName = $client->name;
            $this->billCompany = $client->company ?: $client->name;
            $this->billPhone = (string) $client->phone;
            $this->billEmail = (string) $client->email;
        }
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'details' => '', 'quantity' => '1', 'price' => ''];
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
    }

    public function addPackage(int $id): void
    {
        $p = Package::findOrFail($id);
        $row = ['description' => $p->name, 'details' => (string) $p->description, 'quantity' => '1', 'price' => (string) (float) $p->price];
        // Fill the first empty row instead of adding a new one.
        foreach ($this->items as $i => $item) {
            if (trim($item['description']) === '' && trim((string) $item['price']) === '') {
                $this->items[$i] = $row;

                return;
            }
        }
        $this->items[] = $row;
    }

    /** What the preview draws: the form as it is right now. */
    #[Computed]
    public function paper(): array
    {
        return Invoice::paper([
            'type' => $this->type, 'number' => $this->invoice?->number ?? Invoice::nextNumber($this->type), 'status' => 'draft',
            'issue_date' => $this->issueDate ?: null, 'due_date' => $this->dueDate ?: null,
            'bill_name' => $this->billName, 'bill_company' => $this->billCompany, 'bill_phone' => $this->billPhone, 'bill_email' => $this->billEmail, 'bill_address' => $this->billAddress,
            'items' => array_map(fn ($i) => ['description' => $i['description'], 'details' => $i['details'], 'quantity' => (float) $i['quantity'], 'price' => (float) $i['price']], $this->items),
            'discount' => (float) $this->discount, 'tax_rate' => (float) $this->taxRate,
            'notes' => $this->notes, 'terms' => $this->terms, 'payment_info' => $this->paymentInfo,
            'template' => $this->template, 'accent' => $this->accent,
        ]);
    }

    public function save(): void
    {
        $data = $this->validate([
            'clientId' => ['nullable', 'exists:clients,id'],
            'billName' => ['nullable', 'string', 'max:120'],
            'billCompany' => ['required', 'string', 'max:120'],
            'billPhone' => ['nullable', 'string', 'max:50'],
            'billEmail' => ['nullable', 'email', 'max:120'],
            'billAddress' => ['nullable', 'string', 'max:200'],
            'issueDate' => ['required', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:issueDate'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'taxRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'paymentInfo' => ['nullable', 'string', 'max:1000'],
            'template' => ['required', Rule::in(array_keys(Invoice::TEMPLATES))],
            'accent' => ['required', Rule::in(array_keys(Invoice::ACCENTS))],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.details' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'items.*.description.required' => __('Describe each item.'),
            'items.*.price.required' => __('Enter a price.'),
        ], ['billCompany' => __('business'), 'billEmail' => __('email'), 'dueDate' => $this->type === 'quotation' ? __('valid until date') : __('due date'), 'issueDate' => __('date')]);

        $fields = [
            'client_id' => $data['clientId'] ?: null,
            'bill_name' => trim($data['billName'] ?: $data['billCompany']), 'bill_company' => trim($data['billCompany']), 'bill_phone' => $data['billPhone'] ?: null,
            'bill_email' => $data['billEmail'] ?: null, 'bill_address' => $data['billAddress'] ?: null,
            'issue_date' => $data['issueDate'], 'due_date' => $data['dueDate'] ?: null,
            'discount' => (float) $data['discount'], 'tax_rate' => (float) $data['taxRate'],
            'notes' => $data['notes'] ?: null, 'terms' => $data['terms'] ?: null, 'payment_info' => $data['paymentInfo'] ?: null,
            'template' => $data['template'], 'accent' => $data['accent'],
        ];

        $invoice = DB::transaction(function () use ($fields, $data) {
            $invoice = $this->invoice ?? new Invoice(['type' => $this->type, 'number' => Invoice::nextNumber($this->type), 'status' => 'draft']);
            $invoice->fill($fields)->save();
            $invoice->items()->delete();
            foreach (array_values($data['items']) as $pos => $item) {
                $invoice->items()->create(['description' => trim($item['description']), 'details' => $item['details'] ?: null, 'quantity' => $item['quantity'], 'price' => $item['price'], 'position' => $pos]);
            }

            return $invoice;
        });

        // Remember the look and the payment details for the next document.
        Setting::put([
            'invoice_template' => $data['template'], 'invoice_accent' => $data['accent'],
            'invoice_payment_info' => $data['paymentInfo'] ?? '',
            ($this->type === 'quotation' ? 'quotation_terms' : 'invoice_terms') => $data['terms'] ?? '',
        ]);

        Flux::toast(variant: 'success', text: $this->type === 'quotation' ? __('Quotation saved.') : __('Invoice saved.'));
        $this->redirectRoute('invoices.show', $invoice, navigate: true);
    }
}; ?>

@php
    $clientOptions = collect([['value' => '', 'label' => __('Someone new (not a client yet)'), 'hint' => __('Type the details below'), 'icon' => 'user-plus', 'tile' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300']])
        ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->company ?: $c->name, 'hint' => collect([$c->company ? $c->name : null, $c->phone])->filter()->implode(' · '), 'initials' => mb_strtoupper(mb_substr($c->company ?: $c->name, 0, 2)), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $quote = $type === 'quotation';
    $doc = $this->paper;
    $money = fn ($v) => Client::money($v);
    $sectionTitle = 'flex items-center gap-2 font-semibold text-zinc-900 dark:text-white';
@endphp

<div class="flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:button variant="ghost" icon="arrow-left" :href="$invoice ? route('invoices.show', $invoice) : route('invoices.index', $quote ? ['type' => 'quotation'] : [])" wire:navigate :aria-label="__('Back')" />
            <div>
                <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 dark:text-white">{{ $invoice ? __('Edit :number', ['number' => $invoice->number]) : ($quote ? __('New quotation') : __('New invoice')) }}</h1>
                <p class="text-sm text-zinc-500">{{ __('The preview updates as you type.') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-end">
                <p class="text-xs text-zinc-500">{{ __('Total') }}</p>
                <p class="text-xl font-bold text-zinc-900 tabular-nums dark:text-white">{{ $money($doc['totals']['total']) }}</p>
            </div>
            <flux:button variant="primary" icon="check" wire:click="save" class="shadow-lg shadow-brand-500/25" data-test="save-invoice">{{ $quote ? __('Save quotation') : __('Save invoice') }}</flux:button>
        </div>
    </header>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        {{-- Form --}}
        <div class="flex min-w-0 flex-col gap-5">
            {{-- Look --}}
            <section class="{{ $panel }} p-5">
                <p class="{{ $sectionTitle }}"><flux:icon.swatch variant="mini" class="size-5 text-brand-500" />{{ __('Template') }}</p>
                <div class="mt-4 grid grid-cols-3 gap-3">
                    @foreach (Invoice::TEMPLATES as $key => $tpl)
                        @php $hex = $doc['accent_hex']; @endphp
                        <button type="button" wire:click="$set('template', '{{ $key }}')" @class([
                            'group overflow-hidden rounded-xl text-start ring-1 transition',
                            'ring-2 ring-brand-500 shadow-md' => $template === $key,
                            'ring-zinc-200 hover:ring-zinc-300 dark:ring-white/10' => $template !== $key,
                        ]) data-test="template-{{ $key }}">
                            {{-- Mini drawing of the template --}}
                            <div class="relative h-24 bg-white">
                                @if ($key === 'classic')
                                    <div class="absolute inset-x-0 top-0 h-7" style="background:#232b3a; clip-path: polygon(0 0, 62% 0, 52% 100%, 0 100%)"></div>
                                    <div class="absolute top-0 left-[50%] h-7 w-3" style="background: {{ $hex }}; clip-path: polygon(70% 0, 100% 0, 30% 100%, 0 100%)"></div>
                                    <div class="absolute top-2 right-2 h-2 w-10 rounded-sm" style="background: {{ $hex }}"></div>
                                    <div class="absolute top-11 right-2 left-2 grid grid-cols-[1fr_2fr] gap-0.5"><div class="h-1.5" style="background: {{ $hex }}"></div><div class="h-1.5" style="background:#232b3a"></div></div>
                                @elseif ($key === 'minimal')
                                    <div class="absolute top-3 left-2 size-3 rounded-sm" style="background: {{ $hex }}"></div>
                                    <div class="absolute top-3 right-2 h-2 w-12 rounded-sm bg-zinc-700"></div>
                                    <div class="absolute top-8 right-2 h-4 w-16 bg-zinc-100"><div class="h-full w-1" style="background: {{ $hex }}"></div></div>
                                    <div class="absolute top-14 right-2 left-2 h-1.5" style="background: {{ $hex }}"></div>
                                @else
                                    <div class="absolute inset-x-0 top-0 h-9" style="background:#232b3a"></div>
                                    <div class="absolute top-0 left-0 h-2 w-1/2" style="background: {{ $hex }}"></div>
                                    <div class="absolute top-7 right-0 h-2 w-1/2" style="background: {{ $hex }}"></div>
                                    <div class="absolute top-2.5 right-2 h-2 w-10 rounded-sm bg-white"></div>
                                    <div class="absolute top-14 right-2 left-2 h-1.5" style="background: {{ $hex }}"></div>
                                @endif
                                <div class="absolute right-2 bottom-2 left-2 space-y-1"><div class="h-1 bg-zinc-100"></div><div class="h-1 bg-zinc-100"></div><div class="h-1 w-2/3 bg-zinc-100"></div></div>
                            </div>
                            <div class="flex items-center justify-between gap-2 border-t border-zinc-100 px-3 py-2 dark:border-white/5">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __($tpl['label']) }}</p>
                                    <p class="truncate text-[0.6875rem] text-zinc-500">{{ __($tpl['hint']) }}</p>
                                </div>
                                @if ($template === $key)<flux:icon.check-circle variant="mini" class="size-5 shrink-0 text-brand-600" />@endif
                            </div>
                        </button>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="me-1 text-sm text-zinc-500">{{ __('Colour') }}</span>
                    @foreach (Invoice::ACCENTS as $key => $ac)
                        <button type="button" wire:click="$set('accent', '{{ $key }}')" title="{{ __($ac['label']) }}" @class(['size-8 rounded-full ring-offset-2 transition dark:ring-offset-zinc-950', 'ring-2 ring-zinc-900 dark:ring-white' => $accent === $key, 'hover:scale-110' => $accent !== $key]) style="background: {{ $ac['hex'] }}" aria-label="{{ __($ac['label']) }}"></button>
                    @endforeach
                </div>
            </section>

            {{-- Bill to --}}
            <section class="{{ $panel }} p-5">
                <p class="{{ $sectionTitle }}"><flux:icon.user variant="mini" class="size-5 text-brand-500" />{{ $quote ? __('Quotation for') : __('Bill to') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-rich-select model="clientId" live searchable class="sm:col-span-2" :label="__('Client')" :placeholder="__('Pick a client')" :options="$clientOptions" />
                    <flux:input wire:model.live.debounce.400ms="billCompany" :label="__('Business')" required data-test="bill-company" />
                    <flux:input wire:model.live.debounce.400ms="billAddress" :label="__('Address')" />
                    <flux:input wire:model.live.debounce.400ms="billPhone" :label="__('Phone')" />
                    <flux:input wire:model.live.debounce.400ms="billEmail" type="email" :label="__('Email')" />
                </div>
            </section>

            {{-- Dates --}}
            <section class="{{ $panel }} p-5">
                <p class="{{ $sectionTitle }}"><flux:icon.calendar-days variant="mini" class="size-5 text-brand-500" />{{ __('Dates') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-nepali-date wire:model.live="issueDate" :label="__('Date')" />
                    <x-nepali-date wire:model.live="dueDate" :label="$quote ? __('Valid until') : __('Due date')" />
                    <flux:error name="dueDate" class="sm:col-span-2" />
                </div>
            </section>

            {{-- Items --}}
            <section class="{{ $panel }} p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="{{ $sectionTitle }}"><flux:icon.list-bullet variant="mini" class="size-5 text-brand-500" />{{ __('Items') }}</p>
                    @php $packages = Package::where('is_active', true)->orderBy('position')->get(); @endphp
                    @if ($packages->isNotEmpty())
                        <flux:dropdown align="end">
                            <flux:button size="sm" icon="cube">{{ __('Add a package') }}</flux:button>
                            <flux:menu>
                                @foreach ($packages as $p)
                                    <flux:menu.item icon="cube" wire:click="addPackage({{ $p->id }})">{{ $p->name }} · {{ $money($p->price) }}</flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
                <div class="mt-4 flex flex-col gap-3">
                    @foreach ($items as $i => $item)
                        <div wire:key="item-{{ $i }}" class="rounded-xl bg-zinc-50 p-3 ring-1 ring-zinc-200/70 dark:bg-white/[0.03] dark:ring-white/10">
                            <div class="flex items-start gap-3">
                                <span class="mt-2 flex size-6 shrink-0 items-center justify-center rounded-md bg-white text-xs font-bold text-zinc-500 shadow-xs dark:bg-white/10">{{ $i + 1 }}</span>
                                <div class="grid min-w-0 flex-1 gap-2 sm:grid-cols-[1fr_5.5rem_8rem]">
                                    <flux:input wire:model.live.debounce.400ms="items.{{ $i }}.description" :placeholder="__('Item, e.g. Social media management')" data-test="item-description-{{ $i }}" />
                                    <flux:input wire:model.live.debounce.400ms="items.{{ $i }}.quantity" type="number" min="0" step="any" :placeholder="__('Qty')" />
                                    <flux:input wire:model.live.debounce.400ms="items.{{ $i }}.price" type="number" min="0" step="any" :placeholder="__('Price')" data-test="item-price-{{ $i }}" />
                                    <div class="sm:col-span-3"><flux:input wire:model.live.debounce.400ms="items.{{ $i }}.details" size="sm" :placeholder="__('Details (optional)')" /></div>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <span class="mt-2 text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money((float) $item['quantity'] * (float) $item['price']) }}</span>
                                    @if (count($items) > 1)
                                        <button type="button" wire:click="removeItem({{ $i }})" class="rounded-md p-1 text-zinc-400 hover:bg-rose-50 hover:text-rose-500 dark:hover:bg-rose-500/10" aria-label="{{ __('Remove item') }}"><flux:icon.trash variant="micro" class="size-4" /></button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                    @error('items')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
                    @if ($errors->has('items.*'))<p class="text-sm text-rose-600">{{ __('Every item needs a description, a quantity and a price.') }}</p>@endif
                    <button type="button" wire:click="addItem" class="flex items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-zinc-200 py-3 text-sm font-medium text-zinc-500 transition hover:border-brand-300 hover:text-brand-600 dark:border-white/10" data-test="add-item"><flux:icon.plus variant="micro" class="size-4" />{{ __('Add item') }}</button>
                </div>

                <div class="mt-5 grid gap-4 border-t border-zinc-100 pt-5 sm:grid-cols-2 dark:border-white/5">
                    <flux:input wire:model.live.debounce.400ms="discount" type="number" min="0" step="any" :label="__('Discount (:currency)', ['currency' => config('app.currency')])" placeholder="0" />
                    <flux:input wire:model.live.debounce.400ms="taxRate" type="number" min="0" max="100" step="any" :label="__('Tax / VAT (%)')" placeholder="13" />
                </div>
                <dl class="mt-4 flex flex-col gap-1 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-white/[0.03]">
                    <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Subtotal') }}</dt><dd class="font-medium tabular-nums">{{ $money($doc['totals']['subtotal']) }}</dd></div>
                    @if ($doc['totals']['discount'] > 0)<div class="flex justify-between"><dt class="text-zinc-500">{{ __('Discount') }}</dt><dd class="font-medium tabular-nums">− {{ $money($doc['totals']['discount']) }}</dd></div>@endif
                    @if ($doc['totals']['tax'] > 0)<div class="flex justify-between"><dt class="text-zinc-500">{{ __('Tax') }}</dt><dd class="font-medium tabular-nums">{{ $money($doc['totals']['tax']) }}</dd></div>@endif
                    <div class="mt-1 flex justify-between border-t border-zinc-200 pt-2 text-base dark:border-white/10"><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Total') }}</dt><dd class="font-bold text-zinc-900 tabular-nums dark:text-white">{{ $money($doc['totals']['total']) }}</dd></div>
                </dl>
            </section>

            {{-- Extra --}}
            <section class="{{ $panel }} p-5">
                <p class="{{ $sectionTitle }}"><flux:icon.document-text variant="mini" class="size-5 text-brand-500" />{{ __('Payment, terms and notes') }}</p>
                <div class="mt-4 flex flex-col gap-4">
                    <flux:textarea wire:model.live.debounce.500ms="paymentInfo" rows="3" :label="__('Payment details')" :placeholder="__('e.g. Bank: NIC Asia Bank · Account name: Nepsus Tech · Account no: 0000000000 · eSewa: 98XXXXXXXX')" :description="__('Remembered for your next document.')" />
                    <flux:textarea wire:model.live.debounce.500ms="terms" rows="2" :label="__('Terms & conditions')" />
                    <flux:textarea wire:model.live.debounce.500ms="notes" rows="2" :label="__('Notes')" :placeholder="__('Optional')" />
                </div>
            </section>
        </div>

        {{-- Live preview --}}
        <aside class="min-w-0 xl:sticky xl:top-6">
            <div class="rounded-2xl bg-zinc-200/60 p-4 sm:p-6 dark:bg-white/[0.03]">
                <div class="mb-3 flex items-center justify-between text-xs font-medium text-zinc-500">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 animate-pulse rounded-full bg-emerald-500"></span>{{ __('Live preview') }}</span>
                    <span>{{ __(Invoice::TEMPLATES[$template]['label']) }} · A4</span>
                </div>
                @include('partials.invoice-preview', ['doc' => $doc])
            </div>
        </aside>
    </div>
</div>
