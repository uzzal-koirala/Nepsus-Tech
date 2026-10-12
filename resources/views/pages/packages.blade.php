<?php

use App\Models\Client;
use App\Models\Package;
use App\Support\PackageTheme;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Packages')] class extends Component {
    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $name = '';
    public string $description = '';
    public string $price = '';
    public string $billingCycle = 'monthly';
    public string $icon = 'star';
    public string $color = 'blue';
    public string $features = '';
    public bool $isActive = true;

    #[Computed]
    public function packages()
    {
        return Package::withCount('clients')
            ->with(['clients' => fn ($q) => $q->orderBy('position')])
            ->orderBy('position')->orderBy('id')->get()
            ->each(fn ($p) => $p->monthly = $p->clients->where('status', 'active')
                ->sum(fn ($c) => $c->price !== null && $c->billing_months ? $c->price / $c->billing_months : 0));
    }

    #[Computed]
    public function summary(): array
    {
        return [
            'offered' => $this->packages->where('is_active', true)->count(),
            'hidden' => $this->packages->where('is_active', false)->count(),
            'clients' => $this->packages->sum('clients_count'),
            'monthly' => (float) $this->packages->sum('monthly'),
            'withoutPackage' => Client::where('service_type', 'social_media')->whereNull('package_id')->count(),
            'popularId' => $this->packages->where('clients_count', '>', 0)->sortByDesc('clients_count')->first()?->id,
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('package-form')->show();
    }

    public function edit(int $id): void
    {
        $package = Package::findOrFail($id);

        $this->resetForm();
        $this->editingId = $package->id;
        $this->name = $package->name;
        $this->description = (string) $package->description;
        $this->price = $package->price === null ? '' : rtrim(rtrim((string) $package->price, '0'), '.');
        $this->billingCycle = $package->billing_cycle;
        $this->icon = $package->icon;
        $this->color = $package->color;
        $this->features = implode("\n", $package->features ?? []);
        $this->isActive = $package->is_active;

        Flux::modal('package-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:160'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'billingCycle' => ['required', Rule::in(Client::BILLING_CYCLES)],
            'icon' => ['required', Rule::in(PackageTheme::ICONS)],
            'color' => ['required', Rule::in(array_keys(PackageTheme::THEMES))],
            'features' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['boolean'],
        ]);

        $features = collect(preg_split('/\R/', $data['features'] ?? ''))
            ->map(fn ($line) => trim(ltrim(trim($line), '-•*')))
            ->filter()
            ->take(12)
            ->values()
            ->all();

        $values = [
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'price' => $data['price'] === '' || $data['price'] === null ? null : $data['price'],
            'billing_cycle' => $data['billingCycle'],
            'icon' => $data['icon'],
            'color' => $data['color'],
            'features' => $features,
            'is_active' => $data['isActive'],
        ];

        if (! $this->editingId) {
            $values['position'] = (int) Package::max('position') + 1;
        }

        Package::updateOrCreate(['id' => $this->editingId], $values);

        Flux::modal('package-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Package updated.') : __('Package created.'));
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('package-delete')->show();
    }

    public function delete(): void
    {
        Package::whereKey($this->deletingId)->delete(); // clients fall back to "no package" (nullOnDelete)
        $this->deletingId = null;

        Flux::modal('package-delete')->close();
        Flux::toast(text: __('Package deleted.'));
    }

    public function sortPackage(int $id, int $position): void
    {
        $ids = $this->packages->pluck('id')->reject(fn ($v) => $v === $id)->values();
        $ids->splice($position, 0, [$id]);

        foreach ($ids as $i => $packageId) {
            Package::whereKey($packageId)->update(['position' => $i]);
        }

        unset($this->packages);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'description', 'price', 'features');
        $this->billingCycle = 'monthly';
        $this->icon = 'star';
        $this->color = 'blue';
        $this->isActive = true;
        $this->resetErrorBag();
    }
}; ?>

@php
    $cycles = ['monthly' => __('/mo'), 'yearly' => __('/yr'), 'one_time' => ' · '.__('one-time')];
    $deleting = $deletingId ? $this->packages->firstWhere('id', $deletingId) : null;
    $seg = 'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition';
    $segOn = 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-white dark:ring-white/10';
    $segOff = 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white';
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $summary = $this->summary;
    $popular = $this->packages->firstWhere('id', $summary['popularId']);
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Packages') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('The social media plans you sell. Drag a card to change the order.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="new-package-button">
            {{ __('New package') }}
        </flux:button>
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5">
            <div class="absolute -top-12 -right-12 size-32 rounded-full bg-brand-100/70 dark:bg-brand-500/10"></div>
            <div class="relative flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.cube variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Packages offered') }}</p>
            </div>
            <p class="relative text-[1.75rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $summary['offered'] }}</p>
            <p class="relative mt-auto text-xs text-zinc-500">{{ $summary['hidden'] ? trans_choice(':count switched off|:count switched off', $summary['hidden']) : __('All packages are on offer') }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300"><flux:icon.user-group variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Clients on a package') }}</p>
            </div>
            <p class="text-[1.75rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $summary['clients'] }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ $popular ? __(':name is the most chosen', ['name' => $popular->name]) : __('No clients on a package yet') }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.arrow-trending-up variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Monthly income') }}</p>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ Client::money(round($summary['monthly'])) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ __('From active clients, per month') }}</p>
        </div>

        <a href="{{ route('clients.index', ['service' => 'social_media']) }}" wire:navigate class="{{ $panel }} group flex flex-col gap-4 p-5 transition hover:ring-amber-300">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-400/15 dark:text-amber-300"><flux:icon.exclamation-triangle variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Without a package') }}</p>
                <flux:icon.arrow-up-right variant="micro" class="size-4 text-zinc-300 transition group-hover:text-amber-500" />
            </div>
            <p class="text-[1.75rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $summary['withoutPackage'] }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ __('Social media clients to assign') }}</p>
        </a>
    </section>

    @if ($this->packages->isEmpty())
        <div class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.cube class="size-7" /></span>
            <div>
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('No packages yet') }}</h2>
                <p class="mt-1 max-w-xs text-sm text-zinc-500">{{ __('Create the plans you sell, with a price, an icon and what is included.') }}</p>
            </div>
            <flux:button variant="primary" size="sm" icon="plus" wire:click="create">{{ __('New package') }}</flux:button>
        </div>
    @else
        <div wire:sort="sortPackage" class="grid items-stretch gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($this->packages as $p)
                @php
                    $t = $p->theme();
                    $isPopular = $p->id === $summary['popularId'];
                    $share = $summary['monthly'] > 0 ? (int) round($p->monthly / $summary['monthly'] * 100) : 0;
                    $faces = $p->clients->take(4);
                @endphp
                <article wire:key="pkg-{{ $p->id }}" wire:sort:item="{{ $p->id }}" @class([
                    'group relative flex flex-col rounded-2xl bg-white bg-linear-to-b to-white to-45% p-1.5 ring-1 transition duration-300 hover:-translate-y-1 dark:bg-zinc-950 dark:to-zinc-950',
                    $t['card'],
                    'ring-2!' => $isPopular,
                    'opacity-60 hover:opacity-100' => ! $p->is_active,
                ])>
                    @if ($isPopular)
                        <span class="{{ $t['chip'] }} absolute -top-3 left-1/2 z-10 inline-flex -translate-x-1/2 items-center gap-1 rounded-full px-3 py-1 text-[0.6875rem] font-semibold whitespace-nowrap shadow-sm">
                            <flux:icon.fire variant="micro" class="size-3.5" /> {{ __('Most popular') }}
                        </span>
                    @endif

                    <div class="flex flex-col gap-5 px-4 pt-4 pb-5">
                        {{-- Top row --}}
                        <div class="flex items-start justify-between">
                            <span class="{{ $t['cover'] }} flex size-12 items-center justify-center rounded-xl bg-linear-to-br shadow-lg shadow-zinc-900/10">
                                <flux:icon :icon="$p->icon" class="size-6" />
                            </span>
                            <div class="flex items-center gap-0.5 text-zinc-400">
                                @unless ($p->is_active)
                                    <span class="me-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[0.6875rem] font-medium text-zinc-500 dark:bg-white/10">{{ __('Not offered') }}</span>
                                @endunless
                                <button type="button" wire:sort:handle class="flex size-7 cursor-grab items-center justify-center rounded-md opacity-0 transition group-hover:opacity-100 hover:bg-zinc-100 hover:text-zinc-700 focus:opacity-100 active:cursor-grabbing dark:hover:bg-white/10" aria-label="{{ __('Drag to reorder') }}">
                                    <flux:icon.grip-vertical variant="micro" class="size-4" />
                                </button>
                                <flux:dropdown align="end">
                                    <button type="button" class="flex size-7 items-center justify-center rounded-md transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Actions for :name', ['name' => $p->name]) }}">
                                        <flux:icon.ellipsis-horizontal variant="micro" class="size-4" />
                                    </button>
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $p->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $p->id }})">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>

                        {{-- Name and price --}}
                        <div>
                            <h2 class="truncate text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $p->name }}</h2>
                            <p class="mt-0.5 line-clamp-2 min-h-10 text-sm text-zinc-500 dark:text-zinc-400">{{ $p->description ?: __('No description yet.') }}</p>
                            <p class="mt-4 flex items-baseline gap-1 whitespace-nowrap tabular-nums">
                                @if ($p->price !== null)
                                    <span class="text-sm font-medium text-zinc-400">{{ config('app.currency') }}</span>
                                    <span class="text-[2rem] leading-none font-semibold tracking-tight text-zinc-900 dark:text-white">{{ number_format((float) $p->price) }}</span>
                                    <span class="text-sm text-zinc-400">{{ $cycles[$p->billing_cycle] ?? '' }}</span>
                                @else
                                    <span class="text-2xl leading-none font-semibold tracking-tight text-zinc-400">{{ __('Custom price') }}</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    {{-- Inner panel --}}
                    <div class="flex flex-1 flex-col rounded-xl bg-white ring-1 ring-zinc-200/60 dark:bg-white/[0.03] dark:ring-white/10">
                        {{-- Clients --}}
                        <a href="{{ route('clients.index', ['search' => $p->name]) }}" wire:navigate class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3 transition hover:bg-zinc-50/80 dark:border-white/5 dark:hover:bg-white/[0.03]" title="{{ __('See these clients') }}">
                            @if ($faces->isNotEmpty())
                                <div class="flex -space-x-1.5">
                                    @foreach ($faces as $c)
                                        @if ($c->avatarUrl())
                                            <img src="{{ $c->avatarUrl() }}" alt="" class="size-8 rounded-full object-cover ring-2 ring-white dark:ring-zinc-900">
                                        @else
                                            <span class="{{ $t['icon'] }} flex size-8 items-center justify-center rounded-full text-[0.625rem] font-semibold tracking-tighter ring-2 ring-white dark:ring-zinc-900" title="{{ $c->displayName() }}">{{ $c->initials() }}</span>
                                        @endif
                                    @endforeach
                                    @if ($p->clients_count > $faces->count())
                                        <span class="flex size-8 items-center justify-center rounded-full bg-zinc-100 text-[0.625rem] font-semibold text-zinc-600 ring-2 ring-white dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-900">+{{ $p->clients_count - $faces->count() }}</span>
                                    @endif
                                </div>
                                <p class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ trans_choice(':count client|:count clients', $p->clients_count) }}</p>
                            @else
                                <span class="flex size-7 items-center justify-center rounded-full border border-dashed border-zinc-300 text-zinc-400 dark:border-white/20"><flux:icon.user variant="micro" class="size-3.5" /></span>
                                <p class="flex-1 text-sm text-zinc-400">{{ __('No clients yet') }}</p>
                            @endif
                            <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-300" />
                        </a>

                        {{-- Income share --}}
                        <div class="flex flex-col gap-2 border-b border-zinc-100 px-4 py-3 dark:border-white/5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-zinc-500">{{ __('Brings in') }} <span class="font-semibold text-zinc-800 tabular-nums dark:text-zinc-100">{{ Client::money(round($p->monthly)) }}</span>{{ __('/mo') }}</span>
                                <span class="font-semibold text-zinc-500 tabular-nums">{{ $share }}%</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                <div class="{{ $t['swatch'] }} h-full rounded-full transition-[width] duration-700" style="width: {{ $share }}%"></div>
                            </div>
                        </div>

                        {{-- Features --}}
                        <div class="flex flex-1 flex-col gap-3 px-4 py-4">
                            <p class="text-[0.6875rem] font-semibold tracking-wider text-zinc-400 uppercase">{{ __('What is included') }}</p>
                            <ul class="flex flex-col gap-2.5 text-sm">
                                @forelse ($p->features ?? [] as $feature)
                                    <li class="flex items-start gap-2.5 text-zinc-700 dark:text-zinc-300">
                                        <span class="{{ $t['icon'] }} mt-0.5 flex size-4.5 shrink-0 items-center justify-center rounded-full">
                                            <flux:icon.check variant="micro" class="size-3" />
                                        </span>
                                        {{ $feature }}
                                    </li>
                                @empty
                                    <li class="text-zinc-400">{{ __('No features listed yet.') }}</li>
                                @endforelse
                            </ul>
                        </div>

                        <div class="px-3 pb-3">
                            <flux:button size="sm" icon="pencil-square" class="w-full" wire:click="edit({{ $p->id }})">{{ __('Edit package') }}</flux:button>
                        </div>
                    </div>
                </article>
            @endforeach

            {{-- Add tile --}}
            <button type="button" wire:click="create" class="group flex min-h-96 flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-zinc-200 p-6 text-center text-zinc-500 transition hover:border-brand-300 hover:bg-brand-50/40 hover:text-brand-600 dark:border-white/10 dark:hover:bg-brand-500/5">
                <span class="flex size-12 items-center justify-center rounded-xl bg-white shadow-sm ring-1 ring-zinc-200 transition group-hover:scale-110 group-hover:bg-brand-500 group-hover:text-white group-hover:ring-brand-500 dark:bg-white/5 dark:ring-white/10"><flux:icon.plus class="size-6" /></span>
                <span class="text-sm font-semibold">{{ __('Add a package') }}</span>
                <span class="max-w-48 text-xs text-zinc-400">{{ __('Set a price, pick an icon and colour, and list what is included.') }}</span>
            </button>
        </div>
    @endif

    {{-- Create / edit --}}
    <flux:modal name="package-form" class="w-full max-w-4xl overflow-hidden p-0!" :closable="false">
        <form wire:submit="save" class="flex flex-col" x-data="{ themes: @js(collect(PackageTheme::THEMES)->map(fn ($t) => $t['cover'])) }">
            <div class="flex items-center justify-between gap-4 border-b border-zinc-100 px-6 py-4 dark:border-white/5">
                <flux:heading size="lg">{{ $editingId ? __('Edit package') : __('New package') }}</flux:heading>
                <flux:modal.close><flux:button variant="ghost" size="sm" icon="x-mark" :aria-label="__('Close')" /></flux:modal.close>
            </div>

            <div class="grid gap-6 p-6 md:grid-cols-[1fr_17.5rem]">
                {{-- Details --}}
                <div class="flex flex-col gap-4">
                    <flux:input wire:model="name" :label="__('Package name')" :placeholder="__('e.g. Growth')" required />
                    <flux:input wire:model="description" :label="__('Short description')" :placeholder="__('e.g. Our most popular choice')" />
                    <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                        <flux:input wire:model="price" type="number" min="0" step="1" :label="__('Price (:currency)', ['currency' => config('app.currency')])" :placeholder="__('Leave empty for a custom price')" />
                        <div>
                            <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Billing') }}</span>
                            <div class="mt-2 flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                                @foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly', 'one_time' => 'One-time'] as $value => $label)
                                    <button type="button" @click="$wire.billingCycle = '{{ $value }}'" class="{{ $seg }} whitespace-nowrap" :class="$wire.billingCycle === '{{ $value }}' ? @js($segOn) : @js($segOff)">{{ __($label) }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <flux:textarea wire:model="features" :label="__('What is included')" rows="5" :placeholder="__('One feature per line, e.g. 16 posts a month')" />
                    <flux:switch wire:model="isActive" :label="__('Offer this package to new clients')" />
                </div>

                {{-- Look --}}
                <div class="flex flex-col gap-4">
                    <div class="overflow-hidden rounded-xl bg-linear-to-br p-4 shadow-[0_12px_30px_-16px_rgb(15_23_42/0.45)]" :class="themes[$wire.color]">
                        <span class="flex size-10 items-center justify-center rounded-lg bg-white/25 ring-1 ring-white/40">
                            @foreach (PackageTheme::ICONS as $i)
                                <flux:icon :icon="$i" variant="mini" class="size-5" x-show="$wire.icon === '{{ $i }}'" />
                            @endforeach
                        </span>
                        <p class="mt-3 truncate text-lg font-semibold" x-text="$wire.name || @js(__('Package name'))"></p>
                        <p class="text-sm font-medium tabular-nums opacity-85" x-text="$wire.price ? @js(config('app.currency')) + ' ' + Number($wire.price).toLocaleString('en-US') : @js(__('Custom price'))"></p>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Colour') }}</span>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (PackageTheme::THEMES as $key => $theme)
                                <button type="button" @click="$wire.color = '{{ $key }}'" class="{{ $theme['swatch'] }} size-7 rounded-full ring-offset-2 transition hover:scale-110 dark:ring-offset-zinc-900" :class="$wire.color === '{{ $key }}' ? 'ring-2 ring-zinc-900 dark:ring-white' : ''" title="{{ __($theme['label']) }}" aria-label="{{ __($theme['label']) }}"></button>
                            @endforeach
                        </div>
                        <flux:error name="color" />
                    </div>

                    <div>
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Icon') }}</span>
                        <div class="mt-2 grid grid-cols-7 gap-1.5">
                            @foreach (PackageTheme::ICONS as $i)
                                <button type="button" @click="$wire.icon = '{{ $i }}'" class="flex aspect-square items-center justify-center rounded-lg transition" :class="$wire.icon === '{{ $i }}' ? 'bg-brand-500 text-white shadow-md shadow-brand-500/30' : 'text-zinc-500 ring-1 ring-zinc-200 hover:bg-zinc-50 hover:text-zinc-800 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white'" title="{{ str_replace('-', ' ', $i) }}" aria-label="{{ str_replace('-', ' ', $i) }}">
                                    <flux:icon :icon="$i" variant="mini" class="size-4.5" />
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="icon" />
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-100 bg-zinc-50/60 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-package-button">{{ $editingId ? __('Save changes') : __('Create package') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete --}}
    <flux:modal name="package-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $deleting?->name ?? __('this package')]) }}</flux:heading>
                <flux:text class="mt-1">
                    @if ($deleting?->clients_count)
                        {{ trans_choice(':count client uses this package and will be set to No package.|:count clients use this package and will be set to No package.', $deleting->clients_count) }}
                    @endif
                    {{ __('To keep it for existing clients but stop offering it, turn off "Offer this package" instead.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete package') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
