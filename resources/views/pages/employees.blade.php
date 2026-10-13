<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Support\NepaliDate;
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
    public string $salary = '';
    public string $joinedOn = '';
    public string $newDepartment = '';

    public ?int $payEmployeeId = null;
    public string $payPeriod = '';
    public string $payAmount = '';
    public string $payDate = '';
    public string $payNote = '';

    public ?int $historyEmployeeId = null;

    public function updated($property): void
    {
        if (in_array($property, ['search'])) {
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
        $this->reset('search', 'status');
        $this->resetPage();
    }

    #[Computed]
    public function period(): string
    {
        return NepaliDate::period(today());
    }

    #[Computed]
    public function employees()
    {
        return Employee::query()
            ->withSalaryPaid($this->period)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
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
    public function payroll(): array
    {
        $onPayroll = Employee::where('status', '!=', 'inactive')->whereNotNull('salary')->withSalaryPaid($this->period)->get();
        $total = (float) $onPayroll->sum('salary');
        $paid = (float) $onPayroll->sum(fn ($e) => min((float) $e->salary, (float) $e->paid_in_period));

        return [
            'total' => $total,
            'paid' => $paid,
            'left' => max(0, $total - $paid),
            'percent' => $total > 0 ? (int) round($paid / $total * 100) : 0,
            'unpaidPeople' => $onPayroll->filter(fn ($e) => (float) $e->paid_in_period < (float) $e->salary)->count(),
        ];
    }

    // Add / edit

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
        $this->salary = $employee->salary === null ? '' : rtrim(rtrim((string) $employee->salary, '0'), '.');
        $this->joinedOn = (string) $employee->joined_on?->toDateString();

        Flux::modal('employee-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'dept' => ['required', Rule::exists('departments', 'slug')],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'employeeStatus' => ['required', Rule::in(array_keys(Employee::STATUSES))],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'joinedOn' => ['nullable', 'date'],
        ]);

        Employee::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'],
            'designation' => $data['designation'] ?: null,
            'department' => $data['dept'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'status' => $data['employeeStatus'],
            'salary' => $data['salary'] === '' || $data['salary'] === null ? null : $data['salary'],
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

    // Salary

    public function openPay(int $id): void
    {
        $employee = Employee::withSalaryPaid($this->period)->findOrFail($id);

        $this->payEmployeeId = $employee->id;
        $this->payPeriod = $this->period;
        $this->payAmount = (string) ($employee->salaryProgress()['left'] ?? '');
        $this->payDate = today()->toDateString();
        $this->payNote = '';
        $this->resetErrorBag();

        Flux::modal('pay-salary')->show();
    }

    public function savePay(): void
    {
        $data = $this->validate([
            'payEmployeeId' => ['required', 'exists:employees,id'],
            'payPeriod' => ['required', Rule::in(NepaliDate::recentPeriods(6))],
            'payAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payDate' => ['required', 'date'],
            'payNote' => ['nullable', 'string', 'max:255'],
        ]);

        Employee::findOrFail($data['payEmployeeId'])->salaryPayments()->create([
            'period' => $data['payPeriod'],
            'amount' => $data['payAmount'],
            'paid_on' => $data['payDate'],
            'note' => $data['payNote'] ?: null,
        ]);

        Flux::modal('pay-salary')->close();
        Flux::toast(variant: 'success', text: __('Salary payment saved.'));
    }

    public function openHistory(int $id): void
    {
        $this->historyEmployeeId = Employee::findOrFail($id)->id;
        Flux::modal('salary-history')->show();
    }

    #[Computed]
    public function historyEmployee(): ?Employee
    {
        return $this->historyEmployeeId
            ? Employee::with(['salaryPayments' => fn ($q) => $q->orderByDesc('period')->orderByDesc('paid_on')])->find($this->historyEmployeeId)
            : null;
    }

    public function deleteSalaryPayment(int $id): void
    {
        SalaryPayment::where('employee_id', $this->historyEmployeeId)->findOrFail($id)->delete();
        unset($this->historyEmployee);
        Flux::toast(text: __('Salary payment deleted.'));
    }

    public function addDepartment(): void
    {
        $this->validate(['newDepartment' => ['required', 'string', 'max:40']], attributes: ['newDepartment' => __('department name')]);

        $name = trim($this->newDepartment);
        $department = Department::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? Department::add($name);
        Employee::forgetDepartments();

        $this->dept = $department->slug;
        $this->reset('newDepartment');
    }

    public function deleteDepartment(string $slug): void
    {
        $inUse = Employee::where('department', $slug)->count();
        if ($inUse) {
            Flux::toast(variant: 'warning', text: trans_choice(':count employee is in this department. Move them first.|:count employees are in this department. Move them first.', $inUse));

            return;
        }
        if (Department::count() <= 1) {
            Flux::toast(variant: 'warning', text: __('Keep at least one department.'));

            return;
        }

        Department::where('slug', $slug)->delete();
        Employee::forgetDepartments();
        if ($this->dept === $slug) {
            $this->dept = (string) array_key_first(Employee::departments());
        }
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'designation', 'email', 'phone', 'salary', 'joinedOn');
        $this->dept = 'development';
        $this->employeeStatus = 'active';
        $this->resetErrorBag();
    }
}; ?>

@php
    $periodLabel = NepaliDate::periodLabel($this->period);
    $money = fn ($v) => \App\Models\Client::money($v);
    $tabs = ['all' => __('Everyone')] + collect(Employee::STATUSES)->map(fn ($l) => __($l))->all();
    $depts = [
        'design' => ['avatar' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200', 'chip' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
        'development' => ['avatar' => 'bg-brand-100 text-brand-700 dark:bg-brand-500/25 dark:text-brand-100', 'chip' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200'],
        'marketing' => ['avatar' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200', 'chip' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'],
        'content' => ['avatar' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200', 'chip' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        'sales' => ['avatar' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200', 'chip' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        'management' => ['avatar' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200', 'chip' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
        'hr' => ['avatar' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-200', 'chip' => 'bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300'],
    ];
    $statusDot = ['active' => 'bg-emerald-500', 'on_leave' => 'bg-amber-400', 'inactive' => 'bg-zinc-300'];
    $statusText = ['active' => 'text-emerald-700 dark:text-emerald-300', 'on_leave' => 'text-amber-700 dark:text-amber-300', 'inactive' => 'text-zinc-500'];
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $contact = 'flex size-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5 dark:hover:text-white';
    $payroll = $this->payroll;
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Employees') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count person on the Nepsus Tech team|:count people on the Nepsus Tech team', $this->counts['all']) }} · {{ __('Payroll month: :month', ['month' => $periodLabel]) }}</p>
        </div>
        <flux:button variant="primary" icon="user-plus" wire:click="create" class="shadow-lg shadow-brand-500/25" data-test="new-employee-button">
            {{ __('Add employee') }}
        </flux:button>
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5">
            <div class="absolute -top-12 -right-12 size-32 rounded-full bg-brand-100/70 dark:bg-brand-500/10"></div>
            <div class="relative flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.user-group variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Active team') }}</p>
            </div>
            <p class="relative text-[1.75rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $this->counts['active'] }}</p>
            <p class="relative mt-auto text-xs text-zinc-500">{{ trans_choice(':count on leave|:count on leave', $this->counts['on_leave']) }} · {{ trans_choice(':count inactive|:count inactive', $this->counts['inactive']) }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300"><flux:icon.wallet variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Monthly payroll') }}</p>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($payroll['total']) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ __('Salaries of everyone not inactive') }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Paid for :month', ['month' => $periodLabel]) }}</p>
                <span class="text-xs font-semibold text-emerald-600 tabular-nums dark:text-emerald-400">{{ $payroll['percent'] }}%</span>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($payroll['paid']) }}</p>
            <div class="mt-auto h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-emerald-500 transition-[width] duration-700" style="width: {{ $payroll['percent'] }}%"></div></div>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-400/15 dark:text-amber-300"><flux:icon.clock variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Still to pay') }}</p>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($payroll['left']) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ trans_choice(':count person not fully paid this month|:count people not fully paid this month', $payroll['unpaidPeople']) }}</p>
        </div>
    </section>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 rounded-2xl bg-white p-2 ring-1 ring-zinc-200/70 lg:flex-row lg:items-center lg:justify-between dark:bg-zinc-950 dark:ring-white/5">
        <nav class="flex gap-1 overflow-x-auto" aria-label="{{ __('Filter by status') }}">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setStatus('{{ $key }}')" @class([
                    'flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white shadow-md shadow-brand-500/25' => $status === $key,
                    'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' => $status !== $key,
                ]) @if ($status === $key) aria-current="true" @endif>
                    {{ $label }}
                    <span @class(['rounded-md px-1.5 py-0.5 text-xs tabular-nums', 'bg-white/20' => $status === $key, 'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $status !== $key])>{{ $this->counts[$key] }}</span>
                </button>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search people')" clearable class="min-w-0 flex-1 lg:w-72 lg:flex-none" />
            <div class="flex shrink-0 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                @foreach (['grid' => 'squares-2x2', 'list' => 'list-bullet'] as $mode => $icon)
                    <button type="button" wire:click="$set('view', '{{ $mode }}')" aria-label="{{ $mode === 'grid' ? __('Card view') : __('List view') }}" @class([
                        'flex size-8 items-center justify-center rounded-lg transition',
                        'bg-white text-brand-600 shadow-sm dark:bg-zinc-800 dark:text-white' => $view === $mode,
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
            <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.identification class="size-7" /></span>
            @if ($search !== '' || $status !== 'all')
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Nobody matches these filters') }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('Try another search or status.') }}</p>
                </div>
                <flux:button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            @else
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('No employees yet') }}</h2>
                    <p class="mt-1 max-w-xs text-sm text-zinc-500">{{ __('Add your team to track roles, salaries and join dates in one place.') }}</p>
                </div>
                <flux:button variant="primary" size="sm" icon="user-plus" wire:click="create">{{ __('Add employee') }}</flux:button>
            @endif
        </div>
    @elseif ($view === 'grid')
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 min-[1800px]:grid-cols-5">
            @foreach ($this->employees as $e)
                @php
                    $d = $depts[$e->department] ?? $depts['development'];
                    $meta = $e->departmentMeta();
                    $pay = $e->status === 'inactive' ? null : $e->salaryProgress();
                    $full = $pay && $pay['left'] <= 0;
                @endphp
                <article wire:key="emp-{{ $e->id }}" @class([
                    'group flex flex-col gap-4 rounded-2xl bg-white p-5 ring-1 ring-zinc-200/70 transition duration-300 hover:-translate-y-0.5 hover:shadow-[0_20px_44px_-26px_rgb(49_81_254/0.4)] hover:ring-brand-200 dark:bg-zinc-950 dark:ring-white/10 dark:hover:ring-brand-500/40',
                    'opacity-70 hover:opacity-100' => $e->status === 'inactive',
                ])>
                    {{-- Identity --}}
                    <div class="flex items-start gap-3.5">
                        <span class="{{ $d['avatar'] }} relative flex size-14 shrink-0 items-center justify-center rounded-2xl text-lg font-semibold">
                            {{ $e->initials() }}
                            <span class="{{ $statusDot[$e->status] }} absolute -end-1 -bottom-1 size-3.5 rounded-full ring-[3px] ring-white dark:ring-zinc-950" title="{{ __(Employee::STATUSES[$e->status]) }}"></span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate leading-snug font-semibold text-zinc-900 dark:text-white"><a href="{{ route('employees.show', $e) }}" wire:navigate class="hover:text-brand-600 dark:hover:text-brand-300">{{ $e->name }}</a></h3>
                            <p class="truncate text-[0.8125rem] text-zinc-500 dark:text-zinc-400">{{ $e->designation ?? __('No role set') }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span class="{{ $d['chip'] }} inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium">
                                    <flux:icon :icon="$meta['icon']" variant="micro" class="size-3" />
                                    {{ __($meta['label']) }}
                                </span>
                                @if ($e->status !== 'active')
                                    <span class="{{ $statusText[$e->status] }} text-[0.6875rem] font-medium">{{ __(Employee::STATUSES[$e->status]) }}</span>
                                @endif
                            </div>
                        </div>
                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" class="-me-2 -mt-1" :aria-label="__('Actions for :name', ['name' => $e->name])" />
                            <flux:menu>
                                <flux:menu.item icon="user" :href="route('employees.show', $e)" wire:navigate>{{ __('View profile') }}</flux:menu.item>
                                <flux:menu.item icon="banknotes" wire:click="openPay({{ $e->id }})">{{ __('Pay salary') }}</flux:menu.item>
                                <flux:menu.item icon="clock" wire:click="openHistory({{ $e->id }})">{{ __('Salary history') }}</flux:menu.item>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $e->id }})">{{ __('Edit') }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $e->id }})">{{ __('Remove') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    {{-- Salary --}}
                    <div class="rounded-xl bg-zinc-50/80 p-3.5 ring-1 ring-zinc-100 dark:bg-white/[0.03] dark:ring-white/5">
                        @if ($e->status === 'inactive')
                            <p class="text-xs text-zinc-400">{{ __('Not on payroll') }}</p>
                        @elseif (! $pay)
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs text-zinc-500">{{ __('Salary not set') }}</p>
                                <button type="button" wire:click="edit({{ $e->id }})" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('Add salary') }}</button>
                            </div>
                        @else
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span class="text-zinc-500">{{ __(':month salary', ['month' => $periodLabel]) }}</span>
                                @if ($full)
                                    <span class="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400"><flux:icon.check-circle variant="micro" class="size-3.5" />{{ __('Paid') }}</span>
                                @elseif ($pay['paid'] > 0)
                                    <span class="font-medium text-brand-600 tabular-nums dark:text-brand-300">{{ $pay['percent'] }}%</span>
                                @else
                                    <span class="font-medium text-amber-600 dark:text-amber-400">{{ __('Not paid yet') }}</span>
                                @endif
                            </div>
                            <div class="mt-1.5 flex items-end justify-between gap-2">
                                <p class="tabular-nums">
                                    <span class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $money($pay['paid']) }}</span>
                                    <span class="text-xs text-zinc-400">/ {{ number_format($pay['salary']) }}</span>
                                </p>
                                @unless ($full)
                                    <flux:button size="xs" variant="primary" icon="banknotes" wire:click="openPay({{ $e->id }})">{{ __('Pay') }}</flux:button>
                                @endunless
                            </div>
                            <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-white ring-1 ring-zinc-200/70 dark:bg-white/5 dark:ring-white/10" role="progressbar" aria-valuenow="{{ $pay['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Salary paid') }}">
                                <div @class(['h-full rounded-full transition-[width] duration-700', 'bg-emerald-500' => $full, 'bg-brand-500' => ! $full]) style="width: {{ $pay['percent'] }}%"></div>
                            </div>
                        @endif
                    </div>

                    {{-- Footer --}}
                    <div class="mt-auto flex items-center justify-between gap-2">
                        <div class="-ms-1.5 flex items-center">
                            @if ($e->phone)
                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $e->phone) }}" class="{{ $contact }}" title="{{ __('Call :phone', ['phone' => $e->phone]) }}"><flux:icon.phone variant="micro" class="size-4" /><span class="sr-only">{{ __('Call') }}</span></a>
                                <a href="https://wa.me/{{ $e->whatsappNumber() }}" target="_blank" rel="noopener noreferrer" class="{{ $contact }} hover:text-[#25D366]!" title="WhatsApp"><x-social-icon name="whatsapp" class="size-4" /><span class="sr-only">WhatsApp</span></a>
                            @endif
                            @if ($e->email)
                                <a href="mailto:{{ $e->email }}" class="{{ $contact }}" title="{{ $e->email }}"><flux:icon.envelope variant="micro" class="size-4" /><span class="sr-only">{{ __('Email') }}</span></a>
                            @endif
                        </div>
                        @if ($e->joined_on)
                            <p class="truncate text-xs text-zinc-400" title="{{ $e->joined_on->format('j M Y') }}">
                                {{ __('Joined') }} {{ $e->joined_on->toNepali() }} · <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ $e->tenure() }}</span>
                            </p>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="{{ $panel }} overflow-x-auto">
            <table class="w-full min-w-[62rem] text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 text-xs text-zinc-500 dark:border-white/5">
                        <th class="px-5 py-3 text-start font-medium">{{ __('Employee') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Department') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Phone') }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __(':month salary', ['month' => $periodLabel]) }}</th>
                        <th class="px-5 py-3 text-start font-medium">{{ __('Joined') }}</th>
                        <th class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($this->employees as $e)
                        @php
                            $d = $depts[$e->department] ?? $depts['development'];
                            $meta = $e->departmentMeta();
                            $pay = $e->status === 'inactive' ? null : $e->salaryProgress();
                        @endphp
                        <tr wire:key="emp-row-{{ $e->id }}" @class(['transition hover:bg-zinc-50/70 dark:hover:bg-white/[0.02]', 'opacity-60' => $e->status === 'inactive'])>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="{{ $d['avatar'] }} relative flex size-9 shrink-0 items-center justify-center rounded-xl text-xs font-semibold">
                                        {{ $e->initials() }}
                                        <span class="{{ $statusDot[$e->status] }} absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-zinc-950"></span>
                                    </span>
                                    <div class="min-w-0">
                                        <a href="{{ route('employees.show', $e) }}" wire:navigate class="block truncate font-medium text-zinc-900 hover:text-brand-600 dark:text-white">{{ $e->name }}</a>
                                        <p class="truncate text-xs text-zinc-500">{{ $e->designation ?? __('No role set') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $d['chip'] }} inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium">
                                    <flux:icon :icon="$meta['icon']" variant="micro" class="size-3.5" />
                                    {{ __($meta['label']) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-zinc-600 tabular-nums dark:text-zinc-300">{{ $e->phone ?? '-' }}</td>
                            <td class="px-5 py-3">
                                @if ($pay)
                                    <div class="flex w-48 flex-col gap-1.5">
                                        <p class="text-xs tabular-nums"><span class="font-semibold text-zinc-900 dark:text-white">{{ $money($pay['paid']) }}</span> <span class="text-zinc-400">/ {{ number_format($pay['salary']) }}</span></p>
                                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div @class(['h-full rounded-full', 'bg-emerald-500' => $pay['left'] <= 0, 'bg-brand-500' => $pay['left'] > 0]) style="width: {{ $pay['percent'] }}%"></div></div>
                                    </div>
                                @else
                                    <span class="text-xs text-zinc-400">{{ $e->status === 'inactive' ? __('Not on payroll') : __('Salary not set') }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <p class="text-zinc-700 dark:text-zinc-200" title="{{ $e->joined_on?->format('j M Y') }}">{{ $e->joined_on?->toNepali() ?: '-' }}</p>
                                <p class="text-xs text-zinc-400">{{ $e->tenure() }}</p>
                            </td>
                            <td class="px-5 py-3 text-end whitespace-nowrap">
                                @if ($pay && $pay['left'] > 0)
                                    <flux:button size="xs" icon="banknotes" wire:click="openPay({{ $e->id }})">{{ __('Pay') }}</flux:button>
                                @endif
                                <flux:dropdown align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('Actions for :name', ['name' => $e->name])" />
                                    <flux:menu>
                                        <flux:menu.item icon="user" :href="route('employees.show', $e)" wire:navigate>{{ __('View profile') }}</flux:menu.item>
                                <flux:menu.item icon="banknotes" wire:click="openPay({{ $e->id }})">{{ __('Pay salary') }}</flux:menu.item>
                                        <flux:menu.item icon="clock" wire:click="openHistory({{ $e->id }})">{{ __('Salary history') }}</flux:menu.item>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $e->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.separator />
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
                <flux:text class="mt-1">{{ __('Role, team, salary and contact details.') }}</flux:text>
            </div>
            @php
                $deptOptions = Employee::departments();
                $deptCounts = Employee::selectRaw('department, count(*) as total')->groupBy('department')->pluck('total', 'department');
            @endphp
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Full name')" required autofocus />
                <flux:select wire:model="employeeStatus" :label="__('Status')">
                    @foreach (Employee::STATUSES as $key => $label)
                        <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="designation" :label="__('Role')" :placeholder="__('e.g. Graphic Designer')" />

                {{-- Department --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false">
                    <span class="mb-2 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Department') }}</span>
                    <button type="button" @click="open = ! open" class="flex h-10 w-full items-center gap-2.5 rounded-lg bg-white px-3 text-start shadow-xs ring-1 ring-zinc-200 transition hover:ring-zinc-300 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:ring-white/10" :aria-expanded="open" aria-haspopup="listbox" data-test="department-button">
                        @foreach ($deptOptions as $key => $d)
                            <span x-show="$wire.dept === @js($key)" class="flex min-w-0 items-center gap-2.5" x-cloak>
                                <span class="{{ $depts[$key]['avatar'] ?? $depts['development']['avatar'] }} flex size-6 shrink-0 items-center justify-center rounded-md"><flux:icon :icon="$d['icon']" variant="micro" class="size-3.5" /></span>
                                <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ __($d['label']) }}</span>
                            </span>
                        @endforeach
                        <flux:icon.chevron-up-down variant="mini" class="ms-auto size-4 shrink-0 text-zinc-400" />
                    </button>
                    <div x-show="open" x-transition.origin.top x-cloak class="absolute inset-x-0 top-full z-40 mt-1.5 overflow-hidden rounded-xl bg-white shadow-[0_20px_50px_-12px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10" role="listbox">
                        <div class="max-h-72 overflow-y-auto p-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                            @foreach ($deptOptions as $key => $d)
                                <div wire:key="dept-opt-{{ $key }}" class="group flex items-center rounded-lg transition hover:bg-zinc-50 dark:hover:bg-white/5" :class="$wire.dept === @js($key) && 'bg-brand-50/70 dark:bg-brand-500/10'">
                                    <button type="button" role="option" @click="$wire.dept = @js($key); open = false" class="flex min-w-0 flex-1 items-center gap-2.5 px-2 py-1.5 text-start">
                                        <span class="{{ $depts[$key]['avatar'] ?? $depts['development']['avatar'] }} flex size-7 shrink-0 items-center justify-center rounded-lg"><flux:icon :icon="$d['icon']" variant="micro" class="size-3.5" /></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __($d['label']) }}</span>
                                            <span class="block text-[0.6875rem] text-zinc-400">{{ trans_choice(':count person|:count people', (int) ($deptCounts[$key] ?? 0)) }}</span>
                                        </span>
                                        <flux:icon.check variant="mini" class="size-4 text-brand-600" x-show="$wire.dept === @js($key)" />
                                    </button>
                                    <button type="button" wire:click="deleteDepartment('{{ $key }}')" wire:confirm="{{ __('Delete the department “:name”?', ['name' => __($d['label'])]) }}" class="me-1 rounded-md p-1.5 text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-500 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete :name', ['name' => __($d['label'])]) }}">
                                        <flux:icon.trash variant="micro" class="size-3.5" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-1.5 border-t border-zinc-100 bg-zinc-50/70 p-2 dark:border-white/5 dark:bg-white/[0.02]">
                            <input wire:model="newDepartment" type="text" maxlength="40" placeholder="{{ __('New department…') }}" @keydown.enter.prevent="$wire.addDepartment()" class="h-8 min-w-0 flex-1 rounded-lg border-0 bg-white px-2.5 text-sm ring-1 ring-zinc-200 placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:text-white dark:ring-white/10" data-test="new-department-input" />
                            <button type="button" wire:click="addDepartment" class="inline-flex h-8 items-center gap-1 rounded-lg bg-brand-500 px-2.5 text-xs font-semibold text-white transition hover:bg-brand-600"><flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add') }}</button>
                        </div>
                        <flux:error name="newDepartment" class="px-3 pb-2" />
                    </div>
                    <flux:error name="dept" />
                </div>

                <flux:input wire:model="phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" />
                <flux:input wire:model="salary" type="number" min="0" step="1" :label="__('Monthly salary (:currency)', ['currency' => config('app.currency')])" />
                <flux:input wire:model="email" type="email" :label="__('Email')" />
                <x-nepali-date wire:model="joinedOn" :label="__('Joined on')" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-employee-button">{{ $editingId ? __('Save changes') : __('Add employee') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Pay salary --}}
    <flux:modal name="pay-salary" class="w-full max-w-md">
        <form wire:submit="savePay" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Pay salary') }}</flux:heading>
                <flux:text class="mt-1">{{ $payEmployeeId ? \App\Models\Employee::find($payEmployeeId)?->name : '' }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="payPeriod" :label="__('For month')">
                    @foreach (NepaliDate::recentPeriods(6) as $p)
                        <flux:select.option :value="$p">{{ NepaliDate::periodLabel($p) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="payAmount" type="number" min="0" step="1" :label="__('Amount (:currency)', ['currency' => config('app.currency')])" required />
                <x-nepali-date wire:model="payDate" :label="__('Paid on')" class="sm:col-span-2" />
                <flux:input wire:model="payNote" :label="__('Note')" :placeholder="__('e.g. Advance, bonus, bank transfer')" class="sm:col-span-2" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-salary-button">{{ __('Save payment') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Salary history --}}
    <flux:modal name="salary-history" class="w-full max-w-lg">
        @if ($emp = $this->historyEmployee)
            <div class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Salary history') }}</flux:heading>
                    <flux:text class="mt-1">{{ $emp->name }}{{ $emp->salary ? ' · '.$money($emp->salary).__('/mo') : '' }}</flux:text>
                </div>
                @forelse ($emp->salaryPayments->groupBy('period') as $period => $payments)
                    <div class="rounded-xl ring-1 ring-zinc-200/70 dark:ring-white/10">
                        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-white/5">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ NepaliDate::periodLabel($period) }}</p>
                            <p class="text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money($payments->sum('amount')) }}</p>
                        </div>
                        @foreach ($payments as $sp)
                            <div wire:key="sp-{{ $sp->id }}" class="flex items-center gap-3 px-4 py-2 text-sm">
                                <span class="text-zinc-500" title="{{ $sp->paid_on->format('j M Y') }}">{{ $sp->paid_on->toNepali() }}</span>
                                <span class="min-w-0 flex-1 truncate text-xs text-zinc-400">{{ $sp->note }}</span>
                                <span class="font-medium text-zinc-700 tabular-nums dark:text-zinc-200">{{ $money($sp->amount) }}</span>
                                <button type="button" wire:click="deleteSalaryPayment({{ $sp->id }})" wire:confirm="{{ __('Delete this salary payment?') }}" class="text-zinc-300 hover:text-rose-500" aria-label="{{ __('Delete payment') }}">
                                    <flux:icon.trash variant="micro" class="size-4" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-zinc-500">{{ __('No salary paid yet.') }}</p>
                @endforelse
            </div>
        @endif
    </flux:modal>

    {{-- Remove --}}
    <flux:modal name="employee-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Remove this employee?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Their details and salary history will be deleted. To keep their history, set their status to Inactive instead.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
