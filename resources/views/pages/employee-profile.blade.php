<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Employee')] class extends Component {
    public Employee $employee;

    public string $name = '';
    public string $designation = '';
    public string $dept = 'development';
    public string $email = '';
    public string $phone = '';
    public string $employeeStatus = 'active';
    public string $salary = '';
    public string $joinedOn = '';

    public string $payPeriod = '';
    public string $payAmount = '';
    public string $payDate = '';
    public string $payNote = '';

    #[Computed]
    public function period(): string
    {
        return NepaliDate::period(today());
    }

    #[Computed]
    public function payments()
    {
        return $this->employee->salaryPayments()->orderByDesc('period')->orderByDesc('paid_on')->orderByDesc('id')->get();
    }

    /** Salary paid for the current BS month. */
    #[Computed]
    public function thisMonth(): ?array
    {
        $e = $this->employee;
        $e->paid_in_period = $this->payments->where('period', $this->period)->sum('amount');

        return $e->status === 'inactive' ? null : $e->salaryProgress();
    }

    /**
     * The last 12 BS months (oldest first), from the joining month on, with what was paid in each.
     *
     * @return list<array{period: string, paid: float, percent: int}>
     */
    #[Computed]
    public function months(): array
    {
        $byPeriod = $this->payments->groupBy('period')->map->sum('amount');
        $joined = $this->employee->joined_on ? NepaliDate::period($this->employee->joined_on) : null;
        $salary = (float) $this->employee->salary;

        return collect(NepaliDate::recentPeriods(12))
            ->reverse()
            ->filter(fn ($p) => ! $joined || $p >= $joined)
            ->map(fn ($p) => [
                'period' => $p,
                'paid' => $paid = (float) ($byPeriod[$p] ?? 0),
                'percent' => $salary > 0 ? (int) min(100, round($paid / $salary * 100)) : ($paid > 0 ? 100 : 0),
            ])
            ->values()
            ->all();
    }

    // Edit

    public function edit(): void
    {
        $e = $this->employee;

        $this->name = $e->name;
        $this->designation = (string) $e->designation;
        $this->dept = $e->department;
        $this->email = (string) $e->email;
        $this->phone = (string) $e->phone;
        $this->employeeStatus = $e->status;
        $this->salary = $e->salary === null ? '' : rtrim(rtrim((string) $e->salary, '0'), '.');
        $this->joinedOn = (string) $e->joined_on?->toDateString();
        $this->resetErrorBag();

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
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'joinedOn' => ['nullable', 'date'],
        ]);

        $this->employee->update([
            'name' => $data['name'],
            'designation' => $data['designation'] ?: null,
            'department' => $data['dept'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'status' => $data['employeeStatus'],
            'salary' => $data['salary'] === '' || $data['salary'] === null ? null : $data['salary'],
            'joined_on' => $data['joinedOn'] ?: null,
        ]);

        unset($this->thisMonth, $this->months);
        Flux::modal('employee-form')->close();
        Flux::toast(variant: 'success', text: __('Employee updated.'));
    }

    public function delete(): void
    {
        $this->employee->delete();
        Flux::toast(text: __('Employee removed.'));
        $this->redirectRoute('employees.index', navigate: true);
    }

    // Salary

    public function openPay(): void
    {
        $this->payPeriod = $this->period;
        $this->payAmount = (string) ($this->thisMonth['left'] ?? '');
        $this->payDate = today()->toDateString();
        $this->payNote = '';
        $this->resetErrorBag();

        Flux::modal('pay-salary')->show();
    }

    public function savePay(): void
    {
        $data = $this->validate([
            'payPeriod' => ['required', Rule::in(NepaliDate::recentPeriods(6))],
            'payAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payDate' => ['required', 'date'],
            'payNote' => ['nullable', 'string', 'max:255'],
        ]);

        $this->employee->salaryPayments()->create([
            'period' => $data['payPeriod'],
            'amount' => $data['payAmount'],
            'paid_on' => $data['payDate'],
            'note' => $data['payNote'] ?: null,
        ]);

        unset($this->payments, $this->thisMonth, $this->months);
        Flux::modal('pay-salary')->close();
        Flux::toast(variant: 'success', text: __('Salary payment saved.'));
    }

    public function deleteSalaryPayment(int $id): void
    {
        SalaryPayment::where('employee_id', $this->employee->id)->findOrFail($id)->delete();
        unset($this->payments, $this->thisMonth, $this->months);
        Flux::toast(text: __('Salary payment deleted.'));
    }
}; ?>

@php
    $e = $employee;
    $money = fn ($v) => Client::money($v);
    $periodLabel = NepaliDate::periodLabel($this->period);
    $meta = Employee::DEPARTMENTS[$e->department] ?? Employee::DEPARTMENTS['development'];
    $looks = [
        'design' => ['cover' => 'from-rose-300 via-pink-400 to-rose-500', 'avatar' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/25 dark:text-rose-100', 'chip' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
        'development' => ['cover' => 'from-brand-400 via-brand-500 to-indigo-600', 'avatar' => 'bg-brand-100 text-brand-700 dark:bg-brand-500/30 dark:text-white', 'chip' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200'],
        'marketing' => ['cover' => 'from-amber-300 via-orange-400 to-orange-500', 'avatar' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/25 dark:text-amber-100', 'chip' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'],
        'content' => ['cover' => 'from-emerald-300 via-emerald-400 to-teal-500', 'avatar' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/25 dark:text-emerald-100', 'chip' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        'sales' => ['cover' => 'from-sky-300 via-sky-400 to-blue-500', 'avatar' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/25 dark:text-sky-100', 'chip' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        'management' => ['cover' => 'from-violet-300 via-violet-400 to-purple-500', 'avatar' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/25 dark:text-violet-100', 'chip' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
        'hr' => ['cover' => 'from-teal-300 via-teal-400 to-cyan-500', 'avatar' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/25 dark:text-teal-100', 'chip' => 'bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300'],
    ];
    $look = $looks[$e->department] ?? $looks['development'];
    $statusDot = ['active' => 'bg-emerald-500', 'on_leave' => 'bg-amber-400', 'inactive' => 'bg-zinc-300'];
    $statusPill = [
        'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300',
        'on_leave' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300',
        'inactive' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/15 dark:bg-white/10 dark:text-zinc-300',
    ];
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $iconTile = 'flex size-9 items-center justify-center rounded-lg';
    $pay = $this->thisMonth;
    $full = $pay && $pay['left'] <= 0;
    $payments = $this->payments;
    $months = $this->months;
    $paidMonths = collect($months)->where('percent', '>=', 100)->count();
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Breadcrumb --}}
    <nav class="flex items-center gap-1.5 text-sm" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('employees.index') }}" wire:navigate class="font-medium text-zinc-500 transition hover:text-brand-600 dark:text-zinc-400">{{ __('Employees') }}</a>
        <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-300" />
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-100">{{ $e->name }}</span>
    </nav>

    {{-- Header --}}
    <header class="{{ $panel }} overflow-hidden">
        <div class="{{ $look['cover'] }} relative h-28 bg-linear-to-r text-white sm:h-32">
            <div class="absolute inset-0 opacity-25 [background-image:radial-gradient(currentColor_1px,transparent_1px)] [background-size:18px_18px] [mask-image:linear-gradient(to_left,black,transparent_70%)]"></div>
            <flux:icon :icon="$meta['icon']" class="absolute -right-4 -bottom-8 size-40 opacity-20" />
        </div>

        <div class="flex flex-col gap-4 px-5 pb-5 sm:px-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex min-w-0 items-end gap-4">
                    <span class="relative -mt-12 shrink-0">
                        <span class="{{ $look['avatar'] }} flex size-24 items-center justify-center rounded-2xl text-3xl font-semibold shadow-lg ring-4 ring-white dark:ring-zinc-950">{{ $e->initials() }}</span>
                        <span class="{{ $statusDot[$e->status] }} absolute -end-1 -bottom-1 size-4.5 rounded-full ring-[3px] ring-white dark:ring-zinc-950" title="{{ __(Employee::STATUSES[$e->status]) }}"></span>
                    </span>
                    <div class="min-w-0 pt-3">
                        <h1 class="truncate text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-[1.75rem] dark:text-white">{{ $e->name }}</h1>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                            <span class="inline-flex items-center gap-1.5"><flux:icon.briefcase variant="micro" class="size-3.5 opacity-70" />{{ $e->designation ?? __('No role set') }}</span>
                            @if ($e->phone)
                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $e->phone) }}" class="inline-flex items-center gap-1.5 tabular-nums hover:text-brand-600"><flux:icon.phone variant="micro" class="size-3.5 opacity-70" />{{ $e->phone }}</a>
                            @endif
                            @if ($e->email)
                                <a href="mailto:{{ $e->email }}" class="inline-flex min-w-0 items-center gap-1.5 hover:text-brand-600"><flux:icon.envelope variant="micro" class="size-3.5 opacity-70" /><span class="truncate">{{ $e->email }}</span></a>
                            @endif
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($e->phone)
                        <flux:button icon="phone" :href="'tel:'.preg_replace('/[^\d+]/', '', $e->phone)">{{ __('Call') }}</flux:button>
                        <a href="https://wa.me/{{ $e->whatsappNumber() }}" target="_blank" rel="noopener noreferrer" class="flex size-10 items-center justify-center rounded-lg bg-white text-zinc-600 shadow-xs ring-1 ring-zinc-200 transition hover:text-[#25D366] dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10" title="WhatsApp">
                            <x-social-icon name="whatsapp" class="size-4.5" /><span class="sr-only">WhatsApp</span>
                        </a>
                    @endif
                    <flux:button icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
                    @if ($pay && ! $full)
                        <flux:button variant="primary" icon="banknotes" wire:click="openPay" class="shadow-lg shadow-brand-500/25">{{ __('Pay salary') }}</flux:button>
                    @endif
                    <flux:dropdown align="end">
                        <flux:button icon="ellipsis-horizontal" :aria-label="__('More actions')" />
                        <flux:menu>
                            <flux:menu.item icon="banknotes" wire:click="openPay">{{ __('Record a payment') }}</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" x-on:click="$flux.modal('employee-delete').show()">{{ __('Remove employee') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="{{ $statusPill[$e->status] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium ring-1 ring-inset">
                    <span class="{{ $statusDot[$e->status] }} size-1.5 rounded-full"></span>{{ __(Employee::STATUSES[$e->status]) }}
                </span>
                <span class="{{ $look['chip'] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium">
                    <flux:icon :icon="$meta['icon']" variant="micro" class="size-3.5" />{{ __($meta['label']) }}
                </span>
                @if ($e->joined_on)
                    <span class="text-zinc-400" title="{{ $e->joined_on->format('j M Y') }}">· {{ __('Joined :date', ['date' => $e->joined_on->toNepali()]) }}</span>
                @endif
            </div>
        </div>
    </header>

    {{-- Stats --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5">
            <div class="absolute -top-12 -right-12 size-32 rounded-full bg-brand-100/70 dark:bg-brand-500/10"></div>
            <div class="relative flex items-center gap-3">
                <span class="{{ $iconTile }} bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.wallet variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Monthly salary') }}</p>
            </div>
            <p class="relative text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $e->salary ? $money($e->salary) : __('Not set') }}</p>
            <p class="relative mt-auto text-xs text-zinc-500">{{ $e->salary ? __(':amount a year', ['amount' => $money($e->salary * 12)]) : __('Add it with Edit') }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span @class([$iconTile, 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => $full, 'bg-amber-100 text-amber-600 dark:bg-amber-400/15 dark:text-amber-300' => ! $full])><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __(':month salary', ['month' => $periodLabel]) }}</p>
                @if ($pay)
                    <span @class(['text-xs font-semibold tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $full, 'text-amber-600 dark:text-amber-400' => ! $full])>{{ $pay['percent'] }}%</span>
                @endif
            </div>
            @if ($pay)
                <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($pay['paid']) }}</p>
                <div class="mt-auto flex flex-col gap-1.5">
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div @class(['h-full rounded-full transition-[width] duration-700', 'bg-emerald-500' => $full, 'bg-amber-400' => ! $full]) style="width: {{ $pay['percent'] }}%"></div></div>
                    <p class="text-xs text-zinc-500">{{ $full ? __('Fully paid') : __(':amount still to pay', ['amount' => $money($pay['left'])]) }}</p>
                </div>
            @else
                <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-300 dark:text-zinc-600">—</p>
                <p class="mt-auto text-xs text-zinc-500">{{ $e->status === 'inactive' ? __('Not on payroll') : __('Salary not set') }}</p>
            @endif
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="{{ $iconTile }} bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300"><flux:icon.chart-bar variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Paid in total') }}</p>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($payments->sum('amount')) }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ trans_choice(':count payment|:count payments', $payments->count()) }} · {{ trans_choice(':count month fully paid|:count months fully paid', $paidMonths) }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="{{ $iconTile }} bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300"><flux:icon.calendar-days variant="mini" class="size-4.5" /></span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('With the team') }}</p>
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $e->tenure() ?? '—' }}</p>
            <p class="mt-auto text-xs text-zinc-500">{{ $e->joined_on ? __('Since :date', ['date' => NepaliDate::monthYear($e->joined_on)]) : __('Joining date not set') }}</p>
        </div>
    </section>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex min-w-0 flex-col gap-6">
            {{-- Salary by month --}}
            <section class="{{ $panel }}">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <div>
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Salary by month') }}</h2>
                        <p class="text-xs text-zinc-500">{{ __('Last :count months', ['count' => count($months)]) }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-xs text-zinc-500">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500"></span>{{ __('Paid') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-400"></span>{{ __('Part paid') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-zinc-200 dark:bg-white/15"></span>{{ __('Not paid') }}</span>
                    </div>
                </div>
                @if ($months === [])
                    <p class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('Their first salary month has not started yet.') }}</p>
                @else
                    <div class="flex h-56 items-end gap-2 px-5 pt-6 pb-4 sm:gap-3">
                        @foreach ($months as $m)
                            @php
                                $current = $m['period'] === $this->period;
                                [$y, $mo] = explode('-', $m['period']);
                            @endphp
                            <div class="group flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2" title="{{ NepaliDate::periodLabel($m['period']) }}: {{ $money($m['paid']) }}">
                                <span class="text-[0.625rem] font-semibold text-zinc-500 tabular-nums opacity-0 transition group-hover:opacity-100">{{ $m['percent'] }}%</span>
                                <div class="relative flex w-full max-w-10 flex-1 items-end overflow-hidden rounded-lg bg-zinc-100 dark:bg-white/5">
                                    <div @class([
                                        'w-full rounded-lg transition-[height] duration-700',
                                        'bg-linear-to-t from-emerald-500 to-emerald-400' => $m['percent'] >= 100,
                                        'bg-linear-to-t from-amber-400 to-amber-300' => $m['percent'] > 0 && $m['percent'] < 100,
                                    ]) style="height: {{ $m['percent'] }}%"></div>
                                </div>
                                <span @class(['text-[0.6875rem] whitespace-nowrap', 'font-semibold text-brand-600 dark:text-brand-300' => $current, 'text-zinc-400' => ! $current])>{{ mb_substr(NepaliDate::MONTHS[(int) $mo - 1], 0, 3) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- Payment history --}}
            <section class="{{ $panel }}">
                <div class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <div>
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Payment history') }}</h2>
                        <p class="text-xs text-zinc-500">{{ __('Every salary payment, newest first') }}</p>
                    </div>
                    <flux:button size="sm" icon="plus" wire:click="openPay">{{ __('Add payment') }}</flux:button>
                </div>
                @forelse ($payments->groupBy('period') as $period => $rows)
                    @php
                        $sum = (float) $rows->sum('amount');
                        $isFull = $e->salary && $sum >= (float) $e->salary;
                    @endphp
                    <div wire:key="period-{{ $period }}" class="border-b border-zinc-100 last:border-0 dark:border-white/5">
                        <div class="flex items-center gap-3 bg-zinc-50/70 px-5 py-2.5 dark:bg-white/[0.02]">
                            <p class="flex-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ NepaliDate::periodLabel($period) }}</p>
                            @if ($e->salary)
                                <span @class([
                                    'rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $isFull,
                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ! $isFull,
                                ])>{{ $isFull ? __('Paid') : __('Part paid') }}</span>
                            @endif
                            <p class="text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $money($sum) }}</p>
                        </div>
                        @foreach ($rows as $sp)
                            <div wire:key="sp-{{ $sp->id }}" class="group flex items-center gap-3 px-5 py-3 text-sm">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.arrow-up-right variant="micro" class="size-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-zinc-800 dark:text-zinc-100" title="{{ $sp->paid_on->format('j M Y') }}">{{ __('Paid :date', ['date' => $sp->paid_on->toNepali()]) }}</p>
                                    @if ($sp->note)
                                        <p class="truncate text-xs text-zinc-500">{{ $sp->note }}</p>
                                    @endif
                                </div>
                                <span class="font-semibold text-zinc-800 tabular-nums dark:text-zinc-100">{{ $money($sp->amount) }}</span>
                                <button type="button" wire:click="deleteSalaryPayment({{ $sp->id }})" wire:confirm="{{ __('Delete this salary payment?') }}" class="text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:text-rose-500 focus:opacity-100" aria-label="{{ __('Delete payment') }}">
                                    <flux:icon.trash variant="micro" class="size-4" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-3 px-6 py-12 text-center">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.banknotes class="size-6" /></span>
                        <p class="text-sm text-zinc-500">{{ __('No salary paid yet.') }}</p>
                        <flux:button size="sm" variant="primary" icon="banknotes" wire:click="openPay">{{ __('Record first payment') }}</flux:button>
                    </div>
                @endforelse
            </section>
        </div>

        {{-- Side --}}
        <aside class="flex flex-col gap-6">
            @if ($pay)
                <section @class([
                    'relative overflow-hidden rounded-xl p-5 ring-1',
                    'bg-linear-to-br from-emerald-50 to-white ring-emerald-200/80 dark:from-emerald-500/10 dark:to-zinc-950 dark:ring-emerald-500/20' => $full,
                    'bg-linear-to-br from-brand-50 to-white ring-brand-200/80 dark:from-brand-500/10 dark:to-zinc-950 dark:ring-brand-500/20' => ! $full,
                ])>
                    <p class="text-xs font-semibold tracking-wider text-zinc-500 uppercase">{{ __('This month') }}</p>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $periodLabel }}</p>
                    @if ($full)
                        <div class="mt-4 flex items-center gap-3">
                            <span class="flex size-10 items-center justify-center rounded-full bg-emerald-500 text-white shadow-md shadow-emerald-500/30"><flux:icon.check variant="mini" class="size-5" /></span>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Salary paid') }}</p>
                                <p class="text-xs text-zinc-500">{{ $money($pay['paid']) }}</p>
                            </div>
                        </div>
                    @else
                        <p class="mt-4 text-xs text-zinc-500">{{ __('Still to pay') }}</p>
                        <p class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $money($pay['left']) }}</p>
                        <flux:button variant="primary" icon="banknotes" class="mt-4 w-full" wire:click="openPay">{{ __('Pay :amount', ['amount' => $money($pay['left'])]) }}</flux:button>
                    @endif
                </section>
            @endif

            <section class="{{ $panel }}">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Details') }}</h2>
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
                </div>
                <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                    @foreach ([
                        ['briefcase', __('Role'), $e->designation],
                        [$meta['icon'], __('Department'), __($meta['label'])],
                        ['phone', __('Phone'), $e->phone],
                        ['envelope', __('Email'), $e->email],
                        ['calendar', __('Joined'), $e->joined_on?->toNepali()],
                        ['signal', __('Status'), __(Employee::STATUSES[$e->status])],
                    ] as [$icon, $label, $value])
                        <div class="flex items-center gap-3 px-5 py-3">
                            <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0 text-zinc-400" />
                            <dt class="w-24 shrink-0 text-zinc-500">{{ $label }}</dt>
                            <dd @class(['min-w-0 flex-1 truncate', 'font-medium text-zinc-800 dark:text-zinc-100' => $value, 'text-zinc-400' => ! $value])>{{ $value ?: __('Not set') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </aside>
    </div>

    {{-- Edit --}}
    <flux:modal name="employee-form" class="w-full max-w-lg">
        <form wire:submit="save" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Edit employee') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Role, team, salary and contact details.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Full name')" required class="sm:col-span-2" />
                <flux:input wire:model="designation" :label="__('Role')" :placeholder="__('e.g. Graphic Designer')" />
                <flux:select wire:model="dept" :label="__('Department')">
                    @foreach (Employee::DEPARTMENTS as $key => $d)
                        <flux:select.option :value="$key">{{ __($d['label']) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="salary" type="number" min="0" step="1" :label="__('Monthly salary (:currency)', ['currency' => config('app.currency')])" />
                <flux:select wire:model="employeeStatus" :label="__('Status')">
                    @foreach (Employee::STATUSES as $key => $label)
                        <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" />
                <flux:input wire:model="email" type="email" :label="__('Email')" />
                <x-nepali-date wire:model="joinedOn" :label="__('Joined on')" class="sm:col-span-2" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Pay salary --}}
    <flux:modal name="pay-salary" class="w-full max-w-md">
        <form wire:submit="savePay" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Pay salary') }}</flux:heading>
                <flux:text class="mt-1">{{ $e->name }}{{ $e->salary ? ' · '.$money($e->salary).__('/mo') : '' }}</flux:text>
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
                <flux:button type="submit" variant="primary">{{ __('Save payment') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove --}}
    <flux:modal name="employee-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Remove :name?', ['name' => $e->name]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('Their details and salary history will be deleted. To keep their history, set their status to Inactive instead.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
