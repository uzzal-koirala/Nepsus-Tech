<?php

use App\Models\Client;
use App\Models\Package;
use App\Models\Payment;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

new #[Title('Clients')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 'grid')]
    public string $view = 'grid';

    #[Url(except: '')]
    public string $service = '';

    #[Url(except: null)]
    public ?int $open = null;

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $name = '';
    public string $company = '';
    public string $email = '';
    public string $phone = '';
    public string $clientStatus = 'active';
    public $photo = null;
    public ?string $currentPhoto = null;
    public bool $removePhoto = false;
    public string $package = '';
    public string $price = '';
    public string $billingMonths = '1';
    public string $serviceType = 'social_media';
    public string $videoCount = '1';
    public int $progress = 0;
    public string $agreementStart = '';
    public string $agreementMonths = '';
    public string $agreementEnd = '';

    /** Step shown in the add/edit form. */
    public string $formTab = 'details';

    private const FORM_TABS = [
        'details' => ['photo', 'name', 'company', 'phone', 'clientStatus', 'email'],
        'package' => ['serviceType', 'package', 'price', 'billingMonths', 'videoCount', 'agreementStart', 'agreementMonths', 'agreementEnd', 'progress'],
    ];

    public function updatedPhoto(): void
    {
        $this->removePhoto = false;

        try {
            $this->validateOnly('photo', ['photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('photo'); // never try to preview a rejected file
            throw $e;
        }
    }

    public function clearPhoto(): void
    {
        $this->reset('photo');
        $this->removePhoto = true;
    }

    public function mount(): void
    {
        if ($this->open) {
            $this->edit($this->open);
        }
    }

    public function updatedService(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'service');
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
            ->with('package')
            ->withNextPayment()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->service !== '', fn ($q) => $q->where('service_type', $this->service))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('company', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhereHas('package', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))))
            ->orderBy('position')
            ->latest('id')
            ->paginate(12);
    }

    /** Packages offered in the form (plus the client's current one if it was switched off). */
    #[Computed]
    public function packageOptions()
    {
        return Package::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', (int) $this->package))
            ->orderBy('position')
            ->get();
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
            'monthly' => $recurring = (clone $active)->whereNotNull('billing_months')->whereNotNull('price')->get(['price', 'billing_months'])
                ->sum(fn ($c) => (float) $c->price / max(1, $c->billing_months)),
            'paying' => (clone $active)->whereNotNull('billing_months')->whereNotNull('price')->count(),
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

    /** New client: ask what kind of client first. */
    public function create(): void
    {
        $this->resetForm();
        Flux::modal('client-type')->show();
    }

    /** From the type picker: open the form set up for that kind of client. */
    public function chooseType(string $type): void
    {
        abort_unless(array_key_exists($type, Client::SERVICE_TYPES), 422);

        $this->serviceType = $type;

        if ($type !== 'social_media') {
            $this->package = '';
        }

        $this->formTab = $this->editingId ? 'package' : 'details';
        Flux::modal('client-type')->close();
        Flux::modal('client-form')->show();
    }

    /** "Change type" inside the form. */
    public function changeType(): void
    {
        Flux::modal('client-form')->close();
        Flux::modal('client-type')->show();
    }

    public function edit(int $id): void
    {
        $client = Client::findOrFail($id);

        $this->resetForm();
        $this->editingId = $client->id;
        $this->clientStatus = $client->status;
        $this->billingMonths = (string) ($client->billing_months ?? 1);
        $this->serviceType = $client->service_type ?? 'social_media';
        $this->videoCount = (string) ($client->video_count ?? 1);
        $this->progress = $client->progress;
        $this->price = $client->price === null ? '' : rtrim(rtrim((string) $client->price, '0'), '.');
        $this->package = (string) $client->package_id;

        $this->currentPhoto = $client->avatarUrl();
        $this->agreementStart = (string) $client->contract_start?->toDateString();
        $this->agreementMonths = (string) ($client->agreementMonths() ?? '');
        $this->agreementEnd = (string) $client->contract_end?->toDateString();

        foreach (['name', 'company', 'email', 'phone'] as $field) {
            $this->{$field} = (string) $client->{$field};
        }

        Flux::modal('client-form')->show();
    }

    public function save(): void
    {
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
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'package' => [Rule::requiredIf(fn () => $this->serviceType === 'social_media' && Package::where('is_active', true)->exists()), 'nullable', Rule::exists('packages', 'id')],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'serviceType' => ['required', Rule::in(array_keys(Client::SERVICE_TYPES))],
            'billingMonths' => ['exclude_unless:serviceType,social_media', 'required', 'integer', 'min:1', 'max:120'],
            'videoCount' => ['exclude_unless:serviceType,video', 'required', 'integer', 'min:1', 'max:1000'],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'agreementStart' => ['nullable', 'date'],
            // Social media packages run a number of months; other work picks an end date.
            'agreementMonths' => ['exclude_unless:serviceType,social_media', 'nullable', 'integer', 'min:1', 'max:120'],
            'agreementEnd' => ['exclude_if:serviceType,social_media', 'nullable', 'date', 'after_or_equal:agreementStart'],
        ]);

        $data['status'] = $data['clientStatus'];
        $data['package_id'] = $data['package'] ?: null;
        $data['service_type'] = $this->serviceType;
        $data['billing_months'] = $this->serviceType === 'social_media' ? (int) $this->billingMonths : null;
        $data['video_count'] = $this->serviceType === 'video' ? (int) $this->videoCount : null;
        unset($data['clientStatus'], $data['package'], $data['photo'], $data['serviceType'], $data['billingMonths'], $data['videoCount'], $data['agreementStart'], $data['agreementMonths'], $data['agreementEnd']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $data['contract_start'] = $this->agreementStart ?: null;
        $data['contract_end'] = match (true) {
            ! $this->agreementStart => null,
            $this->serviceType === 'social_media' => $this->agreementMonths
                ? NepaliDate::periodEndAfter(Date::parse($this->agreementStart), (int) $this->agreementMonths)
                : null,
            default => $this->agreementEnd ?: null,
        };

        if (! $this->editingId) {
            $data['position'] = (int) Client::min('position') - 1; // new clients start at the top
        }

        $client = Client::updateOrCreate(['id' => $this->editingId], $data);

        if ($this->photo || $this->removePhoto) {
            if ($client->avatar_path) {
                Storage::disk('public')->delete($client->avatar_path);
            }

            $client->update(['avatar_path' => $this->photo ? $this->photo->store('clients/avatars', 'public') : null]);
        }

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
        $this->reset('editingId', 'name', 'company', 'email', 'phone', 'photo', 'currentPhoto', 'removePhoto', 'package', 'price', 'billingMonths', 'serviceType', 'videoCount', 'agreementStart', 'agreementMonths', 'agreementEnd');
        $this->clientStatus = 'active';
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
            <flux:select wire:model.live="service" class="w-40 shrink-0" :aria-label="__('Service')">
                <flux:select.option value="">{{ __('All services') }}</flux:select.option>
                @foreach (Client::SERVICE_TYPES as $key => $st)
                    <flux:select.option :value="$key">{{ __($st['label']) }}</flux:select.option>
                @endforeach
            </flux:select>
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
                    $pkg = $client->package;
                @endphp
                <article wire:key="client-{{ $client->id }}" wire:sort:item="{{ $client->id }}" class="{{ $pkg ? $pkg->theme()['card'] : 'from-zinc-50 ring-zinc-200/80 hover:ring-zinc-300 hover:shadow-[0_18px_40px_-24px_rgb(24_24_27/0.3)] dark:from-white/[0.02] dark:ring-white/10' }} flex flex-col rounded-lg bg-white bg-linear-to-b via-white via-45% to-white ring-1 transition duration-300 hover:-translate-y-0.5 dark:bg-zinc-950 dark:via-zinc-950 dark:to-zinc-950">
                    <div class="flex flex-col gap-4 p-4">
                        <div class="flex items-center gap-3">
                            <button type="button" wire:sort:handle class="-ms-2 -me-1.5 flex h-10 w-5 shrink-0 cursor-grab items-center justify-center rounded text-zinc-300 transition hover:bg-zinc-100 hover:text-zinc-500 active:cursor-grabbing dark:text-zinc-600 dark:hover:bg-white/5" aria-label="{{ __('Drag to change priority') }}" title="{{ __('Drag to change priority') }}">
                                <flux:icon.grip-vertical variant="micro" class="size-4" />
                            </button>
                            <span class="relative shrink-0">
                                <x-client-avatar :client="$client" :tint="$avatars[$client->id % count($avatars)]" class="size-10 rounded-md text-sm ring-1 ring-black/5" />
                                <span class="{{ $dots[$client->status] }} absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-zinc-950" title="{{ ucfirst(__($client->status)) }}"></span>
                                <span class="sr-only">{{ __($client->status) }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate leading-snug font-semibold text-zinc-900 dark:text-white"><a href="{{ route('clients.show', $client) }}" wire:navigate class="hover:text-brand-600 dark:hover:text-brand-300">{{ $client->displayName() }}</a></h3>
                                <p class="flex items-center gap-1 truncate text-[0.8125rem] text-zinc-500 dark:text-zinc-400"><flux:icon.user variant="micro" class="size-3 shrink-0 opacity-60" /><span class="truncate">{{ $client->contactName() }}</span></p>
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
                            @if ($pkg)
                                <span class="{{ $pkg->theme()['chip'] }} inline-flex max-w-[60%] items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold">
                                    <flux:icon :icon="$pkg->icon" variant="micro" class="size-3.5 shrink-0" />
                                    <span class="truncate">{{ $pkg->name }}</span>
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-700 dark:bg-white/10 dark:text-zinc-200">
                                    <flux:icon :icon="Client::SERVICE_TYPES[$client->service_type]['icon'] ?? 'cube'" variant="micro" class="size-3.5" />
                                    {{ __(Client::SERVICE_TYPES[$client->service_type]['label'] ?? 'Client') }}
                                </span>
                            @endif
                            @if ($client->price !== null)
                                <p class="whitespace-nowrap">
                                    <span class="text-lg font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $client->formattedPrice() }}</span><span class="text-xs text-zinc-400">{{ $client->billingSuffix() }}@if ($client->service_type === 'video') ×{{ max(1, (int) $client->video_count) }}@endif</span>
                                </p>
                            @endif
                        </div>

                        @if ($periodEnd = $client->periodEnd())
                            @php
                                $daysLeft = (int) today()->diffInDays($periodEnd);
                            @endphp
                            <div @class([
                                '-mt-1.5 flex items-center justify-between gap-2 text-xs',
                                'text-amber-700 dark:text-amber-400' => $daysLeft <= 3,
                                'text-zinc-500 dark:text-zinc-400' => $daysLeft > 3,
                            ]) title="{{ $periodEnd->format('j M Y') }}">
                                <span class="inline-flex min-w-0 items-center gap-1.5">
                                    <flux:icon.calendar-days variant="micro" class="size-3.5 shrink-0 opacity-70" />
                                    <span class="truncate">{{ match ($client->billing_months) { 1 => __('Month ends'), 12 => __('Year ends'), default => __('Period ends') } }} <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $periodEnd->toNepali(withYear: false) }}</span></span>
                                </span>
                                <span class="shrink-0 font-medium tabular-nums">{{ $daysLeft === 0 ? __('ends today') : trans_choice(':count day left|:count days left', $daysLeft) }}</span>
                            </div>
                        @endif

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
                                    <x-client-avatar :client="$client" :tint="$avatars[$client->id % count($avatars)]" class="size-9 rounded-lg text-xs" />
                                    <div class="min-w-0">
                                        <a href="{{ route('clients.show', $client) }}" wire:navigate class="block truncate font-medium text-zinc-900 hover:text-brand-600 dark:text-white">{{ $client->displayName() }}</a>
                                        <p class="truncate text-xs text-zinc-500">{{ $client->contactName() }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-zinc-600 tabular-nums dark:text-zinc-300">{{ $client->phone ?? '-' }}</td>
                            <td class="px-5 py-3">
                                @if ($pkg = $client->package)
                                    <span class="{{ $pkg->theme()['chip'] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold">
                                        <flux:icon :icon="$pkg->icon" variant="micro" class="size-3.5" />
                                        {{ $pkg->name }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-700 dark:bg-white/10 dark:text-zinc-200">
                                        <flux:icon :icon="Client::SERVICE_TYPES[$client->service_type]['icon'] ?? 'cube'" variant="micro" class="size-3.5" />
                                        {{ __(Client::SERVICE_TYPES[$client->service_type]['label'] ?? 'Client') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-end whitespace-nowrap">
                                @if ($client->price !== null)
                                    <span class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $client->formattedPrice() }}</span>
                                    <span class="text-xs text-zinc-500">{{ $client->billingSuffix() }}@if ($client->service_type === 'video') ×{{ max(1, (int) $client->video_count) }}@endif</span>
                                    @if ($periodEnd = $client->periodEnd())
                                        <span class="mt-0.5 block text-xs text-zinc-400" title="{{ $periodEnd->format('j M Y') }}">{{ __('ends') }} {{ $periodEnd->toNepali(withYear: false) }}</span>
                                    @endif
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
            'details' => ['label' => __('Details'), 'hint' => __('Contact details'), 'fields' => ['photo', 'name', 'company', 'phone', 'clientStatus', 'email']],
            'package' => ['label' => __('Service & payment'), 'hint' => __('What they buy and how they pay'), 'fields' => ['serviceType', 'package', 'price', 'billingMonths', 'videoCount', 'agreementStart', 'agreementMonths', 'agreementEnd', 'progress']],
        ];
        $seg = 'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition';
        $segOn = 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-white dark:ring-white/10';
        $segOff = 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white';
        $optionCard = 'relative flex items-start gap-3 rounded-xl p-3.5 text-start ring-1 transition duration-200';
        $optionOn = 'bg-brand-50/60 ring-2 ring-brand-500 dark:bg-brand-500/10';
        $optionOff = 'bg-white ring-zinc-200 hover:ring-zinc-300 dark:bg-white/[0.03] dark:ring-white/10 dark:hover:ring-white/20';
    @endphp
    <flux:modal name="client-form" class="w-full max-w-2xl rounded-2xl! p-0!" :closable="false">
        <form
            wire:submit="save"
            x-data="{
                tab: $wire.entangle('formTab'),
                get step() { return this.tab === 'package' ? 2 : 1 },
                hints: @js(collect($formTabs)->map(fn ($t) => $t['hint'])),
            }"
            class="flex flex-col"
        >
            {{-- Header --}}
            <div class="relative isolate overflow-hidden rounded-t-2xl bg-linear-to-br from-brand-500 to-brand-700 px-6 pt-6 pb-5 text-white">
                <div class="absolute -top-14 -right-10 -z-10 size-40 rounded-full bg-sun/90"></div>
                <div class="absolute inset-0 -z-10 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:16px_16px] [mask-image:linear-gradient(to_right,black,transparent_70%)]"></div>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3.5">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25 backdrop-blur">
                            <flux:icon :icon="$editingId ? 'pencil-square' : 'user-plus'" variant="mini" class="size-5" />
                        </span>
                        <div>
                            <h2 class="text-lg leading-tight font-semibold">{{ $editingId ? __('Edit client') : __('New client') }}</h2>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-brand-100">
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-white/15 px-2 py-0.5 text-xs font-semibold text-white ring-1 ring-white/20">
                                    <flux:icon :icon="Client::SERVICE_TYPES[$serviceType]['icon'] ?? 'cube'" variant="micro" class="size-3.5" />
                                    {{ __(Client::SERVICE_TYPES[$serviceType]['label'] ?? 'Client') }}
                                    <button type="button" wire:click="changeType" class="ms-1 underline decoration-white/40 underline-offset-2 hover:decoration-white">{{ __('Change') }}</button>
                                </span>
                                <span>{{ __('Step') }} <span x-text="step"></span> {{ __('of 2') }} · <span x-text="hints[tab]"></span></span>
                            </p>
                        </div>
                    </div>
                    <flux:modal.close>
                        <button type="button" class="flex size-8 items-center justify-center rounded-lg bg-brand-900/25 text-white ring-1 ring-white/25 backdrop-blur transition hover:bg-brand-900/40" aria-label="{{ __('Close') }}">
                            <flux:icon.x-mark variant="mini" class="size-5" />
                        </button>
                    </flux:modal.close>
                </div>

                <ol class="mt-5 grid grid-cols-2 gap-3">
                    @foreach ($formTabs as $key => $t)
                        <li>
                            <button type="button" @click="tab = '{{ $key }}'" class="group w-full text-start">
                                <span class="block h-1.5 overflow-hidden rounded-full bg-white/25">
                                    <span class="block h-full rounded-full bg-white transition-[width] duration-500 ease-out" :style="`width: ${step >= {{ $loop->iteration }} ? 100 : 0}%`"></span>
                                </span>
                                <span class="mt-2 flex items-center gap-1.5 text-xs font-medium transition" :class="step >= {{ $loop->iteration }} ? 'text-white' : 'text-white/60 group-hover:text-white/90'">
                                    <span class="tabular-nums">{{ $loop->iteration }}.</span> {{ $t['label'] }}
                                    @if ($errors->hasAny($t['fields']))
                                        <span class="size-2 rounded-full bg-rose-400 ring-2 ring-white/40" title="{{ __('Needs attention') }}"></span>
                                    @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Body --}}
            <div class="px-6 py-6">
                {{-- Step 1: details --}}
                <div x-show="tab === 'details'" class="grid gap-4 sm:grid-cols-2">
                    {{-- Profile picture --}}
                    <div
                        x-data="{
                            drag: false,
                            uploading: false,
                            progress: 0,
                            upload(file) {
                                if (! file) return;
                                this.uploading = true;
                                $wire.upload('photo', file, () => { this.uploading = false; this.progress = 0 }, () => { this.uploading = false }, (e) => { this.progress = e.detail.progress });
                            },
                            get initials() {
                                const parts = ($wire.name || '').trim().split(/\s+/).filter(Boolean);
                                return parts.length ? (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() : '';
                            },
                        }"
                        x-on:livewire-upload-start="uploading = true"
                        x-on:livewire-upload-progress="progress = $event.detail.progress"
                        x-on:livewire-upload-finish="uploading = false; progress = 0"
                        x-on:livewire-upload-error="uploading = false"
                        @dragover.prevent="drag = true"
                        @dragleave.prevent="drag = false"
                        @drop.prevent="drag = false; upload($event.dataTransfer.files[0])"
                        class="flex items-center gap-5 rounded-2xl border border-dashed p-4 transition duration-200 sm:col-span-2"
                        :class="drag ? 'border-brand-400 bg-brand-50/70 dark:bg-brand-500/10' : 'border-zinc-200 bg-zinc-50/60 dark:border-white/10 dark:bg-white/[0.02]'"
                    >
                        {{-- Avatar with gradient ring --}}
                        <div class="relative shrink-0">
                            <div class="rounded-[1.375rem] bg-linear-to-br from-brand-400 via-brand-500 to-sun p-[3px] shadow-[0_10px_24px_-12px_rgb(49_81_254/0.6)]">
                                <div class="flex size-20 items-center justify-center overflow-hidden rounded-[1.2rem] bg-white ring-2 ring-white dark:bg-zinc-900 dark:ring-zinc-900">
                                    @if ($photo && method_exists($photo, 'temporaryUrl'))
                                        <img src="{{ $photo->temporaryUrl() }}" alt="" class="size-full object-cover">
                                    @elseif ($currentPhoto && ! $removePhoto)
                                        <img src="{{ $currentPhoto }}" alt="" class="size-full object-cover">
                                    @else
                                        <span class="text-2xl font-semibold text-brand-600 dark:text-brand-300" x-show="initials" x-text="initials"></span>
                                        <flux:icon.user class="size-9 text-zinc-300 dark:text-zinc-600" x-show="! initials" />
                                    @endif
                                </div>
                            </div>

                            {{-- Upload progress ring --}}
                            <svg x-show="uploading" x-cloak class="absolute inset-0 size-full" viewBox="0 0 100 100" aria-hidden="true">
                                <rect x="3" y="3" width="94" height="94" rx="24" fill="none" stroke="currentColor" stroke-width="6" class="text-white/70" />
                                <rect x="3" y="3" width="94" height="94" rx="24" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" pathLength="100" class="text-brand-500 transition-[stroke-dashoffset] duration-200" stroke-dasharray="100" :stroke-dashoffset="100 - progress" />
                            </svg>

                            <label class="absolute -right-1.5 -bottom-1.5 flex size-8 cursor-pointer items-center justify-center rounded-xl bg-brand-500 text-white shadow-md ring-[3px] ring-white transition hover:scale-110 hover:bg-brand-600 dark:ring-zinc-900" title="{{ __('Upload photo') }}">
                                <flux:icon.camera variant="micro" class="size-4" />
                                <input type="file" wire:model="photo" accept="image/png,image/jpeg,image/webp" class="sr-only" aria-label="{{ __('Upload profile picture') }}">
                            </label>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Profile picture') }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500" x-show="! uploading">{{ __('Drag a logo or photo here, or choose one.') }}</p>
                            <p class="mt-0.5 text-xs font-medium text-brand-600 tabular-nums dark:text-brand-300" x-show="uploading" x-cloak>{{ __('Uploading') }} <span x-text="progress"></span>%</p>

                            <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-xs ring-1 ring-zinc-200 transition hover:bg-zinc-50 hover:ring-zinc-300 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10">
                                    <flux:icon.arrow-up-tray variant="micro" class="size-3.5" />
                                    {{ ($photo || ($currentPhoto && ! $removePhoto)) ? __('Change photo') : __('Upload photo') }}
                                    <input type="file" wire:model="photo" accept="image/png,image/jpeg,image/webp" class="sr-only">
                                </label>
                                @if ($photo || ($currentPhoto && ! $removePhoto))
                                    <button type="button" wire:click="clearPhoto" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-500 transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10">
                                        <flux:icon.trash variant="micro" class="size-3.5" />
                                        {{ __('Remove') }}
                                    </button>
                                @endif
                                <span class="text-[0.6875rem] text-zinc-400">{{ __('JPG, PNG or WebP · max 2 MB') }}</span>
                            </div>
                            <flux:error name="photo" />
                        </div>
                    </div>

                    <flux:input wire:model="name" icon="user" :label="__('Client name')" :placeholder="__('e.g. Sita Gurung')" required class="sm:col-span-2" />
                    <flux:input wire:model="company" icon="building-office-2" :label="__('Company')" :placeholder="__('e.g. Himalayan Cafe')" />
                    <flux:input wire:model="phone" icon="phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" />
                    <flux:input wire:model="email" icon="envelope" type="email" :label="__('Email')" :badge="__('Optional')" :placeholder="__('name@company.com')" class="sm:col-span-2" />

                    <div class="sm:col-span-2">
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Status') }}</span>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ([
                                'active' => ['Active', 'Currently working with you', 'check-circle', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300'],
                                'inactive' => ['Inactive', 'Paused or finished', 'pause-circle', 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400'],
                            ] as $value => [$label, $hint, $icon, $tint])
                                <button type="button" @click="$wire.clientStatus = '{{ $value }}'" class="{{ $optionCard }}" :class="$wire.clientStatus === '{{ $value }}' ? @js($optionOn) : @js($optionOff)">
                                    <span class="{{ $tint }} flex size-9 shrink-0 items-center justify-center rounded-lg"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ __($label) }}</span>
                                        <span class="block text-xs text-zinc-500">{{ __($hint) }}</span>
                                    </span>
                                    <span class="absolute top-2.5 right-2.5 flex size-5 items-center justify-center rounded-full bg-brand-500 text-white" x-show="$wire.clientStatus === '{{ $value }}'" x-cloak>
                                        <flux:icon.check variant="micro" class="size-3.5" />
                                    </span>
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="clientStatus" />
                    </div>

                </div>

                {{-- Step 2: package & agreement --}}
                @php
                    $stepDot = 'flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-600 ring-1 ring-brand-100 dark:bg-brand-500/15 dark:text-brand-300 dark:ring-brand-500/20';
                    $stepLine = 'mt-1.5 w-px flex-1 bg-linear-to-b from-zinc-200 to-transparent dark:from-white/10';
                    $stepTitle = 'text-sm font-semibold text-zinc-900 dark:text-white';
                    $stepHint = 'text-xs text-zinc-500';
                @endphp
                <div
                    x-show="tab === 'package'"
                    x-cloak
                    class="flex flex-col"
                    x-data="{
                        get agreement() {
                            const C = window.NepaliCalendar;
                            const start = $wire.agreementStart;
                            if (! C || ! start) return null;
                            const label = (m) => m === 12 ? '1 year' : m % 12 === 0 ? (m / 12) + ' years' : m + (m === 1 ? ' month' : ' months');

                            if ($wire.serviceType === 'social_media') {
                                const months = parseInt($wire.agreementMonths);
                                if (! (months > 0)) return null;
                                return { from: C.formatBs(start), to: C.formatBs(C.periodEndAfter(start, months)), length: label(months) };
                            }

                            const end = $wire.agreementEnd;
                            if (! end || end < start) return null;
                            let months = null;
                            for (let n = 1; n <= 120; n++) {
                                const e = C.periodEndAfter(start, n);
                                if (e === end) { months = n; break; }
                                if (! e || e > end) break;
                            }
                            const days = Math.round((Date.parse(end) - Date.parse(start)) / 86400000) + 1;
                            return { from: C.formatBs(start), to: C.formatBs(end), length: months ? label(months) : days + ' days' };
                        },
                    }"
                >
                    @php
                        $n = 0;
                    @endphp
                    @if ($serviceType === 'social_media')
                        {{-- Package --}}
                        <section class="flex gap-4">
                            <div class="flex flex-col items-center">
                                <span class="{{ $stepDot }}">{{ ++$n }}</span>
                                <span class="{{ $stepLine }}"></span>
                            </div>
                            <div class="min-w-0 flex-1 pb-6">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 class="{{ $stepTitle }}">{{ __('Package') }}</h3>
                                        <p class="{{ $stepHint }}">{{ __('The social media package they are on') }}</p>
                                    </div>
                                    <a href="{{ route('packages.index') }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('Manage packages') }}</a>
                                </div>
                                @php
                                    $cycleShort = ['monthly' => '/mo', 'yearly' => '/yr', 'one_time' => ' one-time'];
                                @endphp
                                <div class="relative mt-2.5" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false">
                                    <button
                                        type="button"
                                        @click="open = ! open"
                                        class="flex h-12 w-full items-center gap-3 rounded-xl border bg-white px-3 text-start shadow-xs transition dark:bg-white/5"
                                        :class="open ? 'border-brand-400 ring-2 ring-brand-500/20' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10'"
                                        :aria-expanded="open"
                                        aria-haspopup="listbox"
                                    >
                                        <span class="flex items-center gap-2 text-sm text-zinc-400" x-show="! $wire.package">
                                            <span class="flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-white/5"><flux:icon.cube variant="mini" class="size-4" /></span>
                                            {{ __('Choose a package') }}
                                        </span>
                                        @foreach ($this->packageOptions as $p)
                                            <span class="flex min-w-0 flex-1 items-center gap-3" x-show="$wire.package == '{{ $p->id }}'" x-cloak>
                                                <span class="{{ $p->theme()['icon'] }} flex size-8 shrink-0 items-center justify-center rounded-lg"><flux:icon :icon="$p->icon" variant="mini" class="size-4" /></span>
                                                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $p->name }}</span>
                                                <span class="shrink-0 text-xs text-zinc-500 tabular-nums">{{ $p->formattedPrice() ? $p->formattedPrice().($cycleShort[$p->billing_cycle] ?? '') : __('Custom price') }}</span>
                                            </span>
                                        @endforeach
                                        <flux:icon.chevron-down variant="micro" class="ms-auto size-4 shrink-0 text-zinc-400 transition" ::class="open && 'rotate-180'" />
                                    </button>

                                    <div
                                        x-show="open"
                                        x-transition.origin.top
                                        x-cloak
                                        role="listbox"
                                        class="absolute inset-x-0 top-full z-30 mt-1.5 max-h-64 overflow-y-auto rounded-xl bg-white p-1.5 shadow-[0_20px_45px_-20px_rgb(15_23_42/0.4)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10"
                                    >
                                        @forelse ($this->packageOptions as $p)
                                            <button
                                                type="button"
                                                role="option"
                                                wire:key="opt-{{ $p->id }}"
                                                @click="$wire.package = '{{ $p->id }}'; @if ($p->price !== null) $wire.price = '{{ rtrim(rtrim((string) $p->price, '0'), '.') }}'; $wire.billingMonths = '{{ \App\Models\Client::monthsForCycle($p->billing_cycle) ?? 1 }}'; @endif open = false"
                                                class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-start transition"
                                                :class="$wire.package == '{{ $p->id }}' ? 'bg-brand-50 dark:bg-brand-500/10' : 'hover:bg-zinc-50 dark:hover:bg-white/5'"
                                                :aria-selected="$wire.package == '{{ $p->id }}'"
                                            >
                                                <span class="{{ $p->theme()['icon'] }} flex size-8 shrink-0 items-center justify-center rounded-lg"><flux:icon :icon="$p->icon" variant="mini" class="size-4" /></span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $p->name }}</span>
                                                    @if ($p->description)
                                                        <span class="block truncate text-xs text-zinc-400">{{ $p->description }}</span>
                                                    @endif
                                                </span>
                                                <span class="shrink-0 text-xs font-medium text-zinc-600 tabular-nums dark:text-zinc-300">{{ $p->formattedPrice() ? $p->formattedPrice().($cycleShort[$p->billing_cycle] ?? '') : __('Custom price') }}</span>
                                                <flux:icon.check variant="micro" class="size-4 shrink-0 text-brand-600 dark:text-brand-300" x-show="$wire.package == '{{ $p->id }}'" />
                                                <span class="size-4 shrink-0" x-show="$wire.package != '{{ $p->id }}'"></span>
                                            </button>
                                        @empty
                                            <p class="px-3 py-4 text-center text-sm text-zinc-500">
                                                {{ __('No packages yet.') }}
                                                <a href="{{ route('packages.index') }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('Create one') }}</a>
                                            </p>
                                        @endforelse
                                    </div>
                                </div>
                                <flux:error name="package" />
                            </div>
                        </section>
                    @endif

                    {{-- 2. Price & payment --}}
                    <section class="flex gap-4">
                        <div class="flex flex-col items-center">
                            <span class="{{ $stepDot }}">{{ ++$n }}</span>
                            <span class="{{ $stepLine }}"></span>
                        </div>
                        <div class="min-w-0 flex-1 pb-6">
                            <h3 class="{{ $stepTitle }}">
                                <span x-show="$wire.serviceType === 'social_media'">{{ __('Price & payment') }}</span>
                                <span x-show="$wire.serviceType === 'website' || $wire.serviceType === 'software'" x-cloak>{{ __('Project price') }}</span>
                                <span x-show="$wire.serviceType === 'video'" x-cloak>{{ __('Video pricing') }}</span>
                            </h3>
                            <p class="{{ $stepHint }}">
                                <span x-show="$wire.serviceType === 'social_media'">{{ __('Amount per payment, and how often they pay.') }}</span>
                                <span x-show="$wire.serviceType === 'website' || $wire.serviceType === 'software'" x-cloak>{{ __('The full project amount, paid once.') }}</span>
                                <span x-show="$wire.serviceType === 'video'" x-cloak>{{ __('Price for one video and how many videos.') }}</span>
                            </p>
                            <div class="mt-2.5 grid gap-3 sm:grid-cols-2">
                                <flux:field>
                                    <flux:input.group>
                                        <flux:input.group.prefix>{{ config('app.currency') }}</flux:input.group.prefix>
                                        <flux:input wire:model="price" type="number" min="0" step="0.01" inputmode="decimal" placeholder="25000" :aria-label="__('Price')" />
                                        <flux:input.group.suffix x-show="$wire.serviceType === 'video'" x-cloak>{{ __('per video') }}</flux:input.group.suffix>
                                    </flux:input.group>
                                    <flux:error name="price" />
                                </flux:field>

                                <flux:field x-show="$wire.serviceType === 'social_media'">
                                    <flux:input.group>
                                        <flux:input.group.prefix>{{ __('Paid every') }}</flux:input.group.prefix>
                                        <flux:input wire:model="billingMonths" type="number" min="1" max="120" step="1" inputmode="numeric" placeholder="1" :aria-label="__('Payment every how many months')" />
                                        <flux:input.group.suffix>{{ __('month(s)') }}</flux:input.group.suffix>
                                    </flux:input.group>
                                    <flux:error name="billingMonths" />
                                </flux:field>

                                <flux:field x-show="$wire.serviceType === 'video'" x-cloak>
                                    <flux:input.group>
                                        <flux:input wire:model="videoCount" type="number" min="1" max="1000" step="1" inputmode="numeric" placeholder="1" :aria-label="__('Number of videos')" />
                                        <flux:input.group.suffix>{{ __('video(s)') }}</flux:input.group.suffix>
                                    </flux:input.group>
                                    <flux:error name="videoCount" />
                                </flux:field>

                                <div x-show="$wire.serviceType === 'website' || $wire.serviceType === 'software'" x-cloak class="flex items-center gap-2 rounded-lg bg-zinc-50 px-3 text-sm text-zinc-500 ring-1 ring-zinc-100 dark:bg-white/[0.03] dark:ring-white/5">
                                    <flux:icon.check-badge variant="mini" class="size-4 text-brand-500" /> {{ __('One-time payment') }}
                                </div>
                            </div>

                            <p class="mt-2 text-xs text-zinc-500">
                                <span x-show="$wire.serviceType === 'social_media'">
                                    <span x-show="$wire.billingMonths == 1">{{ __('They pay every month.') }}</span>
                                    <span x-show="$wire.billingMonths > 1">{{ __('They pay every') }} <span class="font-medium text-zinc-700 dark:text-zinc-200" x-text="$wire.billingMonths"></span> {{ __('months') }}.</span>
                                </span>
                                <span x-show="$wire.serviceType === 'video' && $wire.price && $wire.videoCount" x-cloak>
                                    {{ __('Total') }}: <span class="font-semibold text-zinc-800 dark:text-zinc-100" x-text="@js(config('app.currency')) + ' ' + (Number($wire.price) * Number($wire.videoCount)).toLocaleString('en-US')"></span>
                                    (<span x-text="$wire.videoCount"></span> × <span x-text="Number($wire.price).toLocaleString('en-US')"></span>)
                                </span>
                            </p>
                        </div>
                    </section>

                    {{-- 3. Agreement / timeline --}}
                    <section class="flex gap-4">
                        <div class="flex flex-col items-center">
                            <span class="{{ $stepDot }}">{{ ++$n }}</span>
                            @if ($editingId)
                                <span class="{{ $stepLine }}"></span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1 {{ $editingId ? 'pb-6' : '' }}">
                            <div class="flex items-baseline justify-between gap-2">
                                <h3 class="{{ $stepTitle }}">
                                    <span x-show="$wire.serviceType === 'social_media'">{{ __('Package period') }}</span>
                                    <span x-show="$wire.serviceType !== 'social_media'" x-cloak>{{ __('Timeline') }}</span>
                                </h3>
                                <span class="text-[0.6875rem] text-zinc-400">{{ __('Optional') }}</span>
                            </div>
                            <p class="{{ $stepHint }}">
                                <span x-show="$wire.serviceType === 'social_media'">{{ __('When the package starts and ends, e.g. a 3-month package.') }}</span>
                                <span x-show="$wire.serviceType !== 'social_media'" x-cloak>{{ __('When the work starts and when it is due.') }}</span>
                            </p>

                            <div class="mt-2.5 grid gap-3 sm:grid-cols-2">
                                <x-nepali-date wire:model="agreementStart" :label="__('Start date')" />
                                @if ($serviceType === 'social_media')
                                    <flux:field>
                                        <flux:label>{{ __('Duration') }}</flux:label>
                                        <flux:input.group>
                                            <flux:input wire:model="agreementMonths" type="number" min="1" max="120" step="1" inputmode="numeric" placeholder="3" />
                                            <flux:input.group.suffix>{{ __('month(s)') }}</flux:input.group.suffix>
                                        </flux:input.group>
                                        <flux:error name="agreementMonths" />
                                    </flux:field>
                                @else
                                    <x-nepali-date wire:model="agreementEnd" :label="__('End date')" />
                                @endif
                            </div>

                            <div class="mt-3 flex items-center gap-2.5 rounded-lg bg-emerald-50 px-3 py-2.5 text-xs text-emerald-800 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20" x-show="agreement" x-cloak>
                                <flux:icon.document-check variant="micro" class="size-4 shrink-0" />
                                <span>
                                    <span class="font-semibold" x-text="agreement?.from"></span>
                                    <span class="opacity-60">→</span>
                                    <span class="font-semibold" x-text="agreement?.to"></span>
                                    <span class="opacity-75">· <span x-text="agreement?.length"></span></span>                                </span>
                            </div>
                            <p class="mt-3 text-xs text-zinc-400" x-show="! agreement">{{ $serviceType === 'social_media' ? __('Pick the start date and enter how many months to see the end date.') : __('Pick the start and end dates to see how long the work runs.') }}</p>
                        </div>
                    </section>

                    @if ($editingId)
                        {{-- 5. Progress (editing only) --}}
                        <section class="flex gap-4">
                            <div class="flex flex-col items-center">
                                <span class="{{ $stepDot }}">{{ ++$n }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="{{ $stepTitle }}">{{ __('Work progress') }}</h3>
                                <div class="mt-2 flex items-center gap-4">
                                    <input id="client-progress" type="range" min="0" max="100" step="5" x-model.number="$wire.progress" class="min-w-0 flex-1 accent-brand-500" aria-label="{{ __('Work progress') }}">
                                    <span class="w-10 text-end text-sm font-semibold text-brand-600 tabular-nums dark:text-brand-300"><span x-text="$wire.progress"></span>%</span>
                                </div>
                            </div>
                        </section>
                    @endif
                </div>
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-between gap-2 rounded-b-2xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                <div>
                    <flux:button variant="ghost" icon="arrow-left" x-show="step === 2" x-cloak @click="tab = 'details'">{{ __('Back') }}</flux:button>
                    <span class="flex items-center gap-1.5 text-xs text-zinc-400" x-show="step === 1">
                        <span class="size-1.5 rounded-full bg-brand-500"></span><span class="size-1.5 rounded-full bg-zinc-300 dark:bg-white/20"></span>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @if ($editingId)
                        <flux:button variant="filled" icon-trailing="arrow-right" x-show="step === 1" @click="tab = 'package'">{{ __('Next') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon="check" data-test="save-client-button">{{ __('Save changes') }}</flux:button>
                    @else
                        <flux:button variant="primary" icon-trailing="arrow-right" x-show="step === 1" @click="tab = 'package'">{{ __('Continue') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon="check" x-show="step === 2" x-cloak data-test="save-client-button">{{ __('Add client') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>
    </flux:modal>


    {{-- New client: pick the type first --}}
    @php
        $typeCards = [
            'social_media' => ['tint' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300', 'examples' => __('Monthly page handling, posts, reels and boosting')],
            'website' => ['tint' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300', 'examples' => __('Business sites, landing pages, online stores')],
            'software' => ['tint' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300', 'examples' => __('Apps, management systems, custom software')],
            'video' => ['tint' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300', 'examples' => __('Ads, reels, event and product videos')],
        ];
    @endphp
    <flux:modal name="client-type" class="w-full max-w-xl rounded-2xl!">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Change client type') : __('What kind of client?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Pick the service and we will show only the details that matter for it.') }}</flux:text>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (Client::SERVICE_TYPES as $key => $st)
                    <button
                        type="button"
                        wire:click="chooseType('{{ $key }}')"
                        data-test="choose-{{ $key }}"
                        class="group relative flex flex-col gap-3 rounded-2xl p-4 text-start ring-1 ring-zinc-200 transition duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_40px_-24px_rgb(49_81_254/0.45)] hover:ring-brand-300 dark:ring-white/10 dark:hover:ring-brand-500/50"
                    >
                        <div class="flex items-center justify-between">
                            <span class="{{ $typeCards[$key]['tint'] }} flex size-11 items-center justify-center rounded-xl">
                                <flux:icon :icon="$st['icon']" class="size-5.5" />
                            </span>
                            <span class="flex size-7 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 transition group-hover:bg-brand-500 group-hover:text-white dark:bg-white/5">
                                <flux:icon.arrow-right variant="micro" class="size-4" />
                            </span>
                        </div>
                        <div>
                            <p class="font-semibold text-zinc-900 dark:text-white">{{ __($st['label']) }}</p>
                            <p class="text-xs font-medium text-brand-600 dark:text-brand-300">{{ __($st['hint']) }}</p>
                            <p class="mt-1.5 text-xs leading-relaxed text-zinc-500">{{ $typeCards[$key]['examples'] }}</p>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>
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
