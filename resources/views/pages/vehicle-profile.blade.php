<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Vehicle')] class extends Component {
    public Vehicle $vehicle;

    /** @var array<string, mixed> Edit vehicle form. */
    public array $form = [];

    // Add expense
    public string $expCategory = 'fuel';
    public string $expTitle = '';
    public string $expAmount = '';
    public string $expDate = '';
    public string $expOdometer = '';
    public string $expLiters = '';
    public string $expMethod = '';
    public string $expNote = '';

    public ?int $deletingExpenseId = null;

    #[Computed]
    public function expenses()
    {
        return $this->vehicle->expenses()->orderByDesc('date')->orderByDesc('id')->get();
    }

    #[Computed]
    public function stats(): array
    {
        [$from, $to] = NepaliDate::monthRange(today());
        $month = $this->expenses->filter(fn ($x) => $x->date->between($from, $to));
        $fuel = $month->where('category', 'fuel');

        return [
            'total' => (float) $this->expenses->sum('amount'),
            'month' => (float) $month->sum('amount'),
            'fuel' => (float) $fuel->sum('amount'),
            'liters' => (float) $fuel->sum('liters'),
            'lastService' => $this->expenses->firstWhere('category', 'servicing'),
            'odometer' => $this->expenses->whereNotNull('odometer')->max('odometer'),
        ];
    }

    /** All-time spend per category, biggest first. */
    #[Computed]
    public function breakdown()
    {
        return $this->expenses->groupBy('category')->map->sum('amount')->sortDesc();
    }

    // Expenses

    public function openExpense(string $category = 'fuel'): void
    {
        $this->expCategory = array_key_exists($category, VehicleExpense::CATEGORIES) ? $category : 'fuel';
        $this->reset('expTitle', 'expAmount', 'expOdometer', 'expLiters', 'expMethod', 'expNote');
        $this->expDate = today()->toDateString();
        $this->resetErrorBag();
        Flux::modal('vehicle-expense')->show();
    }

    public function saveExpense(): void
    {
        $data = $this->validate([
            'expCategory' => ['required', Rule::in(array_keys(VehicleExpense::CATEGORIES))],
            'expTitle' => ['nullable', 'string', 'max:120'],
            'expAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'expDate' => ['required', 'date'],
            'expOdometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'expLiters' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
            'expMethod' => ['nullable', Rule::in(Payment::METHODS)],
            'expNote' => ['nullable', 'string', 'max:255'],
        ], attributes: ['expAmount' => __('amount'), 'expDate' => __('date'), 'expOdometer' => __('odometer'), 'expLiters' => __('litres')]);

        $this->vehicle->expenses()->create([
            'category' => $data['expCategory'],
            'title' => filled($data['expTitle']) ? trim($data['expTitle']) : null,
            'amount' => $data['expAmount'],
            'date' => $data['expDate'],
            'odometer' => $data['expOdometer'] ?: null,
            'liters' => $data['expCategory'] === 'fuel' && $data['expLiters'] ? $data['expLiters'] : null,
            'method' => $data['expMethod'] ?: null,
            'note' => $data['expNote'] ?: null,
        ]);

        unset($this->expenses, $this->stats, $this->breakdown);
        Flux::modal('vehicle-expense')->close();
        Flux::toast(variant: 'success', text: __('Expense added.'));
    }

    public function confirmDeleteExpense(int $id): void
    {
        $this->deletingExpenseId = $id;
        Flux::modal('vehicle-expense-delete')->show();
    }

    public function deleteExpense(int $id): void
    {
        $this->vehicle->expenses()->findOrFail($id)->delete();
        unset($this->expenses, $this->stats, $this->breakdown);
        $this->deletingExpenseId = null;
        Flux::modal('vehicle-expense-delete')->close();
        Flux::toast(text: __('Expense deleted.'));
    }

    // Vehicle

    public function edit(): void
    {
        $this->form = collect(Vehicle::rules())->keys()->mapWithKeys(fn ($k) => [
            $k => $this->vehicle->{$k} instanceof \Carbon\CarbonInterface ? $this->vehicle->{$k}->toDateString() : (string) $this->vehicle->{$k},
        ])->all();
        $this->resetErrorBag();
        Flux::modal('vehicle-form')->show();
    }

    public function save(): void
    {
        $rules = collect(Vehicle::rules())->mapWithKeys(fn ($r, $k) => ["form.$k" => $r])->all();
        $data = $this->validate($rules, attributes: ['form.name' => __('name'), 'form.type' => __('type')])['form'];

        $this->vehicle->update(array_map(fn ($v) => $v === '' ? null : $v, $data));
        Flux::modal('vehicle-form')->close();
        Flux::toast(variant: 'success', text: __('Vehicle updated.'));
    }

    public function delete(): void
    {
        $this->vehicle->delete();
        Flux::toast(text: __('Vehicle removed.'));
        $this->redirectRoute('vehicles.index', navigate: true);
    }
}; ?>

@php
    $v = $vehicle;
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($x) => Client::money(round((float) $x));
    $st = $this->stats;
    $expenses = $this->expenses;
    $cats = VehicleExpense::CATEGORIES;
    $methodIcons = ['Bank' => 'building-library', 'eSewa' => 'device-phone-mobile', 'Khalti' => 'wallet', 'Cash' => 'banknotes', 'Cheque' => 'document-text', 'Other' => 'ellipsis-horizontal-circle'];
    $hth = 'px-3 py-2.5 text-start text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-500 uppercase';
@endphp

<div class="stagger mx-auto flex w-full max-w-7xl flex-col gap-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('vehicles.index')" wire:navigate>{{ __('Vehicles') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $v->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Hero --}}
    <section class="{{ $panel }} overflow-hidden">
        <div class="flex flex-col md:flex-row">
            <div class="{{ $v->typeMeta()['tint'] }} relative flex items-center justify-center bg-linear-to-br px-10 py-8 md:w-80">
                <div class="absolute inset-0 opacity-40 [background-image:radial-gradient(rgb(15_23_42/0.08)_1px,transparent_1px)] [background-size:16px_16px]"></div>
                <div class="absolute bottom-8 h-5 w-40 rounded-[50%] bg-zinc-900/10 blur-md dark:bg-black/40"></div>
                <img src="{{ $v->imageUrl() }}" alt="{{ __($v->typeMeta()['label']) }}" class="relative size-44 drop-shadow-2xl">
            </div>
            <div class="flex flex-1 flex-col justify-between gap-5 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ __($v->typeMeta()['label']) }}</span>
                        <h1 class="mt-3 text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ $v->name }}</h1>
                        <p class="mt-1 text-sm text-zinc-500">{{ $v->subtitle() }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
                        <flux:button variant="primary" icon="plus" wire:click="openExpense" class="shadow-lg shadow-brand-500/25" data-test="add-expense">{{ __('Add expense') }}</flux:button>
                        <flux:dropdown align="end">
                            <flux:button icon="ellipsis-horizontal" :aria-label="__('More actions')" />
                            <flux:menu>
                                <flux:menu.item icon="trash" variant="danger" x-on:click="$flux.modal('vehicle-delete').show()">{{ __('Remove vehicle') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    @if ($v->plate_no)
                        <span class="inline-flex items-center overflow-hidden rounded-lg text-sm font-bold tracking-wider text-white uppercase shadow-md ring-2 ring-red-600/20">
                            <span class="bg-red-800 px-2 py-1.5 text-[0.625rem]">NP</span><span class="bg-red-600 px-3 py-1.5">{{ $v->plate_no }}</span>
                        </span>
                    @endif
                    @if ($v->employee)
                        <a href="{{ route('employees.show', $v->employee) }}" wire:navigate class="inline-flex items-center gap-2 rounded-full bg-zinc-50 py-1 ps-1 pe-3 text-sm ring-1 ring-zinc-200 transition hover:ring-brand-300 dark:bg-white/5 dark:ring-white/10">
                            <x-employee-avatar :employee="$v->employee" class="size-7 text-[0.65rem]" />
                            <span class="text-zinc-500">{{ __('Used by') }}</span><span class="font-medium text-zinc-900 dark:text-white">{{ $v->employee->name }}</span>
                        </a>
                    @endif
                    @if ($st['odometer'])
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-50 px-3 py-1.5 text-sm ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/10"><flux:icon.map variant="micro" class="size-4 text-zinc-400" /><span class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ number_format($st['odometer']) }}</span><span class="text-zinc-500">km</span></span>
                    @endif
                    @foreach ($v->renewalsDue() as $r)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-3 py-1.5 text-sm font-medium text-violet-700 ring-1 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20">
                            <flux:icon.bell-alert variant="micro" class="size-4" />
                            {{ $r['label'] }} · {{ $r['days'] < 0 ? trans_choice(':count day overdue|:count days overdue', -$r['days']) : ($r['days'] === 0 ? __('today') : trans_choice('in :count day|in :count days', $r['days'])) }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Stats --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Spent all time'), 'value' => $money($st['total']), 'image' => 'other', 'sub' => trans_choice(':count expense|:count expenses', $expenses->count())],
            ['label' => __('This month'), 'value' => $money($st['month']), 'image' => 'tax', 'sub' => NepaliDate::monthYear(today())],
            ['label' => __('Fuel this month'), 'value' => $money($st['fuel']), 'image' => 'fuel', 'sub' => $st['liters'] > 0 ? __(':litres litres', ['litres' => rtrim(rtrim(number_format($st['liters'], 2), '0'), '.')]) : __('No fuel yet')],
            ['label' => __('Last servicing'), 'value' => $st['lastService'] ? $st['lastService']->date->toNepali() : '—', 'image' => 'servicing', 'sub' => $st['lastService'] ? ($st['lastService']->odometer ? number_format($st['lastService']->odometer).' km · '.trans_choice(':count day ago|:count days ago', (int) $st['lastService']->date->diffInDays(today())) : trans_choice(':count day ago|:count days ago', (int) $st['lastService']->date->diffInDays(today()))) : __('Not serviced yet')],
        ] as $card)
            <div class="{{ $panel }} flex items-center gap-4 p-5">
                <img src="{{ asset('images/vehicles/'.$card['image'].'.png') }}" alt="" class="size-12 drop-shadow-sm">
                <div class="min-w-0">
                    <p class="text-sm text-zinc-500">{{ $card['label'] }}</p>
                    <p class="truncate text-xl leading-tight font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $card['value'] }}</p>
                    <p class="truncate text-xs text-zinc-500">{{ $card['sub'] }}</p>
                </div>
            </div>
        @endforeach
    </section>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        {{-- Expenses --}}
        <section class="{{ $panel }} min-w-0 overflow-hidden" data-test="vehicle-expenses">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h2 class="text-lg leading-tight font-semibold tracking-tight text-zinc-900 dark:text-white">{{ __('Expenses') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Petrol, servicing, repairs and everything else') }}</p>
                </div>
                <div class="flex gap-1.5">
                    @foreach (['fuel', 'servicing', 'maintenance'] as $quick)
                        <button type="button" wire:click="openExpense('{{ $quick }}')" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-50 py-1 ps-1 pe-2.5 text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 transition hover:bg-white hover:ring-brand-300 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10">
                            <img src="{{ VehicleExpense::imageUrl($quick) }}" alt="" class="size-6">{{ __(str($cats[$quick]['label'])->before(' /')->toString()) }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($expenses->isEmpty())
                <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                    <img src="{{ VehicleExpense::imageUrl('fuel') }}" alt="" class="size-14 opacity-90">
                    <p class="text-sm text-zinc-500">{{ __('No expenses yet. Add the first petrol fill or service.') }}</p>
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openExpense">{{ __('Add expense') }}</flux:button>
                </div>
            @else
                <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                    <table class="w-full min-w-[46rem] text-sm">
                        <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                            <tr>
                                <th class="{{ $hth }} ps-5">{{ __('Title') }}</th>
                                <th class="{{ $hth }}">{{ __('Date') }}</th>
                                <th class="{{ $hth }}">{{ __('Category') }}</th>
                                <th class="{{ $hth }}">{{ __('Method') }}</th>
                                <th class="{{ $hth }}">{{ __('Odometer') }}</th>
                                <th class="{{ str_replace('text-start', 'text-end', $hth) }}">{{ __('Amount') }}</th>
                                <th class="{{ $hth }} w-12 pe-5"><span class="sr-only">{{ __('Delete') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach ($expenses as $x)
                                @php $meta = $x->categoryMeta(); @endphp
                                <tr wire:key="vexp-{{ $x->id }}" class="group transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="max-w-72 px-3 py-2.5 ps-5">
                                        <div class="flex items-center gap-3">
                                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-50 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10"><img src="{{ VehicleExpense::imageUrl($x->category) }}" alt="" class="size-6"></span>
                                            <div class="min-w-0">
                                                <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $x->label() }}</p>
                                                @if ($x->liters || $x->note)
                                                    <p class="truncate text-xs text-zinc-500">{{ collect([$x->liters ? rtrim(rtrim(number_format($x->liters, 2), '0'), '.').' '.__('litres') : null, $x->note])->filter()->join(' · ') }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5 font-medium whitespace-nowrap text-zinc-900 dark:text-white">{{ $x->date->toNepali() }}</td>
                                    <td class="px-3 py-2.5"><span class="{{ $meta['tone'] }} inline-flex rounded-md px-2 py-1 text-xs font-medium whitespace-nowrap">{{ __($meta['label']) }}</span></td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-zinc-700 dark:text-zinc-300">
                                        @if ($x->method)
                                            <span class="inline-flex items-center gap-1.5"><flux:icon :icon="$methodIcons[$x->method] ?? 'credit-card'" variant="micro" class="size-4 text-zinc-400" />{{ __($x->method) }}</span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-zinc-600 tabular-nums dark:text-zinc-300">{{ $x->odometer ? number_format($x->odometer).' km' : '—' }}</td>
                                    <td class="px-3 py-2.5 text-end whitespace-nowrap">
                                        <span class="inline-flex rounded-lg bg-rose-50 px-2.5 py-1 text-sm font-bold text-rose-700 tabular-nums ring-1 ring-rose-200 ring-inset dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">− {{ $money($x->amount) }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 pe-5 text-end">
                                        <button type="button" wire:click="confirmDeleteExpense({{ $x->id }})" class="rounded-md p-1 text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-500 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete expense') }}"><flux:icon.trash variant="micro" class="size-4" /></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Side --}}
        <aside class="flex flex-col gap-6">
            <section class="{{ $panel }} p-5">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Where the money goes') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('All time, by category') }}</p>
                @php $topCat = max(1, (float) $this->breakdown->first()); @endphp
                <div class="mt-4 flex flex-col gap-3.5">
                    @forelse ($this->breakdown as $cat => $amount)
                        <div>
                            <div class="flex items-center gap-2.5">
                                <img src="{{ VehicleExpense::imageUrl($cat) }}" alt="" class="size-7">
                                <span class="flex-1 truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __($cats[$cat]['label'] ?? 'Other') }}</span>
                                <span class="text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money($amount) }}</span>
                            </div>
                            <div class="ms-9.5 mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="{{ $cats[$cat]['bar'] ?? 'bg-zinc-400' }} h-full rounded-full" style="width: {{ $amount / $topCat * 100 }}%"></div></div>
                        </div>
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500">{{ __('Nothing spent yet.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="{{ $panel }} p-5">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Papers') }}</h2>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach ([['tax', __('Bluebook renewal'), $v->bluebook_expiry], ['insurance', __('Insurance'), $v->insurance_expiry]] as [$img, $paperLabel, $paperDate])
                        @php $left = $paperDate ? (int) today()->diffInDays($paperDate, false) : null; @endphp
                        <div class="flex items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2.5 dark:bg-white/[0.03]">
                            <img src="{{ asset('images/vehicles/'.$img.'.png') }}" alt="" class="size-8">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $paperLabel }}</p>
                                <p class="text-xs text-zinc-500">{{ $paperDate ? $paperDate->toNepali() : __('Date not set') }}</p>
                            </div>
                            @if ($left !== null)
                                <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $left < 0, 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' => $left >= 0 && $left <= 30, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $left > 30])>
                                    {{ $left < 0 ? __('Overdue') : trans_choice(':count day|:count days', $left) }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($v->notes)
                    <p class="mt-3 rounded-lg border border-dashed border-zinc-200 px-3 py-2 text-sm text-zinc-600 dark:border-white/10 dark:text-zinc-300">{{ $v->notes }}</p>
                @endif
            </section>
        </aside>
    </div>

    {{-- Add expense --}}
    <flux:modal name="vehicle-expense" class="w-full max-w-2xl">
        <form wire:submit="saveExpense" class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <img src="{{ $v->imageUrl() }}" alt="" class="size-11">
                <div>
                    <flux:heading size="lg">{{ __('Add expense') }}</flux:heading>
                    <flux:text>{{ $v->name }}@if ($v->plate_no) · {{ $v->plate_no }}@endif</flux:text>
                </div>
            </div>

            <div>
                <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ __('What was it for?') }}</p>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5" role="radiogroup">
                    @foreach ($cats as $key => $c)
                        <button type="button" role="radio" wire:click="$set('expCategory', '{{ $key }}')" aria-checked="{{ $expCategory === $key ? 'true' : 'false' }}" data-test="expense-category-{{ $key }}"
                            @class([
                                'group flex flex-col items-center gap-1 rounded-xl px-1 pt-2.5 pb-2 text-center ring-1 transition',
                                'bg-brand-50 ring-2 ring-brand-500 dark:bg-brand-500/10' => $expCategory === $key,
                                'bg-white ring-zinc-200 hover:ring-zinc-300 dark:bg-white/5 dark:ring-white/10' => $expCategory !== $key,
                            ])>
                            <img src="{{ VehicleExpense::imageUrl($key) }}" alt="" class="size-9 transition group-hover:-translate-y-0.5 group-hover:scale-110">
                            <span class="w-full truncate text-[0.6875rem] font-medium text-zinc-700 dark:text-zinc-200">{{ __($c['label']) }}</span>
                        </button>
                    @endforeach
                </div>
                <flux:error name="expCategory" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="expTitle" :label="__('Title')" :placeholder="__('Optional, e.g. Full tank')" />
                <flux:input wire:model="expAmount" type="number" min="0" step="any" :label="__('Amount (:currency)', ['currency' => config('app.currency')])" required />
                <x-nepali-date wire:model="expDate" :label="__('Date')" />
                <flux:input wire:model="expOdometer" type="number" min="0" :label="__('Odometer (km)')" :placeholder="__('Optional')" />
                @if ($expCategory === 'fuel')
                    <flux:input wire:model="expLiters" type="number" min="0" step="0.01" :label="__('Litres')" :placeholder="__('Optional')" />
                @endif
                <flux:select wire:model="expMethod" :label="__('Paid with')">
                    <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                    @foreach (Payment::METHODS as $m)
                        <flux:select.option :value="$m">{{ __($m) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="sm:col-span-2">
                    <flux:input wire:model="expNote" :label="__('Note')" :placeholder="__('Optional, e.g. Changed brake pads')" />
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-expense">{{ __('Save expense') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete expense --}}
    <flux:modal name="vehicle-expense-delete" class="w-full max-w-md p-0!">
        @php $deleting = $deletingExpenseId ? $expenses->firstWhere('id', $deletingExpenseId) : null; @endphp
        <div class="flex flex-col items-center px-6 pt-8 pb-6 text-center">
            <span class="flex size-14 items-center justify-center rounded-full bg-rose-50 text-rose-600 ring-8 ring-rose-50/50 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/5"><flux:icon.trash class="size-6" /></span>
            <flux:heading size="lg" class="mt-5">{{ __('Delete this expense?') }}</flux:heading>
            <flux:text class="mt-1 max-w-xs">{{ __('It will be removed from this vehicle and from Finance. This cannot be undone.') }}</flux:text>
            @if ($deleting)
                <div class="mt-5 flex w-full items-center gap-3 rounded-xl bg-zinc-50 p-4 text-start ring-1 ring-zinc-200/70 dark:bg-white/[0.03] dark:ring-white/10">
                    <img src="{{ VehicleExpense::imageUrl($deleting->category) }}" alt="" class="size-10">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $deleting->label() }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ $deleting->date->toNepali() }}</p>
                    </div>
                    <span class="font-bold text-zinc-900 tabular-nums dark:text-white">{{ $money($deleting->amount) }}</span>
                </div>
            @endif
        </div>
        <div class="grid grid-cols-2 gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
            <flux:modal.close><flux:button variant="ghost" class="w-full">{{ __('Keep it') }}</flux:button></flux:modal.close>
            <flux:button variant="danger" icon="trash" class="w-full" wire:click="deleteExpense({{ (int) $deletingExpenseId }})" :disabled="! $deleting">{{ __('Delete expense') }}</flux:button>
        </div>
    </flux:modal>

    {{-- Edit vehicle --}}
    <flux:modal name="vehicle-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ __('Edit vehicle') }}</flux:heading>
            @include('partials.vehicle-form')
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove vehicle --}}
    <flux:modal name="vehicle-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Remove :name?', ['name' => $v->name]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('Its expenses will be deleted too, and removed from Finance.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
