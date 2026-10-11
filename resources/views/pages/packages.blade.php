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
        return Package::withCount('clients')->orderBy('position')->orderBy('id')->get();
    }

    #[Computed]
    public function summary(): array
    {
        return [
            'offered' => $this->packages->where('is_active', true)->count(),
            'clients' => Client::whereNotNull('package_id')->count(),
            'withoutPackage' => Client::whereNull('package_id')->count(),
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
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Packages') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ trans_choice(':count package offered|:count packages offered', $this->summary['offered']) }}
                · {{ trans_choice(':count client on a package|:count clients on a package', $this->summary['clients']) }}
                · {{ __('drag to reorder') }}
            </p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="new-package-button">
            {{ __('New package') }}
        </flux:button>
    </header>

    @if ($this->packages->isEmpty())
        <div class="flex flex-col items-center gap-4 rounded-2xl bg-white px-6 py-16 text-center ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.cube class="size-7" /></span>
            <div>
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('No packages yet') }}</h2>
                <p class="mt-1 max-w-xs text-sm text-zinc-500">{{ __('Create the plans you sell, with a price, an icon and what is included.') }}</p>
            </div>
            <flux:button variant="primary" size="sm" icon="plus" wire:click="create">{{ __('New package') }}</flux:button>
        </div>
    @else
        <div wire:sort="sortPackage" class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($this->packages as $p)
                @php
                    $t = $p->theme();
                @endphp
                <article wire:key="pkg-{{ $p->id }}" wire:sort:item="{{ $p->id }}" @class([
                    'group flex flex-col overflow-hidden rounded-2xl bg-white ring-1 ring-zinc-200/70 transition duration-300 hover:-translate-y-1 hover:shadow-[0_24px_50px_-28px_rgb(15_23_42/0.45)] dark:bg-zinc-950 dark:ring-white/10',
                    'opacity-60 hover:opacity-100' => ! $p->is_active,
                ])>
                    {{-- Cover --}}
                    <div class="{{ $t['cover'] }} relative bg-linear-to-br px-5 pt-4 pb-14">
                        <div class="absolute inset-0 opacity-20 [background-image:radial-gradient(currentColor_1px,transparent_1px)] [background-size:14px_14px] [mask-image:linear-gradient(to_left,black,transparent_75%)]"></div>
                        <flux:icon :icon="$p->icon" class="absolute -right-5 -bottom-6 size-32 opacity-15" />

                        <div class="relative flex items-center justify-between">
                            <button type="button" wire:sort:handle class="-ms-1.5 flex size-7 cursor-grab items-center justify-center rounded-md opacity-60 transition hover:bg-white/20 hover:opacity-100 active:cursor-grabbing" aria-label="{{ __('Drag to reorder') }}">
                                <flux:icon.grip-vertical variant="micro" class="size-4" />
                            </button>
                            <div class="flex items-center gap-1">
                                @unless ($p->is_active)
                                    <span class="rounded-full bg-white/90 px-2 py-0.5 text-[0.6875rem] font-medium text-zinc-600">{{ __('Not offered') }}</span>
                                @endunless
                                <flux:dropdown align="end">
                                    <button type="button" class="flex size-7 items-center justify-center rounded-md opacity-80 transition hover:bg-white/20 hover:opacity-100" aria-label="{{ __('Actions for :name', ['name' => $p->name]) }}">
                                        <flux:icon.ellipsis-horizontal variant="micro" class="size-4" />
                                    </button>
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $p->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $p->id }})">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>

                        <span class="relative mt-3 flex size-12 items-center justify-center rounded-xl bg-white/25 shadow-sm ring-1 ring-white/40 backdrop-blur">
                            <flux:icon :icon="$p->icon" class="size-6" />
                        </span>
                        <h2 class="relative mt-3 truncate text-xl font-semibold tracking-tight">{{ $p->name }}</h2>
                        <p class="relative mt-0.5 line-clamp-2 min-h-10 text-sm opacity-85">{{ $p->description }}</p>
                    </div>

                    {{-- Price --}}
                    <div class="relative z-10 mx-4 -mt-9 flex items-end justify-between gap-3 rounded-xl bg-white px-4 py-3.5 shadow-[0_12px_30px_-14px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200/60 dark:bg-zinc-900 dark:ring-white/10">
                        <p class="min-w-0 whitespace-nowrap tabular-nums">
                            @if ($p->price !== null)
                                <span class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $p->formattedPrice() }}</span>
                                <span class="text-xs text-zinc-400">{{ $cycles[$p->billing_cycle] ?? '' }}</span>
                            @else
                                <span class="text-lg font-semibold text-zinc-400">{{ __('Custom price') }}</span>
                            @endif
                        </p>
                        <a href="{{ route('clients.index', ['search' => $p->name]) }}" wire:navigate class="{{ $t['chip'] }} shrink-0 rounded-md px-2 py-1 text-xs font-semibold" title="{{ __('See these clients') }}">
                            {{ trans_choice(':count client|:count clients', $p->clients_count) }}
                        </a>
                    </div>

                    {{-- Features --}}
                    <ul class="flex flex-1 flex-col gap-2.5 px-5 py-5 text-sm">
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

                    <div class="border-t border-zinc-100 px-5 py-3 dark:border-white/5">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" class="w-full" wire:click="edit({{ $p->id }})">{{ __('Edit package') }}</flux:button>
                    </div>
                </article>
            @endforeach

            {{-- Add tile --}}
            <button type="button" wire:click="create" class="flex min-h-80 flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-zinc-200 text-zinc-500 transition hover:border-brand-300 hover:bg-brand-50/40 hover:text-brand-600 dark:border-white/10 dark:hover:bg-brand-500/5">
                <span class="flex size-12 items-center justify-center rounded-xl bg-zinc-100 dark:bg-white/5"><flux:icon.plus class="size-6" /></span>
                <span class="text-sm font-medium">{{ __('Add a package') }}</span>
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
