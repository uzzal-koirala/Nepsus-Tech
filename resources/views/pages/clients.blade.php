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
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->latest()
            ->paginate(12);
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

        $this->editingId = $client->id;
        $this->name = $client->name;
        $this->company = (string) $client->company;
        $this->email = (string) $client->email;
        $this->phone = (string) $client->phone;
        $this->clientStatus = $client->status;
        $this->notes = (string) $client->notes;
        $this->resetErrorBag();

        Flux::modal('client-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'clientStatus' => ['required', Rule::in(Client::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
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
        $this->reset('editingId', 'name', 'company', 'email', 'phone', 'notes');
        $this->clientStatus = 'active';
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
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
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
                    <p class="max-w-xs text-sm text-zinc-500">{{ __('Add your first client to keep their contact details and notes in one place.') }}</p>
                </div>
                <flux:button variant="primary" size="sm" icon="plus" wire:click="create">{{ __('Add client') }}</flux:button>
            @endif
        </div>
    @elseif ($view === 'grid')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($this->clients as $client)
                <article wire:key="client-{{ $client->id }}" class="group relative flex flex-col rounded-2xl bg-white p-5 ring-1 ring-zinc-200/70 transition hover:-translate-y-0.5 hover:shadow-[0_18px_40px_-24px_rgb(49_81_254/0.45)] hover:ring-brand-200 dark:bg-zinc-950 dark:ring-white/5 dark:hover:ring-brand-500/40">
                    <div class="flex items-start gap-3">
                        <span class="{{ $avatars[$client->id % count($avatars)] }} flex size-11 shrink-0 items-center justify-center rounded-xl text-sm font-semibold">
                            {{ $client->initials() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate font-semibold text-zinc-900 dark:text-white">{{ $client->name }}</h3>
                            <p class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $client->company ?? __('Independent') }}</p>
                        </div>
                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" class="-me-2 -mt-1" :aria-label="__('Actions for :name', ['name' => $client->name])" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $client->id }})">{{ __('Edit') }}</flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $client->id }})">{{ __('Delete') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    <dl class="mt-5 flex flex-col gap-2 text-sm">
                        <div class="flex items-center gap-2.5 text-zinc-600 dark:text-zinc-300">
                            <flux:icon.envelope variant="micro" class="size-4 shrink-0 text-zinc-400" />
                            <dt class="sr-only">{{ __('Email') }}</dt>
                            <dd class="truncate">
                                @if ($client->email)
                                    <a href="mailto:{{ $client->email }}" class="hover:text-brand-600">{{ $client->email }}</a>
                                @else
                                    <span class="text-zinc-400">{{ __('No email') }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-center gap-2.5 text-zinc-600 dark:text-zinc-300">
                            <flux:icon.phone variant="micro" class="size-4 shrink-0 text-zinc-400" />
                            <dt class="sr-only">{{ __('Phone') }}</dt>
                            <dd class="truncate tabular-nums">{!! $client->phone ? e($client->phone) : '<span class="text-zinc-400">'.e(__('No phone')).'</span>' !!}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-white/5">
                        <span class="{{ $badges[$client->status] }} inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium capitalize ring-1 ring-inset">
                            <span class="{{ $dots[$client->status] }} size-1.5 rounded-full"></span>
                            {{ __($client->status) }}
                        </span>
                        <button type="button" wire:click="edit({{ $client->id }})" class="inline-flex items-center gap-1 text-sm font-medium text-brand-500 transition hover:gap-1.5 hover:text-brand-600 dark:text-brand-300">
                            {{ __('Open') }}
                            <flux:icon.arrow-right variant="micro" class="size-3.5" />
                        </button>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl bg-white ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/5">
            <table class="w-full min-w-[44rem] text-start text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 text-xs font-medium text-zinc-500 dark:border-white/5">
                        <th class="px-5 py-3 text-start font-medium">{{ __('Client') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Email') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Phone') }}</th>
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
                            <td class="px-5 py-3 text-zinc-600 dark:text-zinc-300">{{ $client->email ?? '—' }}</td>
                            <td class="px-5 py-3 text-zinc-600 tabular-nums dark:text-zinc-300">{{ $client->phone ?? '—' }}</td>
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
    <flux:modal name="client-form" class="w-full max-w-lg">
        <form wire:submit="save" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit client') : __('New client') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Contact details and notes for this client.') }}</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required autofocus class="sm:col-span-2" />
                <flux:input wire:model="company" :label="__('Company')" />
                <flux:select wire:model="clientStatus" :label="__('Status')">
                    <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                    <flux:select.option value="lead">{{ __('Lead') }}</flux:select.option>
                    <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="email" type="email" :label="__('Email')" />
                <flux:input wire:model="phone" type="tel" :label="__('Phone')" />
                <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" class="sm:col-span-2" />
            </div>

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
                <flux:text class="mt-1">{{ __('Their contact details and notes will be removed. This cannot be undone.') }}</flux:text>
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
