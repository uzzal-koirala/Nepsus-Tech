<?php

use App\Models\Client;
use App\Models\Payment;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Clients')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 'grid')]
    public string $view = 'grid';

    #[Url(except: null)]
    public ?int $open = null;

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $name = '';
    public string $company = '';
    public string $email = '';
    public string $phone = '';
    public string $clientStatus = 'active';
    public string $notes = '';
    public string $package = '';
    public string $price = '';
    public string $billing_cycle = 'monthly';
    public int $progress = 0;
    public string $facebook_url = '';
    public string $instagram_url = '';
    public string $tiktok_url = '';
    public string $google_business_url = '';

    /** Step shown in the add/edit form. */
    public string $formTab = 'details';

    private const FORM_TABS = [
        'details' => ['name', 'company', 'phone', 'clientStatus', 'email', 'notes'],
        'package' => ['package', 'price', 'billing_cycle', 'progress', 'facebook_url', 'instagram_url', 'tiktok_url', 'google_business_url'],
    ];

    public function mount(): void
    {
        if ($this->open) {
            $this->edit($this->open);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, Client::STATUSES) ? $status : 'all';
        $this->resetPage();
    }

    #[Computed]
    public function clients()
    {
        return Client::query()
            ->withNextPayment()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('company', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('package', 'like', "%{$this->search}%")))
            ->orderBy('position')
            ->latest('id')
            ->paginate(12);
    }

    #[Computed]
    public function summary(): array
    {
        $active = Client::where('status', 'active');
        [$monthStart, $monthEnd] = NepaliDate::monthRange(today());
        $unpaid = Payment::whereNull('paid_at');

        return [
            'active' => (clone $active)->count(),
            'inactive' => Client::where('status', 'inactive')->count(),
            'monthly' => (float) (clone $active)->where('billing_cycle', 'monthly')->sum('price')
                + (float) (clone $active)->where('billing_cycle', 'yearly')->sum('price') / 12,
            'paying' => (clone $active)->whereIn('billing_cycle', ['monthly', 'yearly'])->whereNotNull('price')->count(),
            'received' => (float) Payment::whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount'),
            'receivedCount' => Payment::whereBetween('paid_at', [$monthStart, $monthEnd])->count(),
            'outstanding' => (float) (clone $unpaid)->sum('amount'),
            'overdue' => (clone $unpaid)->whereDate('due_date', '<', today())->count(),
        ];
    }

    /**
     * Drag-and-drop priority. $position is the new index among the cards on screen,
     * so the client is re-slotted next to its visible neighbour in the full order.
     */
    public function sortClient(int $id, int $position): void
    {
        $visible = $this->clients->pluck('id')->reject(fn ($v) => $v === $id)->values();
        $ordered = Client::orderBy('position')->latest('id')->pluck('id')->reject(fn ($v) => $v === $id)->values();

        $anchor = $visible[$position] ?? null;
        $index = match (true) {
            $anchor !== null => $ordered->search($anchor),
            $visible->isNotEmpty() => $ordered->search($visible->last()) + 1,
            default => 0,
        };

        $ordered->splice($index, 0, [$id]);

        // ponytail: one UPDATE per client; switch to a CASE statement if the client list gets into the thousands
        foreach ($ordered as $i => $clientId) {
            Client::whereKey($clientId)->update(['position' => $i]);
        }

        unset($this->clients);
    }

    #[Computed]
    public function counts(): array
    {
        $counts = Client::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['all' => $counts->sum()] + collect(Client::STATUSES)->mapWithKeys(fn ($s) => [$s => $counts[$s] ?? 0])->all();
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('client-form')->show();
    }

    public function edit(int $id): void
    {
        $client = Client::findOrFail($id);

        $this->resetForm();
        $this->editingId = $client->id;
        $this->clientStatus = $client->status;
        $this->billing_cycle = $client->billing_cycle ?? 'monthly';
        $this->progress = $client->progress;
        $this->price = $client->price === null ? '' : rtrim(rtrim((string) $client->price, '0'), '.');

        foreach (['name', 'company', 'email', 'phone', 'notes', 'package', ...array_values(Client::SOCIALS)] as $field) {
            $this->{$field} = (string) $client->{$field};
        }

        Flux::modal('client-form')->show();
    }

    public function save(): void
    {
        $url = ['nullable', 'url:http,https', 'max:255'];

        $this->withValidator(fn ($validator) => $validator->after(function ($validator) {
            // Jump the form to the step that holds the first error.
            if ($field = array_key_first($validator->errors()->messages())) {
                $this->formTab = collect(self::FORM_TABS)->search(fn ($fields) => in_array($field, $fields)) ?: 'details';
            }
        }));

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'clientStatus' => ['required', Rule::in(Client::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'package' => ['nullable', Rule::in(array_keys(Client::PACKAGES))],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'billing_cycle' => ['required', Rule::in(Client::BILLING_CYCLES)],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'facebook_url' => $url,
            'instagram_url' => $url,
            'tiktok_url' => $url,
            'google_business_url' => $url,
        ]);

        $data['status'] = $data['clientStatus'];
        unset($data['clientStatus']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if (! $this->editingId) {
            $data['position'] = (int) Client::min('position') - 1; // new clients start at the top
        }

        $client = Client::updateOrCreate(['id' => $this->editingId], $data);

        if ($this->open) {
            $this->redirectRoute('clients.show', $client, navigate: true);

            return;
        }

        Flux::modal('client-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Client updated.') : __('Client added.'));
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('client-delete')->show();
    }

    public function delete(): void
    {
        Client::whereKey($this->deletingId)->delete();
        $this->deletingId = null;

        Flux::modal('client-delete')->close();
        Flux::toast(text: __('Client deleted.'));
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'company', 'email', 'phone', 'notes', 'package', 'price', 'facebook_url', 'instagram_url', 'tiktok_url', 'google_business_url');
        $this->clientStatus = 'active';
        $this->billing_cycle = 'monthly';
        $this->progress = 0;
        $this->formTab = 'details';
        $this->resetErrorBag();
    }
}; ?>

@php
    $tabs = ['all' => __('All clients'), 'active' => __('Active'), 'inactive' => __('Inactive')];
    $badges = [
        'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300',
        'inactive' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/15 dark:bg-white/5 dark:text-zinc-400',
    ];
    $dots = ['active' => 'bg-emerald-500', 'inactive' => 'bg-zinc-400'];
    $tiers = [
        'basic' => [
            'chip' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
            'card' => 'from-emerald-50 ring-emerald-200/80 hover:ring-emerald-300 hover:shadow-[0_18px_40px_-24px_rgb(5_150_105/0.45)] dark:from-emerald-500/[0.07] dark:ring-emerald-500/20',
        ],
        'standard' => [
            'chip' => 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-600/20 dark:bg-brand-500/15 dark:text-brand-200',
            'card' => 'from-brand-50 ring-brand-200/80 hover:ring-brand-300 hover:shadow-[0_18px_40px_-24px_rgb(49_81_254/0.45)] dark:from-brand-500/[0.09] dark:ring-brand-500/25',
        ],
        'premium' => [
            'chip' => 'bg-linear-to-r from-amber-300 via-yellow-200 to-amber-300 text-amber-950 shadow-sm shadow-amber-500/30 ring-1 ring-inset ring-amber-500/40',
            'card' => 'from-amber-100/70 ring-amber-300 hover:ring-amber-400 hover:shadow-[0_18px_40px_-22px_rgb(217_119_6/0.55)] dark:from-amber-400/[0.1] dark:ring-amber-400/40',
        ],
        'enterprise' => [
            'chip' => 'bg-linear-to-r from-zinc-900 to-slate-700 text-cyan-100 shadow-sm shadow-zinc-900/30 ring-1 ring-inset ring-white/10 dark:from-white dark:to-slate-200 dark:text-zinc-900',
            'card' => 'from-slate-100 ring-slate-300 hover:ring-slate-400 hover:shadow-[0_18px_40px_-22px_rgb(15_23_42/0.5)] dark:from-white/[0.06] dark:ring-white/20',
        ],
    ];
    $avatars = [
        'bg-brand-100 text-brand-700', 'bg-amber-100 text-amber-800', 'bg-emerald-100 text-emerald-800',
        'bg-rose-100 text-rose-800', 'bg-sky-100 text-sky-800', 'bg-violet-100 text-violet-800',
    ];
    $cycles = ['monthly' => __('/mo'), 'yearly' => __('/yr'), 'one_time' => ' · '.__('one-time')];
    $socials = [
        'facebook' => ['label' => 'Facebook', 'class' => 'hover:bg-[#1877F2]/10 hover:text-[#1877F2]'],
        'instagram' => ['label' => 'Instagram', 'class' => 'hover:bg-[#E4405F]/10 hover:text-[#E4405F]'],
        'tiktok' => ['label' => 'TikTok', 'class' => 'hover:bg-zinc-900/[0.06] hover:text-zinc-900 dark:hover:bg-white/10 dark:hover:text-white'],
        'google' => ['label' => __('Google Business Profile'), 'class' => 'hover:bg-[#4285F4]/10 hover:text-[#4285F4]'],
    ];
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Clients') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count client in your workspace|:count clients in your workspace', $this->counts['all']) }} · {{ __('drag cards to set priority') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="new-client-button">
            {{ __('New client') }}
        </flux:button>
    </header>

    {{-- Summary --}}
    @php
        $sum = $this->summary;
    @endphp
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="relative isolate flex flex-col gap-4 overflow-hidden rounded-xl bg-linear-to-br from-brand-500 to-brand-700 p-5 text-white shadow-[0_18px_40px_-20px_rgb(49_81_254/0.7)]">
            <div class="absolute -top-10 -right-10 -z-10 size-36 rounded-full bg-white/10"></div>
            <div class="absolute -right-4 -bottom-14 -z-10 size-28 rounded-full bg-sun/90"></div>
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-white/15 ring-1 ring-white/20"><flux:icon.user-group variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-brand-100">{{ __('Active clients') }}</p>
            </div>
            <p class="text-[2rem] leading-none font-semibold tracking-tight tabular-nums">{{ $sum['active'] }}</p>
            <p class="mt-auto text-xs text-brand-100">{{ trans_choice(':count inactive client|:count inactive clients', $sum['inactive']) }}</p>
        </div>

        <div class="flex flex-col gap-4 rounded-xl bg-white p-5 ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-400/15 dark:text-amber-300"><flux:icon.arrow-trending-up variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Monthly revenue') }}</p>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ Client::money(round($sum['monthly'])) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ trans_choice('From :count client on a monthly or yearly plan|From :count clients on monthly or yearly plans', $sum['paying']) }}</p>
        </div>

        <div class="flex flex-col gap-4 rounded-xl bg-white p-5 ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Received in :month', ['month' => today()->toNepaliMonth()]) }}</p>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ Client::money($sum['received']) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ trans_choice(':count payment this month|:count payments this month', $sum['receivedCount']) }}</p>
        </div>

        <div class="flex flex-col gap-4 rounded-xl bg-white p-5 ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.clock variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Outstanding') }}</p>
                @if ($sum['overdue'])
                    <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $sum['overdue']) }}</span>
                @endif
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ Client::money($sum['outstanding']) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ __('Unpaid invoices across all clients') }}</p>
        </div>
    </section>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 rounded-2xl bg-white p-2 ring-1 ring-zinc-200/70 lg:flex-row lg:items-center lg:justify-between dark:bg-zinc-950 dark:ring-white/5">
        <nav class="flex gap-1 overflow-x-auto" aria-label="{{ __('Filter by status') }}">
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="setStatus('{{ $key }}')"
                    @class([
                        'flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition',
                        'bg-brand-500 text-white shadow-md shadow-brand-500/25' => $status === $key,
                        'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' => $status !== $key,
                    ])
                    @if ($status === $key) aria-current="true" @endif
                >
                    {{ $label }}
                    <span @class([
                        'rounded-md px-1.5 py-0.5 text-xs tabular-nums',
                        'bg-white/20' => $status === $key,
                        'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $status !== $key,
                    ])>{{ $this->counts[$key] }}</span>
                </button>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search clients')" clearable class="lg:w-72" />

            <div class="flex shrink-0 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                @foreach (['grid' => 'squares-2x2', 'list' => 'list-bullet'] as $mode => $icon)
                    <button
                        type="button"
                        wire:click="$set('view', '{{ $mode }}')"
                        aria-label="{{ $mode === 'grid' ? __('Grid view') : __('List view') }}"
                        @class([
                            'flex size-8 items-center justify-center rounded-lg transition',
                            'bg-white text-brand-600 shadow-sm dark:bg-zinc-800 dark:text-white' => $view === $mode,
                            'text-zinc-400 hover:text-zinc-700 dark:hover:text-white' => $view !== $mode,
                        ])
                    >
                        <flux:icon :icon="$icon" variant="mini" class="size-4" />
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Results --}}
    @if ($this->clients->isEmpty())
        <div class="flex flex-col items-center gap-4 rounded-3xl bg-white px-6 py-16 text-center ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/5">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-300">
                <flux:icon.building-office-2 class="size-7" />
            </span>
            @if ($search !== '' || $status !== 'all')
                <div class="flex flex-col gap-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('No clients match your filters') }}</h2>
                    <p class="text-sm text-zinc-500">{{ __('Try a different search or switch to All clients.') }}</p>
                </div>
                <flux:button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            @else
                <div class="flex flex-col gap-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('No clients yet') }}</h2>
                    <p class="max-w-xs text-sm text-zinc-500">{{ __('Add your first client to keep their package, phone and social profiles in one place.') }}</p>
                </div>
                <flux:button variant="primary" size="sm" icon="plus" wire:click="create">{{ __('Add client') }}</flux:button>
            @endif
        </div>
    @elseif ($view === 'grid')
        <div wire:sort="sortClient" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 min-[1800px]:grid-cols-5">
            @foreach ($this->clients as $client)
                @php
                    $done = $client->progress >= 100;
                    $bar = $done ? 'bg-emerald-500' : ($client->progress < 34 ? 'bg-amber-400' : 'bg-brand-500');
                @endphp
                @php
                    $tier = Client::PACKAGES[$client->package] ?? null;
                @endphp
                <article wire:key="client-{{ $client->id }}" wire:sort:item="{{ $client->id }}" class="{{ $tier ? $tiers[$client->package]['card'] : 'from-zinc-50 ring-zinc-200/80 hover:ring-zinc-300 hover:shadow-[0_18px_40px_-24px_rgb(24_24_27/0.3)] dark:from-white/[0.02] dark:ring-white/10' }} flex flex-col rounded-lg bg-white bg-linear-to-b via-white via-45% to-white ring-1 transition duration-300 hover:-translate-y-0.5 dark:bg-zinc-950 dark:via-zinc-950 dark:to-zinc-950">
                    <div class="flex flex-col gap-4 p-4">
                        <div class="flex items-center gap-3">
                            <button type="button" wire:sort:handle class="-ms-2 -me-1.5 flex h-10 w-5 shrink-0 cursor-grab items-center justify-center rounded text-zinc-300 transition hover:bg-zinc-100 hover:text-zinc-500 active:cursor-grabbing dark:text-zinc-600 dark:hover:bg-white/5" aria-label="{{ __('Drag to change priority') }}" title="{{ __('Drag to change priority') }}">
                                <flux:icon.grip-vertical variant="micro" class="size-4" />
                            </button>
                            <span class="{{ $avatars[$client->id % count($avatars)] }} relative flex size-10 shrink-0 items-center justify-center rounded-md text-sm font-semibold ring-1 ring-black/5">
                                {{ $client->initials() }}
                                <span class="{{ $dots[$client->status] }} absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-zinc-950" title="{{ ucfirst(__($client->status)) }}"></span>
                                <span class="sr-only">{{ __($client->status) }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate leading-snug font-semibold text-zinc-900 dark:text-white"><a href="{{ route('clients.show', $client) }}" wire:navigate class="hover:text-brand-600 dark:hover:text-brand-300">{{ $client->name }}</a></h3>
                                <p class="truncate text-[0.8125rem] text-zinc-500 dark:text-zinc-400">{{ $client->company ?? __('Independent') }}</p>
                            </div>
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" class="-me-2" :aria-label="__('Actions for :name', ['name' => $client->name])" />
                                <flux:menu>
                                    <flux:menu.item icon="user" :href="route('clients.show', $client)" wire:navigate>{{ __('View profile') }}</flux:menu.item>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $client->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $client->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        @if ($alert = $client->paymentAlert())
                            @php
                                $alertStyles = [
                                    'overdue' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30',
                                    'today' => 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/30',
                                    'soon' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
                                ];
                            @endphp
                            <div class="{{ $alertStyles[$alert['tone']] }} flex items-center gap-2 rounded-md px-2.5 py-2 text-xs ring-1 ring-inset" title="{{ $alert['date']->format('j M Y') }}">
                                @if ($alert['tone'] === 'today')
                                    <span class="relative flex size-2 shrink-0"><span class="absolute inline-flex size-full animate-ping rounded-full bg-orange-400 opacity-75"></span><span class="relative inline-flex size-2 rounded-full bg-orange-500"></span></span>
                                @else
                                    <flux:icon :icon="$alert['tone'] === 'overdue' ? 'exclamation-triangle' : 'bell-alert'" variant="micro" class="size-3.5 shrink-0" />
                                @endif
                                <span class="min-w-0 truncate font-medium">
                                    @if ($alert['tone'] === 'overdue')
                                        {{ trans_choice('Overdue by :count day|Overdue by :count days', $alert['days']) }}
                                    @elseif ($alert['tone'] === 'today')
                                        {{ __('Due today') }}
                                    @else
                                        {{ trans_choice('Due tomorrow|Due in :count days', $alert['days']) }}
                                        <span class="font-normal opacity-75">· {{ $alert['date']->toNepali(withYear: false) }}</span>
                                    @endif
                                </span>
                                <span class="ms-auto shrink-0 font-semibold tabular-nums">{{ $alert['amount'] }}</span>
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-3">
                            @if ($tier)
                                <span class="{{ $tiers[$client->package]['chip'] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold">
                                    <flux:icon :icon="$tier['icon']" variant="micro" class="size-3.5" />
                                    {{ __($tier['label']) }}
                                </span>
                            @else
                                <span class="text-xs text-zinc-400">{{ __('No package') }}</span>
                            @endif
                            @if ($client->price !== null)
                                <p class="whitespace-nowrap">
                                    <span class="text-lg font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $client->formattedPrice() }}</span><span class="text-xs text-zinc-400">{{ $cycles[$client->billing_cycle] ?? '' }}</span>
                                </p>
                            @endif
                        </div>

                        @php
                            $done = $client->progress >= 100;
                        @endphp
                        <div class="flex items-center gap-3" title="{{ __('Work progress') }}">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $client->progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Work progress') }}">
                                <div @class(['h-full rounded-full', 'bg-emerald-500' => $done, 'bg-amber-400' => $client->progress < 34, 'bg-brand-500' => ! $done && $client->progress >= 34]) style="width: {{ $client->progress }}%"></div>
                            </div>
                            @if ($done)
                                <flux:icon.check-circle variant="mini" class="size-4 text-emerald-500" />
                            @else
                                <span class="w-8 text-end text-xs font-medium text-zinc-500 tabular-nums">{{ $client->progress }}%</span>
                            @endif
                        </div>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-zinc-100 px-4 py-2.5 dark:border-white/5">
                        @if ($client->phone)
                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $client->phone) }}" class="flex min-w-0 items-center gap-2 text-sm text-zinc-600 tabular-nums hover:text-brand-600 dark:text-zinc-300">
                                <flux:icon.phone variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                                <span class="truncate">{{ $client->phone }}</span>
                            </a>
                        @else
                            <span class="text-sm text-zinc-400">{{ __('No phone') }}</span>
                        @endif

                        <div class="-me-1.5 flex shrink-0 items-center">
                            @foreach ($client->socialLinks() as $network => $link)
                                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="{{ $socials[$network]['class'] }} flex size-7 items-center justify-center rounded-md text-zinc-400 transition duration-200" title="{{ $socials[$network]['label'] }}">
                                    <x-social-icon :name="$network" class="size-3.5" />
                                    <span class="sr-only">{{ $socials[$network]['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl bg-white ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/5">
            <table class="w-full min-w-[60rem] text-start text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 text-xs font-medium text-zinc-500 dark:border-white/5">
                        <th class="px-5 py-3 text-start font-medium">{{ __('Client') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Phone') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Package') }}</th>
                        <th class="px-5 py-3 text-end font-medium">{{ __('Price') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Progress') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Social') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Status') }}</th>
                        <th class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody wire:sort="sortClient" class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($this->clients as $client)
                        <tr wire:key="row-{{ $client->id }}" wire:sort:item="{{ $client->id }}" class="transition hover:bg-brand-50/50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <button type="button" wire:sort:handle class="-ms-2 flex h-8 w-5 shrink-0 cursor-grab items-center justify-center rounded text-zinc-300 hover:text-zinc-500 active:cursor-grabbing dark:text-zinc-600" aria-label="{{ __('Drag to change priority') }}">
                                        <flux:icon.grip-vertical variant="micro" class="size-4" />
                                    </button>
                                    <span class="{{ $avatars[$client->id % count($avatars)] }} flex size-9 shrink-0 items-center justify-center rounded-lg text-xs font-semibold">{{ $client->initials() }}</span>
                                    <div class="min-w-0">
                                        <a href="{{ route('clients.show', $client) }}" wire:navigate class="block truncate font-medium text-zinc-900 hover:text-brand-600 dark:text-white">{{ $client->name }}</a>
                                        <p class="truncate text-xs text-zinc-500">{{ $client->company ?? __('Independent') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-zinc-600 tabular-nums dark:text-zinc-300">{{ $client->phone ?? '-' }}</td>
                            <td class="px-5 py-3">
                                @if ($tier = Client::PACKAGES[$client->package] ?? null)
                                    <span class="{{ $tiers[$client->package]['chip'] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold">
                                        <flux:icon :icon="$tier['icon']" variant="micro" class="size-3.5" />
                                        {{ __($tier['label']) }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-end whitespace-nowrap">
                                @if ($client->price !== null)
                                    <span class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $client->formattedPrice() }}</span>
                                    <span class="text-xs text-zinc-500">{{ $cycles[$client->billing_cycle] ?? '' }}</span>
                                    @if ($alert = $client->paymentAlert())
                                        <span @class([
                                            'mt-0.5 block text-xs font-medium',
                                            'text-rose-600 dark:text-rose-400' => $alert['tone'] === 'overdue',
                                            'text-orange-600 dark:text-orange-400' => $alert['tone'] === 'today',
                                            'text-amber-600 dark:text-amber-400' => $alert['tone'] === 'soon',
                                        ])>
                                            {{ match ($alert['tone']) { 'overdue' => trans_choice('Overdue :count day|Overdue :count days', $alert['days']), 'today' => __('Due today'), default => trans_choice('Due tomorrow|Due in :count days', $alert['days']) } }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex w-28 items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                        <div @class(['h-full rounded-full', 'bg-emerald-500' => $client->progress >= 100, 'bg-amber-400' => $client->progress < 34, 'bg-brand-500' => $client->progress >= 34 && $client->progress < 100]) style="width: {{ $client->progress }}%"></div>
                                    </div>
                                    <span class="w-8 text-end text-xs text-zinc-500 tabular-nums">{{ $client->progress }}%</span>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex gap-1">
                                    @foreach ($client->socialLinks() as $network => $link)
                                        <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="{{ $socials[$network]['class'] }} flex size-7 items-center justify-center rounded-md text-zinc-400 transition duration-200" title="{{ $socials[$network]['label'] }}">
                                            <x-social-icon :name="$network" class="size-3.5" />
                                            <span class="sr-only">{{ $socials[$network]['label'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $badges[$client->status] }} inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium capitalize ring-1 ring-inset">
                                    <span class="{{ $dots[$client->status] }} size-1.5 rounded-full"></span>
                                    {{ __($client->status) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-end">
                                <flux:dropdown align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" :aria-label="__('Actions for :name', ['name' => $client->name])" />
                                    <flux:menu>
                                        <flux:menu.item icon="user" :href="route('clients.show', $client)" wire:navigate>{{ __('View profile') }}</flux:menu.item>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $client->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $client->id }})">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($this->clients->hasPages())
        <flux:pagination :paginator="$this->clients" />
    @endif

    {{-- Create / edit --}}
    @php
        $formTabs = [
            'details' => ['label' => __('Details'), 'fields' => ['name', 'company', 'phone', 'clientStatus', 'email', 'notes']],
            'package' => ['label' => __('Package & social'), 'fields' => ['package', 'price', 'billing_cycle', 'progress', 'facebook_url', 'instagram_url', 'tiktok_url', 'google_business_url']],
        ];
        $seg = 'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition';
        $segOn = 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-white dark:ring-white/10';
        $segOff = 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white';
        $tierPick = [
            'basic' => 'text-emerald-600 ring-emerald-500 bg-emerald-50 dark:bg-emerald-500/10',
            'standard' => 'text-brand-600 ring-brand-500 bg-brand-50 dark:bg-brand-500/10',
            'premium' => 'text-amber-600 ring-amber-400 bg-amber-50 dark:bg-amber-500/10',
            'enterprise' => 'text-slate-700 ring-slate-500 bg-slate-100 dark:bg-white/10 dark:text-slate-200',
        ];
    @endphp
    <flux:modal name="client-form" class="w-full max-w-2xl overflow-hidden p-0!" :closable="false">
        <form
            wire:submit="save"
            x-data="{
                tab: $wire.entangle('formTab'),
                get step() { return this.tab === 'package' ? 2 : 1 },
            }"
            class="flex flex-col"
        >
            {{-- Header with stepper --}}
            <div class="border-b border-zinc-100 bg-linear-to-b from-brand-50/70 to-white px-6 pt-5 pb-4 dark:border-white/5 dark:from-brand-500/10 dark:to-transparent">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">{{ $editingId ? __('Edit client') : __('New client') }}</flux:heading>
                        <p class="mt-0.5 text-sm text-zinc-500">{{ __('Step') }} <span class="font-semibold text-brand-600 dark:text-brand-300" x-text="step"></span> {{ __('of 2') }}</p>
                    </div>
                    <flux:modal.close>
                        <flux:button variant="ghost" size="sm" icon="x-mark" :aria-label="__('Close')" />
                    </flux:modal.close>
                </div>

                <ol class="mt-4 flex items-center gap-3">
                    @foreach ($formTabs as $key => $t)
                        @if (! $loop->first)
                            <li aria-hidden="true" class="h-1 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10">
                                <div class="h-full rounded-full bg-brand-500 transition-[width] duration-500 ease-out" :style="`width: ${step === 2 ? 100 : 0}%`"></div>
                            </li>
                        @endif
                        <li>
                            <button type="button" @click="tab = '{{ $key }}'" class="group flex items-center gap-2.5">
                                <span
                                    class="relative flex size-8 items-center justify-center rounded-full text-sm font-semibold ring-1 transition duration-300"
                                    :class="tab === '{{ $key }}'
                                        ? 'bg-brand-500 text-white ring-brand-500 shadow-md shadow-brand-500/30'
                                        : ({{ $loop->iteration }} < step ? 'bg-brand-50 text-brand-600 ring-brand-200 dark:bg-brand-500/15 dark:ring-brand-500/30' : 'bg-white text-zinc-400 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10')"
                                >
                                    <span x-show="! ({{ $loop->iteration }} < step)">{{ $loop->iteration }}</span>
                                    <flux:icon.check variant="micro" class="size-4" x-show="{{ $loop->iteration }} < step" x-cloak />
                                    @if ($errors->hasAny($t['fields']))
                                        <span class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-rose-500 ring-2 ring-white dark:ring-zinc-900"></span>
                                    @endif
                                </span>
                                <span class="text-sm font-medium transition" :class="tab === '{{ $key }}' ? 'text-zinc-900 dark:text-white' : 'text-zinc-500 group-hover:text-zinc-800 dark:group-hover:text-white'">{{ $t['label'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Body --}}
            <div class="px-6 py-5">
                {{-- Step 1: details --}}
                <div x-show="tab === 'details'" class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="name" :label="__('Client name')" :placeholder="__('e.g. Sita Gurung')" required />
                    <flux:input wire:model="company" :label="__('Company')" :placeholder="__('e.g. Himalayan Cafe')" />
                    <flux:input wire:model="phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" />
                    <flux:input wire:model="email" type="email" :label="__('Email')" :badge="__('Optional')" />
                    <div class="sm:col-span-2">
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Status') }}</span>
                        <div class="mt-2 flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                            @foreach (['active' => ['Active', 'bg-emerald-500'], 'inactive' => ['Inactive', 'bg-zinc-400']] as $value => [$label, $dot])
                                <button type="button" @click="$wire.clientStatus = '{{ $value }}'" class="{{ $seg }} inline-flex items-center justify-center gap-2" :class="$wire.clientStatus === '{{ $value }}' ? @js($segOn) : @js($segOff)">
                                    <span class="{{ $dot }} size-2 rounded-full"></span>{{ __($label) }}
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="clientStatus" />
                    </div>
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" :placeholder="__('Anything the team should know about this client')" />
                    </div>
                </div>

                {{-- Step 2: package & social --}}
                <div x-show="tab === 'package'" x-cloak class="flex flex-col gap-4">
                    <div>
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Package') }}</span>
                        <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                            <button type="button" @click="$wire.package = ''" class="flex flex-col items-center gap-1 rounded-xl px-2 py-2.5 text-xs font-medium ring-1 transition" :class="! $wire.package ? 'bg-zinc-100 text-zinc-800 ring-2 ring-zinc-400 dark:bg-white/10 dark:text-white' : 'text-zinc-500 ring-zinc-200 hover:ring-zinc-300 dark:ring-white/10'">
                                <flux:icon.minus-circle variant="mini" class="size-5" />{{ __('None') }}
                            </button>
                            @foreach (Client::PACKAGES as $key => $p)
                                <button type="button" @click="$wire.package = '{{ $key }}'" class="flex flex-col items-center gap-1 rounded-xl px-2 py-2.5 text-xs font-semibold ring-1 transition" :class="$wire.package === '{{ $key }}' ? @js($tierPick[$key].' ring-2') : 'text-zinc-500 ring-zinc-200 hover:ring-zinc-300 dark:ring-white/10'">
                                    <flux:icon :icon="$p['icon']" variant="mini" class="size-5" />{{ __($p['label']) }}
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="package" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                        <flux:input wire:model="price" type="number" min="0" step="0.01" inputmode="decimal" :label="__('Price (:currency)', ['currency' => config('app.currency')])" placeholder="25000" />
                        <div>
                            <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Billing') }}</span>
                            <div class="mt-2 flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                                @foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly', 'one_time' => 'One-time'] as $value => $label)
                                    <button type="button" @click="$wire.billing_cycle = '{{ $value }}'" class="{{ $seg }} whitespace-nowrap" :class="$wire.billing_cycle === '{{ $value }}' ? @js($segOn) : @js($segOff)">{{ __($label) }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    @if ($editingId)
                        <div class="flex items-center gap-4 rounded-xl bg-zinc-50 px-4 py-3 ring-1 ring-zinc-100 dark:bg-white/[0.03] dark:ring-white/5">
                            <label for="client-progress" class="shrink-0 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Work progress') }}</label>
                            <input id="client-progress" type="range" min="0" max="100" step="5" x-model.number="$wire.progress" class="min-w-0 flex-1 accent-brand-500">
                            <span class="w-10 text-end text-sm font-semibold text-brand-600 tabular-nums dark:text-brand-300"><span x-text="$wire.progress"></span>%</span>
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ([
                            'facebook_url' => ['facebook', 'Facebook', 'https://facebook.com/…', 'text-[#1877F2]'],
                            'instagram_url' => ['instagram', 'Instagram', 'https://instagram.com/…', 'text-[#E4405F]'],
                            'tiktok_url' => ['tiktok', 'TikTok', 'https://tiktok.com/@…', 'text-zinc-900 dark:text-white'],
                            'google_business_url' => ['google', __('Google Business'), 'https://g.page/…', 'text-[#4285F4]'],
                        ] as $field => [$icon, $label, $placeholder, $color])
                            <div>
                                <label class="flex items-center gap-2.5 rounded-lg border border-zinc-200 bg-white px-3 shadow-xs transition focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-500/20 dark:border-white/10 dark:bg-white/5">
                                    <x-social-icon :name="$icon" class="size-4 shrink-0 {{ $color }}" />
                                    <span class="sr-only">{{ $label }}</span>
                                    <input wire:model="{{ $field }}" type="url" placeholder="{{ $label }} · {{ $placeholder }}" class="h-10 min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-zinc-800 placeholder:text-zinc-400 focus:ring-0 focus:outline-none dark:text-white">
                                </label>
                                <flux:error :name="$field" />
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-between gap-2 border-t border-zinc-100 bg-zinc-50/60 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                <div>
                    <flux:button variant="ghost" icon="arrow-left" x-show="step === 2" x-cloak @click="tab = 'details'">{{ __('Back') }}</flux:button>
                </div>
                <div class="flex items-center gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @if ($editingId)
                        <flux:button variant="filled" icon-trailing="arrow-right" x-show="step === 1" @click="tab = 'package'">{{ __('Next') }}</flux:button>
                        <flux:button type="submit" variant="primary" data-test="save-client-button">{{ __('Save changes') }}</flux:button>
                    @else
                        <flux:button variant="primary" icon-trailing="arrow-right" x-show="step === 1" @click="tab = 'package'">{{ __('Next') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon="check" x-show="step === 2" x-cloak data-test="save-client-button">{{ __('Add client') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Delete --}}
    <flux:modal name="client-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Delete this client?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Their details, package and notes will be removed. This cannot be undone.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete client') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
