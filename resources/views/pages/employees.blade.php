<?php

use App\Models\Employee;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employees')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: 'grid')]
    public string $view = 'grid';

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $name = '';
    public string $designation = '';
    public string $dept = 'development';
    public string $email = '';
    public string $phone = '';
    public string $employeeStatus = 'active';
    public string $joinedOn = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'department'])) {
            $this->resetPage();
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, Employee::STATUSES) ? $status : 'all';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'department');
        $this->resetPage();
    }

    #[Computed]
    public function employees()
    {
        return Employee::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->department !== '', fn ($q) => $q->where('department', $this->department))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('designation', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate(12);
    }

    #[Computed]
    public function counts(): array
    {
        $counts = Employee::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['all' => $counts->sum()] + collect(Employee::STATUSES)->map(fn ($label, $key) => $counts[$key] ?? 0)->all();
    }

    #[Computed]
    public function summary(): array
    {
        return [
            'departments' => Employee::where('status', '!=', 'inactive')->distinct()->count('department'),
            'newHires' => Employee::where('joined_on', '>=', today()->subDays(90))->count(),
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('employee-form')->show();
    }

    public function edit(int $id): void
    {
        $employee = Employee::findOrFail($id);

        $this->resetForm();
        $this->editingId = $employee->id;
        $this->name = $employee->name;
        $this->designation = (string) $employee->designation;
        $this->dept = $employee->department;
        $this->email = (string) $employee->email;
        $this->phone = (string) $employee->phone;
        $this->employeeStatus = $employee->status;
        $this->joinedOn = (string) $employee->joined_on?->toDateString();

        Flux::modal('employee-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'dept' => ['required', Rule::in(array_keys(Employee::DEPARTMENTS))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'employeeStatus' => ['required', Rule::in(array_keys(Employee::STATUSES))],
            'joinedOn' => ['nullable', 'date'],
        ]);

        Employee::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'],
            'designation' => $data['designation'] ?: null,
            'department' => $data['dept'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'status' => $data['employeeStatus'],
            'joined_on' => $data['joinedOn'] ?: null,
        ]);

        Flux::modal('employee-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Employee updated.') : __('Employee added.'));
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('employee-delete')->show();
    }

    public function delete(): void
    {
        Employee::whereKey($this->deletingId)->delete();
        $this->deletingId = null;

        Flux::modal('employee-delete')->close();
        Flux::toast(text: __('Employee removed.'));
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'designation', 'email', 'phone', 'joinedOn');
        $this->dept = 'development';
        $this->employeeStatus = 'active';
        $this->resetErrorBag();
    }
}; ?>

@php
    $tabs = ['all' => __('Everyone')] + collect(Employee::STATUSES)->map(fn ($l) => __($l))->all();
    $depts = [
        'design' => ['cover' => 'from-rose-400 to-pink-600', 'avatar' => 'bg-rose-100 text-rose-700', 'chip' => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300', 'hover' => 'hover:text-rose-600 hover:ring-rose-300'],
        'development' => ['cover' => 'from-brand-400 to-brand-700', 'avatar' => 'bg-brand-100 text-brand-700', 'chip' => 'bg-brand-50 text-brand-700 ring-brand-600/20 dark:bg-brand-500/15 dark:text-brand-200', 'hover' => 'hover:text-brand-600 hover:ring-brand-300'],
        'marketing' => ['cover' => 'from-amber-300 to-orange-500', 'avatar' => 'bg-amber-100 text-amber-800', 'chip' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300', 'hover' => 'hover:text-amber-600 hover:ring-amber-300'],
        'content' => ['cover' => 'from-emerald-400 to-teal-600', 'avatar' => 'bg-emerald-100 text-emerald-700', 'chip' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300', 'hover' => 'hover:text-emerald-600 hover:ring-emerald-300'],
        'sales' => ['cover' => 'from-sky-400 to-cyan-600', 'avatar' => 'bg-sky-100 text-sky-700', 'chip' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300', 'hover' => 'hover:text-sky-600 hover:ring-sky-300'],
        'management' => ['cover' => 'from-zinc-800 to-slate-600', 'avatar' => 'bg-zinc-200 text-zinc-800', 'chip' => 'bg-zinc-100 text-zinc-700 ring-zinc-500/20 dark:bg-white/10 dark:text-zinc-200', 'hover' => 'hover:text-zinc-900 hover:ring-zinc-400 dark:hover:text-white'],
    ];
    $statusPill = [
        'active' => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-300'],
        'on_leave' => ['dot' => 'bg-amber-500', 'text' => 'text-amber-700 dark:text-amber-300'],
        'inactive' => ['dot' => 'bg-zinc-400', 'text' => 'text-zinc-500 dark:text-zinc-400'],
    ];
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $contactBtn = 'flex size-9 items-center justify-center rounded-full bg-white text-zinc-500 ring-1 ring-zinc-200 transition duration-200 hover:-translate-y-0.5 dark:bg-zinc-900 dark:text-zinc-400 dark:ring-white/10';
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Employees') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count person on the Nepsus Tech team|:count people on the Nepsus Tech team', $this->counts['all']) }}</p>
        </div>
        <flux:button variant="primary" icon="user-plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="new-employee-button">
            {{ __('Add employee') }}
        </flux:button>
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="relative isolate flex flex-col gap-4 overflow-hidden rounded-xl bg-linear-to-br from-zinc-900 to-slate-700 p-5 text-white shadow-[0_18px_40px_-22px_rgb(15_23_42/0.8)]">
            <div class="absolute -right-6 -bottom-10 -z-10 size-32 rounded-full bg-brand-500/60 blur-xl"></div>
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15"><flux:icon.user-group variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-300">{{ __('Active team') }}</p>
            </div>
            <p class="text-[2rem] leading-none font-semibold tracking-tight tabular-nums">{{ $this->counts['active'] }}</p>
            <p class="mt-auto text-xs text-zinc-400">{{ __(':total in total, including inactive', ['total' => $this->counts['all']]) }}</p>
        </div>

        @foreach ([
            ['icon' => 'sun', 'tint' => 'bg-amber-100 text-amber-600 dark:bg-amber-400/15 dark:text-amber-300', 'label' => __('On leave'), 'value' => $this->counts['on_leave'], 'note' => __('Away right now')],
            ['icon' => 'squares-2x2', 'tint' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300', 'label' => __('Departments'), 'value' => $this->summary['departments'], 'note' => __('Teams with active people')],
            ['icon' => 'sparkles', 'tint' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300', 'label' => __('New hires'), 'value' => $this->summary['newHires'], 'note' => __('Joined in the last 90 days')],
        ] as $tile)
            <div class="{{ $panel }} flex flex-col gap-4 p-5">
                <div class="flex items-center gap-3">
                    <span class="{{ $tile['tint'] }} flex size-9 items-center justify-center rounded-lg"><flux:icon :icon="$tile['icon']" variant="mini" class="size-4.5" /></span>
                    <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $tile['label'] }}</p>
                </div>
                <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $tile['value'] }}</p>
                <p class="mt-auto text-xs text-zinc-500">{{ $tile['note'] }}</p>
            </div>
        @endforeach
    </section>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 rounded-2xl bg-white p-2 ring-1 ring-zinc-200/70 lg:flex-row lg:items-center lg:justify-between dark:bg-zinc-950 dark:ring-white/5">
        <nav class="flex gap-1 overflow-x-auto" aria-label="{{ __('Filter by status') }}">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setStatus('{{ $key }}')" @class([
                    'flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition',
                    'bg-zinc-900 text-white shadow-md shadow-zinc-900/20 dark:bg-white dark:text-zinc-900' => $status === $key,
                    'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' => $status !== $key,
                ]) @if ($status === $key) aria-current="true" @endif>
                    {{ $label }}
                    <span @class(['rounded-md px-1.5 py-0.5 text-xs tabular-nums', 'bg-white/20 dark:bg-zinc-900/10' => $status === $key, 'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $status !== $key])>{{ $this->counts[$key] }}</span>
                </button>
            @endforeach
        </nav>

        <div class="flex flex-wrap items-center gap-2">
            <flux:select wire:model.live="department" class="w-44" :aria-label="__('Department')">
                <flux:select.option value="">{{ __('All departments') }}</flux:select.option>
                @foreach (Employee::DEPARTMENTS as $key => $d)
                    <flux:select.option :value="$key">{{ __($d['label']) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search people')" clearable class="min-w-0 flex-1 lg:w-64 lg:flex-none" />
            <div class="flex shrink-0 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                @foreach (['grid' => 'squares-2x2', 'list' => 'list-bullet'] as $mode => $icon)
                    <button type="button" wire:click="$set('view', '{{ $mode }}')" aria-label="{{ $mode === 'grid' ? __('Card view') : __('List view') }}" @class([
                        'flex size-8 items-center justify-center rounded-lg transition',
                        'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' => $view === $mode,
                        'text-zinc-400 hover:text-zinc-700 dark:hover:text-white' => $view !== $mode,
                    ])>
                        <flux:icon :icon="$icon" variant="mini" class="size-4" />
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Results --}}
    @if ($this->employees->isEmpty())
        <div class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-500 dark:bg-white/5"><flux:icon.identification class="size-7" /></span>
            @if ($search !== '' || $status !== 'all' || $department !== '')
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Nobody matches these filters') }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('Try another search, status or department.') }}</p>
                </div>
                <flux:button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            @else
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('No employees yet') }}</h2>
                    <p class="mt-1 max-w-xs text-sm text-zinc-500">{{ __('Add your team to keep their roles, contacts and join dates in one place.') }}</p>
                </div>
                <flux:button variant="primary" size="sm" icon="user-plus" wire:click="create">{{ __('Add employee') }}</flux:button>
            @endif
        </div>
    @elseif ($view === 'grid')
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 min-[1800px]:grid-cols-5">
            @foreach ($this->employees as $e)
                @php
                    $d = $depts[$e->department] ?? $depts['development'];
                    $meta = Employee::DEPARTMENTS[$e->department] ?? Employee::DEPARTMENTS['development'];
                @endphp
                <article wire:key="emp-{{ $e->id }}" @class([
                    'group relative flex flex-col overflow-hidden rounded-2xl bg-white text-center ring-1 ring-zinc-200/70 transition duration-300 hover:-translate-y-1 hover:shadow-[0_24px_48px_-28px_rgb(15_23_42/0.45)] dark:bg-zinc-950 dark:ring-white/10',
                    'opacity-60 grayscale-[0.6] hover:opacity-100 hover:grayscale-0' => $e->status === 'inactive',
                ])>
                    {{-- Badge cover --}}
                    <div class="{{ $d['cover'] }} relative h-24 bg-linear-to-br">
                        <div class="absolute inset-0 opacity-25 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:14px_14px]"></div>
                        <flux:icon :icon="$meta['icon']" class="absolute -bottom-4 -left-3 size-20 text-white/15" />
                        <span class="absolute top-3 left-1/2 h-1.5 w-12 -translate-x-1/2 rounded-full bg-white/50 ring-1 ring-black/10" aria-hidden="true"></span>
                        <span class="{{ $statusPill[$e->status]['text'] }} absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-full bg-white/95 px-2 py-0.5 text-[0.6875rem] font-medium shadow-sm dark:bg-zinc-950/90">
                            <span class="{{ $statusPill[$e->status]['dot'] }} size-1.5 rounded-full"></span>
                            {{ __(Employee::STATUSES[$e->status]) }}
                        </span>
                        <div class="absolute top-1.5 right-1.5">
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" class="text-white! hover:bg-white/20!" :aria-label="__('Actions for :name', ['name' => $e->name])" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $e->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $e->id }})">{{ __('Remove') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>

                    {{-- Identity --}}
                    <div class="-mt-11 flex flex-1 flex-col items-center px-5 pb-5">
                        <span class="{{ $d['avatar'] }} relative flex size-[5.5rem] items-center justify-center rounded-full text-2xl font-semibold shadow-lg ring-4 ring-white dark:ring-zinc-950">
                            {{ $e->initials() }}
                        </span>
                        <h3 class="mt-3 w-full truncate text-base font-semibold text-zinc-900 dark:text-white">{{ $e->name }}</h3>
                        <p class="w-full truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $e->designation ?? __('No role set') }}</p>
                        <span class="{{ $d['chip'] }} mt-2.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset">
                            <flux:icon :icon="$meta['icon']" variant="micro" class="size-3.5" />
                            {{ __($meta['label']) }}
                        </span>

                        <div class="mt-4 flex items-center justify-center gap-2">
                            @if ($e->phone)
                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $e->phone) }}" class="{{ $contactBtn }} {{ $d['hover'] }}" title="{{ __('Call :phone', ['phone' => $e->phone]) }}">
                                    <flux:icon.phone variant="micro" class="size-4" /><span class="sr-only">{{ __('Call') }}</span>
                                </a>
                                <a href="https://wa.me/{{ $e->whatsappNumber() }}" target="_blank" rel="noopener noreferrer" class="{{ $contactBtn }} hover:text-[#25D366] hover:ring-[#25D366]/40" title="WhatsApp">
                                    <x-social-icon name="whatsapp" class="size-4" /><span class="sr-only">WhatsApp</span>
                                </a>
                            @endif
                            @if ($e->email)
                                <a href="mailto:{{ $e->email }}" class="{{ $contactBtn }} {{ $d['hover'] }}" title="{{ $e->email }}">
                                    <flux:icon.envelope variant="micro" class="size-4" /><span class="sr-only">{{ __('Email') }}</span>
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="grid grid-cols-2 divide-x divide-zinc-100 border-t border-dashed border-zinc-200 bg-zinc-50/60 text-start dark:divide-white/5 dark:border-white/10 dark:bg-white/[0.02]">
                        <div class="px-4 py-3">
                            <p class="text-[0.6875rem] text-zinc-400">{{ __('Joined') }}</p>
                            <p class="truncate text-xs font-medium text-zinc-700 dark:text-zinc-200" title="{{ $e->joined_on?->format('j M Y') }}">{{ $e->joined_on?->toNepali() ?: '-' }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-[0.6875rem] text-zinc-400">{{ __('With us') }}</p>
                            <p class="text-xs font-medium text-zinc-700 tabular-nums dark:text-zinc-200">{{ $e->tenure() ?? '-' }}</p>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="{{ $panel }} overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 text-xs text-zinc-500 dark:border-white/5">
                        <th class="px-5 py-3 text-start font-medium">{{ __('Employee') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Department') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Phone') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Joined') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Status') }}</th>
                        <th class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($this->employees as $e)
                        @php
                            $d = $depts[$e->department] ?? $depts['development'];
                            $meta = Employee::DEPARTMENTS[$e->department] ?? Employee::DEPARTMENTS['development'];
                        @endphp
                        <tr wire:key="emp-row-{{ $e->id }}" class="transition hover:bg-zinc-50/70 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="{{ $d['avatar'] }} flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold">{{ $e->initials() }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-zinc-900 dark:text-white">{{ $e->name }}</p>
                                        <p class="truncate text-xs text-zinc-500">{{ $e->designation ?? __('No role set') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $d['chip'] }} inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset">
                                    <flux:icon :icon="$meta['icon']" variant="micro" class="size-3.5" />
                                    {{ __($meta['label']) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-zinc-600 tabular-nums dark:text-zinc-300">{{ $e->phone ?? '-' }}</td>
                            <td class="px-5 py-3">
                                <p class="text-zinc-700 dark:text-zinc-200" title="{{ $e->joined_on?->format('j M Y') }}">{{ $e->joined_on?->toNepali() ?: '-' }}</p>
                                <p class="text-xs text-zinc-400">{{ $e->tenure() }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $statusPill[$e->status]['text'] }} inline-flex items-center gap-1.5 text-xs font-medium">
                                    <span class="{{ $statusPill[$e->status]['dot'] }} size-1.5 rounded-full"></span>
                                    {{ __(Employee::STATUSES[$e->status]) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-end">
                                <flux:dropdown align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('Actions for :name', ['name' => $e->name])" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $e->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $e->id }})">{{ __('Remove') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($this->employees->hasPages())
        <flux:pagination :paginator="$this->employees" />
    @endif

    {{-- Add / edit --}}
    <flux:modal name="employee-form" class="w-full max-w-lg">
        <form wire:submit="save" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit employee') : __('Add employee') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Role, team and contact details.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Full name')" required autofocus class="sm:col-span-2" />
                <flux:input wire:model="designation" :label="__('Role')" :placeholder="__('e.g. Graphic Designer')" />
                <flux:select wire:model="dept" :label="__('Department')">
                    @foreach (Employee::DEPARTMENTS as $key => $d)
                        <flux:select.option :value="$key">{{ __($d['label']) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" />
                <flux:input wire:model="email" type="email" :label="__('Email')" />
                <x-nepali-date wire:model="joinedOn" :label="__('Joined on')" />
                <flux:select wire:model="employeeStatus" :label="__('Status')">
                    @foreach (Employee::STATUSES as $key => $label)
                        <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-employee-button">{{ $editingId ? __('Save changes') : __('Add employee') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove --}}
    <flux:modal name="employee-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Remove this employee?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Their details will be deleted. To keep their history, set their status to Inactive instead.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
