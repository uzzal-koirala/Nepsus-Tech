<?php

use App\Models\Client;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Support\NepaliDate;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Vehicles')] class extends Component {
    /** @var array<string, mixed> */
    public array $form = [];

    #[Computed]
    public function vehicles()
    {
        [$from, $to] = NepaliDate::monthRange(today());

        return Vehicle::with('employee')
            ->withSum('expenses as total_spent', 'amount')
            ->withSum(['expenses as month_spent' => fn ($q) => $q->whereBetween('date', [$from->toDateString(), $to->toDateString()])], 'amount')
            ->withMax('expenses as last_expense_on', 'date')
            ->orderBy('name')->get();
    }

    #[Computed]
    public function summary(): array
    {
        [$from, $to] = NepaliDate::monthRange(today());
        $month = VehicleExpense::whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        return [
            'count' => $this->vehicles->count(),
            'month' => (float) (clone $month)->sum('amount'),
            'fuel' => (float) (clone $month)->where('category', 'fuel')->sum('amount'),
            'renewals' => $this->vehicles->sum(fn ($v) => count($v->renewalsDue())),
        ];
    }

    public function create(): void
    {
        $this->form = array_fill_keys(array_keys(Vehicle::rules()), '');
        $this->form['type'] = 'scooter';
        $this->resetErrorBag();
        Flux::modal('vehicle-form')->show();
    }

    public function save(): void
    {
        $rules = collect(Vehicle::rules())->mapWithKeys(fn ($r, $k) => ["form.$k" => $r])->all();
        $data = $this->validate($rules, attributes: ['form.name' => __('name'), 'form.type' => __('type')])['form'];

        $vehicle = Vehicle::create(array_map(fn ($v) => $v === '' ? null : $v, $data));

        Flux::modal('vehicle-form')->close();
        Flux::toast(variant: 'success', text: __('Vehicle added.'));
        $this->redirectRoute('vehicles.show', $vehicle, navigate: true);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($v) => Client::money(round((float) $v));
    $s = $this->summary;
@endphp

<div class="stagger mx-auto flex w-full max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Vehicles') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Scooters, bikes and cars, and what they cost to run.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="add-vehicle">{{ __('Add vehicle') }}</flux:button>
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Vehicles'), 'value' => $s['count'], 'image' => 'scooter', 'sub' => __('In the workspace'), 'glow' => 'bg-rose-100/70 dark:bg-rose-500/10'],
            ['label' => __('Spent this month'), 'value' => $money($s['month']), 'image' => 'other', 'sub' => NepaliDate::monthYear(today()), 'glow' => 'bg-amber-100/70 dark:bg-amber-500/10'],
            ['label' => __('Fuel this month'), 'value' => $money($s['fuel']), 'image' => 'fuel', 'sub' => $s['month'] > 0 ? __(':percent% of vehicle costs', ['percent' => (int) round($s['fuel'] / $s['month'] * 100)]) : __('No fuel yet'), 'glow' => 'bg-red-100/70 dark:bg-red-500/10'],
            ['label' => __('Renewals due'), 'value' => $s['renewals'], 'image' => 'tax', 'sub' => __('Bluebook or insurance in 30 days'), 'glow' => 'bg-violet-100/70 dark:bg-violet-500/10'],
        ] as $card)
            <div class="{{ $panel }} relative flex items-center gap-4 overflow-hidden p-5">
                <div class="{{ $card['glow'] }} absolute -top-10 -right-10 size-28 rounded-full"></div>
                <img src="{{ asset('images/vehicles/'.$card['image'].'.png') }}" alt="" class="relative size-12 drop-shadow-sm">
                <div class="relative min-w-0">
                    <p class="text-sm text-zinc-500">{{ $card['label'] }}</p>
                    <p @class(['text-2xl leading-tight font-bold tracking-tight tabular-nums', 'text-violet-700 dark:text-violet-300' => $loop->last && $s['renewals'], 'text-zinc-900 dark:text-white' => ! ($loop->last && $s['renewals'])])>{{ $card['value'] }}</p>
                    <p class="truncate text-xs text-zinc-500">{{ $card['sub'] }}</p>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Vehicles --}}
    @if ($this->vehicles->isEmpty())
        <section class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
            <div class="flex -space-x-4">
                @foreach (['scooter', 'bike', 'car'] as $img)
                    <img src="{{ asset('images/vehicles/'.$img.'.png') }}" alt="" class="size-16 drop-shadow-md" style="transform: rotate({{ ($loop->index - 1) * 8 }}deg)">
                @endforeach
            </div>
            <div>
                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('No vehicles yet') }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Add your first scooter, bike or car to track petrol, servicing and more.') }}</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add vehicle') }}</flux:button>
        </section>
    @else
        <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->vehicles as $v)
                @php $due = $v->renewalsDue(); @endphp
                <a href="{{ route('vehicles.show', $v) }}" wire:navigate wire:key="vehicle-{{ $v->id }}" class="{{ $panel }} group flex flex-col overflow-hidden transition duration-300 hover:-translate-y-1 hover:shadow-[0_24px_50px_-28px_rgb(15_23_42/0.45)]">
                    {{-- Picture --}}
                    <div class="{{ $v->typeMeta()['tint'] }} relative flex h-40 items-center justify-center bg-linear-to-br">
                        <div class="absolute inset-0 opacity-40 [background-image:radial-gradient(rgb(15_23_42/0.08)_1px,transparent_1px)] [background-size:16px_16px]"></div>
                        <div class="absolute bottom-6 h-4 w-32 rounded-[50%] bg-zinc-900/10 blur-md dark:bg-black/40"></div>
                        <img src="{{ $v->imageUrl() }}" alt="{{ __($v->typeMeta()['label']) }}" class="relative size-32 drop-shadow-xl transition duration-500 group-hover:-translate-y-1.5 group-hover:-rotate-3 group-hover:scale-105">
                        <span class="absolute top-3 left-3 rounded-full bg-white/80 px-2.5 py-1 text-xs font-semibold text-zinc-700 shadow-xs backdrop-blur dark:bg-zinc-900/70 dark:text-zinc-200">{{ __($v->typeMeta()['label']) }}</span>
                        @if ($due)
                            <span class="absolute top-3 right-3 inline-flex items-center gap-1 rounded-full bg-violet-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm"><flux:icon.bell-alert variant="micro" class="size-3.5" />{{ $due[0]['days'] < 0 ? __('Renewal overdue') : __('Renewal due') }}</span>
                        @endif
                    </div>

                    {{-- Details --}}
                    <div class="flex flex-1 flex-col gap-4 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-semibold text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $v->name }}</h2>
                                <p class="truncate text-sm text-zinc-500">{{ $v->subtitle() }}</p>
                            </div>
                            @if ($v->plate_no)
                                <span class="shrink-0 rounded-md bg-red-600 px-2 py-1 text-xs font-bold tracking-wide text-white uppercase shadow-sm ring-2 ring-red-600/20">{{ $v->plate_no }}</span>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-zinc-100 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/5">
                            <div class="bg-white px-3 py-2.5 dark:bg-zinc-950">
                                <p class="text-[0.6875rem] text-zinc-500">{{ __('This month') }}</p>
                                <p class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money($v->month_spent ?? 0) }}</p>
                            </div>
                            <div class="bg-white px-3 py-2.5 dark:bg-zinc-950">
                                <p class="text-[0.6875rem] text-zinc-500">{{ __('All time') }}</p>
                                <p class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money($v->total_spent ?? 0) }}</p>
                            </div>
                        </div>

                        <div class="mt-auto flex items-center justify-between gap-3 text-xs text-zinc-500">
                            @if ($v->employee)
                                <span class="flex min-w-0 items-center gap-2"><x-employee-avatar :employee="$v->employee" class="size-6 text-[0.6rem]" /><span class="truncate">{{ $v->employee->name }}</span></span>
                            @else
                                <span>{{ __('Shared') }}</span>
                            @endif
                            <span class="shrink-0">{{ $v->last_expense_on ? __('Last cost :date', ['date' => NepaliDate::format(\Illuminate\Support\Carbon::parse($v->last_expense_on), false)]) : __('No costs yet') }}</span>
                        </div>
                    </div>
                </a>
            @endforeach

            <button type="button" wire:click="create" class="flex min-h-72 flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-zinc-200 text-zinc-500 transition hover:border-brand-300 hover:bg-brand-50/40 hover:text-brand-600 dark:border-white/10 dark:hover:bg-brand-500/5">
                <span class="flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-white/5"><flux:icon.plus class="size-6" /></span>
                <span class="text-sm font-medium">{{ __('Add vehicle') }}</span>
            </button>
        </section>
    @endif

    {{-- Add vehicle --}}
    <flux:modal name="vehicle-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Add vehicle') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Pick the type, then fill in what you know.') }}</flux:text>
            </div>
            @include('partials.vehicle-form')
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-vehicle">{{ __('Add vehicle') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
