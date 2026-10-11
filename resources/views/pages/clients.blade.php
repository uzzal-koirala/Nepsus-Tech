<?php

use App\Models\Client;
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
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('company', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('package', 'like', "%{$this->search}%")))
            ->latest()
            ->paginate(12);
    }

    #[Computed]
    public function counts(): array
    {
        $counts = Client::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['all' => $counts->sum()] + collect(Client::STATUSES)->mapWithKeys(fn ($s) => [$s => $counts[$s] ?? 0])->all();
    }

    #[Computed]
    public function packages(): array
    {
        return Client::query()->whereNotNull('package')->distinct()->orderBy('package')->pluck('package')->all();
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

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'clientStatus' => ['required', Rule::in(Client::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'package' => ['nullable', 'string', 'max:255'],
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

        Client::updateOrCreate(['id' => $this->editingId], $data);

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
        $this->resetErrorBag();
    }
}; ?>

@php
    $tabs = ['all' => __('All clients'), 'active' => __('Active'), 'lead' => __('Leads'), 'inactive' => __('Inactive')];
    $badges = [
        'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300',
        'lead' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300',
        'inactive' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/15 dark:bg-white/5 dark:text-zinc-400',
    ];
    $dots = ['active' => 'bg-emerald-500', 'lead' => 'bg-amber-500', 'inactive' => 'bg-zinc-400'];
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
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count client in your workspace|:count clients in your workspace', $this->counts['all']) }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="new-client-button">
            {{ __('New client') }}
        </flux:button>
    </header>

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
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 min-[1800px]:grid-cols-5">
            @foreach ($this->clients as $client)
                @php
                    $done = $client->progress >= 100;
                    $bar = $done ? 'bg-emerald-500' : ($client->progress < 34 ? 'bg-amber-400' : 'bg-brand-500');
                @endphp
                <article wire:key="client-{{ $client->id }}" class="flex flex-col gap-4 rounded-lg bg-white p-4 ring-1 ring-zinc-200/80 transition duration-300 hover:shadow-[0_16px_36px_-22px_rgb(49_81_254/0.5)] hover:ring-brand-300 dark:bg-zinc-950 dark:ring-white/10 dark:hover:ring-brand-500/50">
                    <div class="flex items-center gap-3">
                        <span class="{{ $avatars[$client->id % count($avatars)] }} relative flex size-10 shrink-0 items-center justify-center rounded-md text-sm font-semibold">
                            {{ $client->initials() }}
                            <span class="{{ $dots[$client->status] }} absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-zinc-950" title="{{ ucfirst(__($client->status)) }}"></span>
                            <span class="sr-only">{{ __($client->status) }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate leading-snug font-semibold text-zinc-900 dark:text-white">{{ $client->name }}</h3>
                            <p class="truncate text-[0.8125rem] text-zinc-500 dark:text-zinc-400">{{ $client->company ?? __('Independent') }}</p>
                        </div>
                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" class="-me-2" :aria-label="__('Actions for :name', ['name' => $client->name])" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $client->id }})">{{ __('Edit') }}</flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $client->id }})">{{ __('Delete') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    <div class="flex items-baseline justify-between gap-3">
                        <p class="min-w-0 truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $client->package ?? __('No package') }}</p>
                        @if ($client->price !== null)
                            <p class="shrink-0 whitespace-nowrap">
                                <span class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $client->formattedPrice() }}</span><span class="text-xs text-zinc-400">{{ $cycles[$client->billing_cycle] ?? '' }}</span>
                            </p>
                        @endif
                    </div>

                    <div class="flex items-center gap-3" title="{{ __('Work progress') }}">
                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $client->progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Work progress') }}">
                            <div class="{{ $bar }} h-full rounded-full" style="width: {{ $client->progress }}%"></div>
                        </div>
                        @if ($done)
                            <flux:icon.check-circle variant="mini" class="size-4 text-emerald-500" />
                        @else
                            <span class="w-8 text-end text-xs font-medium text-zinc-500 tabular-nums">{{ $client->progress }}%</span>
                        @endif
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-zinc-100 pt-3 dark:border-white/5">
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
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($this->clients as $client)
                        <tr wire:key="row-{{ $client->id }}" class="transition hover:bg-brand-50/50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="{{ $avatars[$client->id % count($avatars)] }} flex size-9 shrink-0 items-center justify-center rounded-lg text-xs font-semibold">{{ $client->initials() }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-zinc-900 dark:text-white">{{ $client->name }}</p>
                                        <p class="truncate text-xs text-zinc-500">{{ $client->company ?? __('Independent') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-zinc-600 tabular-nums dark:text-zinc-300">{{ $client->phone ?? '-' }}</td>
                            <td class="px-5 py-3 text-zinc-700 dark:text-zinc-200">{{ $client->package ?? '-' }}</td>
                            <td class="px-5 py-3 text-end whitespace-nowrap">
                                @if ($client->price !== null)
                                    <span class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $client->formattedPrice() }}</span>
                                    <span class="text-xs text-zinc-500">{{ $cycles[$client->billing_cycle] ?? '' }}</span>
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
    <flux:modal name="client-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit client') : __('New client') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Contact details, package and social profiles.') }}</flux:text>
            </div>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-3 text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Details') }}</legend>
                <flux:input wire:model="name" :label="__('Name')" required autofocus />
                <flux:input wire:model="company" :label="__('Company')" />
                <flux:input wire:model="phone" type="tel" :label="__('Phone')" />
                <flux:select wire:model="clientStatus" :label="__('Status')">
                    <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                    <flux:select.option value="lead">{{ __('Lead') }}</flux:select.option>
                    <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="email" type="email" :label="__('Email')" :badge="__('Optional')" class="sm:col-span-2" />
            </fieldset>

            <fieldset class="grid gap-4 sm:grid-cols-3">
                <legend class="mb-3 text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Package, pricing & progress') }}</legend>
                <div class="sm:col-span-3">
                    <flux:input wire:model="package" :label="__('Package')" list="package-options" :placeholder="__('e.g. Social Media Growth')" />
                    <datalist id="package-options">
                        @foreach ($this->packages as $option)
                            <option value="{{ $option }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <flux:input wire:model="price" type="number" min="0" step="0.01" inputmode="decimal" :label="__('Price (:currency)', ['currency' => config('app.currency')])" class="sm:col-span-2" />
                <flux:select wire:model="billing_cycle" :label="__('Billing')">
                    <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                    <flux:select.option value="yearly">{{ __('Yearly') }}</flux:select.option>
                    <flux:select.option value="one_time">{{ __('One-time') }}</flux:select.option>
                </flux:select>
                <div class="sm:col-span-3" x-data>
                    <div class="flex items-center justify-between">
                        <label for="client-progress" class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Work progress') }}</label>
                        <span class="text-sm font-semibold text-brand-600 tabular-nums dark:text-brand-300"><span x-text="$wire.progress"></span>%</span>
                    </div>
                    <input id="client-progress" type="range" min="0" max="100" step="5" x-model.number="$wire.progress" class="mt-3 w-full accent-brand-500">
                    <flux:error name="progress" />
                </div>
            </fieldset>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-3 text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Social profiles') }}</legend>
                <flux:input wire:model="facebook_url" type="url" label="Facebook" placeholder="https://facebook.com/…" />
                <flux:input wire:model="instagram_url" type="url" label="Instagram" placeholder="https://instagram.com/…" />
                <flux:input wire:model="tiktok_url" type="url" label="TikTok" placeholder="https://tiktok.com/@…" />
                <flux:input wire:model="google_business_url" type="url" :label="__('Google Business Profile')" placeholder="https://g.page/…" />
            </fieldset>

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-client-button">
                    {{ $editingId ? __('Save changes') : __('Add client') }}
                </flux:button>
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
