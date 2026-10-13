<?php

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\SalaryPayment;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

new #[Title('Employee')] class extends Component {
    use WithFileUploads;

    public Employee $employee;

    public const TABS = ['overview', 'work', 'salary', 'documents', 'details'];

    /** Personal details edited from the Details tab. */
    public const DETAIL_FIELDS = [
        'date_of_birth', 'gender', 'blood_group', 'marital_status', 'father_name', 'mother_name', 'spouse_name',
        'permanent_address', 'current_address', 'district', 'province', 'emergency_name', 'emergency_relation', 'emergency_phone',
        'citizenship_no', 'pan_no', 'bank_name', 'bank_account_name', 'bank_account_no',
    ];

    /** @var array<string, string> */
    public array $info = [];

    /** New profile photo, saved as soon as it is picked. */
    public $photo = null;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /** BS month shown on the attendance calendar. */
    public int $calYear = 0;
    public int $calMonth = 0;

    public string $salaryDay = '';
    public string $contractStart = '';
    public string $contractEnd = '';

    public $docUpload;

    public string $leaveDate = '';
    public string $leaveReason = '';
    public string $docType = 'agreement';

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
    public string $payTitle = '';

    public string $payNote = '';

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
        [$this->calYear, $this->calMonth] = NepaliDate::fromAd(today());
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS) ? $tab : 'overview';
    }

    // Attendance calendar

    public function shiftMonth(int $by): void
    {
        $index = $this->calYear * 12 + $this->calMonth - 1 + max(-1, min(1, $by));
        [$this->calYear, $this->calMonth] = [intdiv($index, 12), $index % 12 + 1];
    }

    public function goToday(): void
    {
        [$this->calYear, $this->calMonth] = NepaliDate::fromAd(today());
    }

    /** Assigned work across all clients. */
    #[Computed]
    public function work()
    {
        return $this->employee->tasks()->with('client')->orderByRaw('completed_at is not null')->orderBy('due_date')->latest('tasks.id')->get();
    }

    /**
     * Weeks of the shown BS month (Sunday first). Each day: BS day, AD date, attendance and the work finished that day.
     */
    #[Computed]
    public function calendar(): array
    {
        $length = NepaliDate::monthLength($this->calYear, $this->calMonth);
        $first = NepaliDate::toAd($this->calYear, $this->calMonth, 1);
        $last = NepaliDate::toAd($this->calYear, $this->calMonth, $length);

        $marks = $this->employee->attendances()->whereBetween('date', [$first->toDateString(), $last->toDateString()])->get()
            ->reject(fn ($a) => $a->date->isHoliday())
            ->keyBy(fn ($a) => $a->date->toDateString());
        $doneByDay = $this->work->whereNotNull('completed_at')
            ->filter(fn ($t) => $t->completed_at->between($first, $last->copy()->endOfDay()))
            ->groupBy(fn ($t) => $t->completed_at->toDateString());

        $cells = array_fill(0, $first->dayOfWeek, null);
        for ($day = 1; $day <= $length; $day++) {
            $ad = $first->copy()->addDays($day - 1);
            $key = $ad->toDateString();
            $cells[] = ['day' => $day, 'ad' => $ad, 'status' => $marks[$key]->status ?? null, 'note' => $marks[$key]->note ?? null, 'in' => $marks[$key]->check_in ?? null, 'device' => ($marks[$key]->source ?? '') === 'device', 'done' => $doneByDay[$key] ?? collect()];
        }
        $cells = array_pad($cells, (int) ceil(count($cells) / 7) * 7, null);

        $counts = collect(Attendance::STATUSES)->map(fn ($v, $k) => $marks->where('status', $k)->count())->all();
        $worked = $counts['present'] + $counts['half_day'] * 0.5;
        $marked = array_sum($counts);

        return [
            'label' => NepaliDate::MONTHS[$this->calMonth - 1].' '.$this->calYear,
            'range' => $first->format('j M').' – '.$last->format('j M Y'),
            'weeks' => array_chunk($cells, 7),
            'counts' => $counts,
            'rate' => $marked ? (int) round($worked / $marked * 100) : null,
            'done' => $doneByDay->flatten()->count(),
        ];
    }

    /** All-time attendance counts. */
    #[Computed]
    public function attendanceTotals(): array
    {
        $counts = $this->employee->attendances()->get(['date', 'status'])->reject(fn ($a) => $a->date->isHoliday())->countBy('status');
        $totals = collect(Attendance::STATUSES)->map(fn ($v, $k) => (int) ($counts[$k] ?? 0))->all();
        $marked = array_sum($totals);

        return $totals + [
            'marked' => $marked,
            'rate' => $marked ? (int) round(($totals['present'] + $totals['half_day'] * 0.5) / $marked * 100) : null,
        ];
    }

    public function markDay(string $date, string $status): void
    {
        // The weekly holiday (Saturday unless changed in Settings).
        abort_if(Carbon::parse($date)->isHoliday(), 422);
        $day = Carbon::parse($date)->toDateString();

        if ($status === 'leave') {
            // Leave needs a reason: ask for it first.
            $this->leaveDate = $day;
            $this->leaveReason = (string) $this->employee->attendances()->whereDate('date', $day)->where('status', 'leave')->value('note');
            $this->resetErrorBag();
            Flux::modal('leave-reason')->show();

            return;
        }

        if ($status === '') {
            $this->employee->attendances()->whereDate('date', $day)->delete();
        } else {
            abort_unless(array_key_exists($status, Attendance::STATUSES), 422);
            $this->employee->attendances()->updateOrCreate(['date' => $day], ['status' => $status, 'note' => null, 'source' => 'manual']);
        }

        unset($this->calendar, $this->attendanceTotals);
    }

    public function saveLeave(): void
    {
        $data = $this->validate([
            'leaveDate' => ['required', 'date'],
            'leaveReason' => ['required', 'string', 'min:3', 'max:255'],
        ], attributes: ['leaveReason' => __('reason')]);
        abort_if(Carbon::parse($data['leaveDate'])->isHoliday(), 422);

        $this->employee->attendances()->updateOrCreate(['date' => Carbon::parse($data['leaveDate'])->toDateString()], ['status' => 'leave', 'note' => trim($data['leaveReason']), 'source' => 'manual']);

        unset($this->calendar, $this->attendanceTotals);
        $this->reset('leaveDate', 'leaveReason');
        Flux::modal('leave-reason')->close();
        Flux::toast(variant: 'success', text: __('Leave marked.'));
    }

    /** Marks every unmarked working day (not Saturday) of the shown month, up to today, as present. */
    public function markWorkdaysPresent(): void
    {
        $length = NepaliDate::monthLength($this->calYear, $this->calMonth);
        $count = 0;

        for ($day = 1; $day <= $length; $day++) {
            $ad = NepaliDate::toAd($this->calYear, $this->calMonth, $day);
            if ($ad->isAfter(today()) || $ad->isHoliday()) {
                continue;
            }
            $mark = $this->employee->attendances()->firstOrCreate(['date' => $ad->toDateString()], ['status' => 'present']);
            $count += $mark->wasRecentlyCreated ? 1 : 0;
        }

        unset($this->calendar, $this->attendanceTotals);
        Flux::toast(variant: 'success', text: trans_choice(':count day marked present.|:count days marked present.', $count));
    }

    // Documents

    #[Computed]
    public function documents()
    {
        return $this->employee->documents()->latest()->get();
    }

    public function uploadDocument(): void
    {
        $data = $this->validate([
            'docType' => ['required', Rule::in(array_keys(EmployeeDocument::TYPES))],
            'docUpload' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
        ], attributes: ['docUpload' => __('file')]);

        $this->employee->documents()->create([
            'type' => $data['docType'],
            'name' => $this->docUpload->getClientOriginalName(),
            'path' => $this->docUpload->store('employee-documents/'.$this->employee->id, 'local'),
            'size' => $this->docUpload->getSize(),
        ]);

        $this->reset('docUpload');
        unset($this->documents);
        Flux::toast(variant: 'success', text: __('Document uploaded.'));
    }

    public function deleteDocument(int $id): void
    {
        $this->employee->documents()->findOrFail($id)->delete();
        unset($this->documents);
    }

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
        $this->salaryDay = (string) $e->salary_day;
        $this->contractStart = (string) $e->contract_start?->toDateString();
        $this->contractEnd = (string) $e->contract_end?->toDateString();
        $this->resetErrorBag();

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
            'salaryDay' => ['nullable', 'integer', 'min:1', 'max:32'],
            'contractStart' => ['nullable', 'date'],
            'contractEnd' => array_filter(['nullable', 'date', $this->contractStart ? 'after_or_equal:contractStart' : null]),
        ], attributes: ['salaryDay' => __('salary day'), 'contractEnd' => __('agreement end')]);

        $this->employee->update([
            'name' => $data['name'],
            'designation' => $data['designation'] ?: null,
            'department' => $data['dept'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'status' => $data['employeeStatus'],
            'salary' => $data['salary'] === '' || $data['salary'] === null ? null : $data['salary'],
            'joined_on' => $data['joinedOn'] ?: null,
            'salary_day' => $data['salaryDay'] ?: null,
            'contract_start' => $data['contractStart'] ?: null,
            'contract_end' => $data['contractEnd'] ?: null,
        ]);

        unset($this->thisMonth, $this->months);
        Flux::modal('employee-form')->close();
        Flux::toast(variant: 'success', text: __('Employee updated.'));
    }

    public function updatedPhoto(): void
    {
        $this->validateOnly('photo', ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']], attributes: ['photo' => __('photo')]);

        $this->employee->avatar_path && Storage::disk('public')->delete($this->employee->avatar_path);
        $this->employee->update(['avatar_path' => $this->photo->store('employees/avatars', 'public')]);
        $this->reset('photo');
        Flux::toast(variant: 'success', text: __('Photo updated.'));
    }

    public function removePhoto(): void
    {
        $this->employee->avatar_path && Storage::disk('public')->delete($this->employee->avatar_path);
        $this->employee->update(['avatar_path' => null]);
        Flux::toast(text: __('Photo removed.'));
    }

    public function openDetails(): void
    {
        $this->info = collect(self::DETAIL_FIELDS)->mapWithKeys(fn ($f) => [
            $f => $f === 'date_of_birth' ? (string) $this->employee->date_of_birth?->toDateString() : (string) $this->employee->{$f},
        ])->all();
        $this->resetErrorBag();
        Flux::modal('employee-details')->show();
    }

    public function saveDetails(): void
    {
        $rules = collect(self::DETAIL_FIELDS)->mapWithKeys(fn ($f) => ["info.$f" => ['nullable', 'string', 'max:255']])->all();
        $rules['info.date_of_birth'] = ['nullable', 'date', 'before:today'];
        $rules['info.gender'] = ['nullable', Rule::in(['male', 'female', 'other'])];
        $rules['info.blood_group'] = ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])];
        $rules['info.marital_status'] = ['nullable', Rule::in(['single', 'married', 'other'])];
        $data = $this->validate($rules, attributes: ['info.date_of_birth' => __('date of birth')])['info'] ?? [];

        $this->employee->update(collect(self::DETAIL_FIELDS)->mapWithKeys(fn ($f) => [$f => filled($data[$f] ?? null) ? trim($data[$f]) : null])->all());

        Flux::modal('employee-details')->close();
        Flux::toast(variant: 'success', text: __('Details saved.'));
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
        // Start on this month, or the first later month not fully paid; suggest what is left.
        $this->payPeriod = SalaryPayment::firstOpenPeriod($this->employee->fullyPaidPeriods(), $this->period);
        $this->updatedPayPeriod();
        $this->payDate = today()->toDateString();
        $this->payTitle = '';
        $this->payNote = '';
        $this->resetErrorBag();

        Flux::modal('pay-salary')->show();
    }

    /** Picking a month fills in what is still owed for it. */
    public function updatedPayPeriod(): void
    {
        $left = $this->payPeriod ? $this->employee->salaryLeftFor($this->payPeriod) : null;
        $this->payAmount = $left ? (string) $left : '';
        $this->resetErrorBag(['payPeriod', 'payAmount']);
    }

    public function savePay(): void
    {
        $data = $this->validate([
            ...SalaryPayment::payRules($this->employee, $this->payPeriod),
            'payDate' => ['required', 'date'],
            'payTitle' => ['nullable', 'string', 'max:120'],
            'payNote' => ['nullable', 'string', 'max:255'],
        ], attributes: ['payPeriod' => __('month'), 'payAmount' => __('amount'), 'payDate' => __('paid date')]);

        $this->employee->salaryPayments()->create([
            'period' => $data['payPeriod'],
            'amount' => $data['payAmount'],
            'paid_on' => $data['payDate'],
            'title' => filled($data['payTitle']) ? trim($data['payTitle']) : null,
            'note' => $data['payNote'] ?: null,
        ]);

        unset($this->payments, $this->thisMonth, $this->months);
        Flux::modal('pay-salary')->close();
        Flux::toast(variant: 'success', text: __('Salary payment saved.'));
    }

    /** Salary payment waiting for delete confirmation. */
    public ?int $deletingPaymentId = null;

    public function confirmDeleteSalaryPayment(int $id): void
    {
        $this->deletingPaymentId = $id;
        Flux::modal('salary-payment-delete')->show();
    }

    public function deleteSalaryPayment(int $id): void
    {
        SalaryPayment::where('employee_id', $this->employee->id)->findOrFail($id)->delete();
        unset($this->payments, $this->thisMonth, $this->months);
        $this->deletingPaymentId = null;
        Flux::modal('salary-payment-delete')->close();
        Flux::toast(text: __('Salary payment deleted.'));
    }
}; ?>

@php
    $e = $employee;
    $money = fn ($v) => Client::money($v);
    $periodLabel = NepaliDate::periodLabel($this->period);
    $meta = $e->departmentMeta();
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
        <div class="{{ $look['cover'] }} relative h-28 bg-linear-to-r text-white sm:h-44">
            <div class="absolute inset-0 opacity-25 [background-image:radial-gradient(currentColor_1px,transparent_1px)] [background-size:18px_18px] [mask-image:linear-gradient(to_left,black,transparent_70%)]"></div>
            <flux:icon :icon="$meta['icon']" class="absolute -right-4 -bottom-8 size-40 opacity-20" />

            {{-- This month's salary --}}
            @if ($pay)
                @php
                    [$cy, $cm] = NepaliDate::fromAd(today());
                    $salaryDate = $e->salary_day ? NepaliDate::toAd($cy, $cm, min($e->salary_day, NepaliDate::monthLength($cy, $cm))) : null;
                    $salaryState = match (true) {
                        $full => 'paid',
                        $pay['paid'] > 0 => 'part',
                        $salaryDate && $salaryDate->isBefore(today()) => 'overdue',
                        default => 'due',
                    };
                    $stateLook = [
                        'paid' => ['label' => __('Paid'), 'icon' => 'check-circle', 'pill' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20', 'bar' => 'bg-emerald-500'],
                        'part' => ['label' => __('Part paid'), 'icon' => 'clock', 'pill' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', 'bar' => 'bg-amber-400'],
                        'due' => ['label' => __('Unpaid'), 'icon' => 'clock', 'pill' => 'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/10', 'bar' => 'bg-brand-500'],
                        'overdue' => ['label' => __('Overdue'), 'icon' => 'exclamation-circle', 'pill' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20', 'bar' => 'bg-rose-500'],
                    ][$salaryState];
                @endphp
                <div class="absolute top-1/2 right-6 hidden w-80 -translate-y-1/2 rounded-2xl bg-white p-4 text-zinc-900 shadow-[0_18px_40px_-18px_rgb(15_23_42/0.5)] ring-1 ring-black/5 sm:block dark:bg-zinc-900 dark:text-white dark:ring-white/10" data-test="salary-status-card">
                    <div class="flex items-center gap-2">
                        <span class="flex size-7 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.banknotes variant="micro" class="size-4" /></span>
                        <span class="text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __(':month salary', ['month' => $periodLabel]) }}</span>
                        <span class="{{ $stateLook['pill'] }} ms-auto inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-inset">
                            <flux:icon :icon="$stateLook['icon']" variant="micro" class="size-3" />{{ $stateLook['label'] }}
                        </span>
                    </div>
                    <div class="mt-2.5 flex items-end justify-between gap-3">
                        <div>
                            <p class="text-[0.6875rem] text-zinc-500">{{ $full ? __('Paid on') : __('Salary date') }}</p>
                            <p class="text-lg leading-tight font-bold tracking-tight">
                                @if ($full && $payments->where('period', $this->period)->isNotEmpty())
                                    {{ $payments->where('period', $this->period)->first()->paid_on->toNepali(withYear: false) }}
                                @elseif ($salaryDate)
                                    {{ $salaryDate->toNepali(withYear: false) }}
                                @else
                                    <button type="button" wire:click="edit" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('Set salary day') }}</button>
                                @endif
                            </p>
                        </div>
                        <p class="text-end text-sm tabular-nums">
                            <span class="font-bold">{{ $money($pay['paid']) }}</span>
                            <span class="text-zinc-400">/ {{ number_format($pay['salary']) }}</span>
                        </p>
                    </div>
                    <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="{{ $stateLook['bar'] }} h-full rounded-full transition-[width] duration-700" style="width: {{ $pay['percent'] }}%"></div></div>
                    @unless ($full)
                        <div class="mt-2.5 flex items-center justify-between gap-2">
                            <p @class(['text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $salaryDate && $salaryDate->isBefore(today()), 'text-zinc-500' => ! ($salaryDate && $salaryDate->isBefore(today()))])>
                                @if ($salaryDate && $salaryDate->isBefore(today()))
                                    {{ trans_choice(':count day late|:count days late', (int) $salaryDate->diffInDays(today())) }}
                                @elseif ($salaryDate)
                                    {{ $salaryDate->isToday() ? __('Due today') : trans_choice('Due tomorrow|Due in :count days', (int) today()->diffInDays($salaryDate)) }}
                                @else
                                    {{ __(':amount left', ['amount' => $money($pay['left'])]) }}
                                @endif
                            </p>
                            <button type="button" wire:click="openPay" class="inline-flex items-center gap-1 rounded-lg bg-brand-500 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-brand-600"><flux:icon.banknotes variant="micro" class="size-3.5" />{{ __('Pay :amount', ['amount' => $money($pay['left'])]) }}</button>
                        </div>
                    @endunless
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-4 px-5 pb-5 sm:px-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex min-w-0 items-end gap-4">
                    <label class="group/photo relative -mt-12 shrink-0 cursor-pointer" title="{{ __('Change photo') }}" data-test="employee-photo">
                        <input type="file" wire:model="photo" accept="image/png,image/jpeg,image/webp" class="sr-only">
                        <span class="{{ $look['avatar'] }} flex size-24 items-center justify-center overflow-hidden rounded-2xl text-3xl font-semibold shadow-lg ring-4 ring-white dark:ring-zinc-950">@if ($photoUrl = $e->avatarUrl())<img src="{{ $photoUrl }}" alt="" class="size-full rounded-[inherit] object-cover">@else{{ $e->initials() }}@endif</span>
                        <span class="absolute inset-0 flex items-center justify-center rounded-2xl bg-zinc-950/45 text-white opacity-0 transition group-hover/photo:opacity-100" wire:loading.class="opacity-100!" wire:target="photo">
                            <flux:icon.camera wire:loading.remove wire:target="photo" class="size-6" />
                            <flux:icon.loading wire:loading wire:target="photo" class="size-6" />
                        </span>
                        <span class="{{ $statusDot[$e->status] }} absolute -end-1 -bottom-1 size-4.5 rounded-full ring-[3px] ring-white dark:ring-zinc-950" title="{{ __(Employee::STATUSES[$e->status]) }}"></span>
                    </label>
                    <div class="min-w-0 pt-3">
                        <h1 class="truncate text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-[1.75rem] dark:text-white">{{ $e->name }}</h1>
                        @error('photo')<p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>@enderror
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
                            @if ($e->avatar_path)
                                <flux:menu.item icon="photo" wire:click="removePhoto">{{ __('Remove photo') }}</flux:menu.item>
                            @endif
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

        @php
            $at = $this->attendanceTotals;
            $attCards = [
                ['label' => __('Total absent'), 'value' => $at['absent'], 'icon' => 'x-circle', 'tile' => 'bg-rose-500 text-white shadow-md shadow-rose-500/30', 'glow' => 'bg-rose-100/70 dark:bg-rose-500/10', 'sub' => __('Days missed without leave'), 'tone' => 'text-rose-700 dark:text-rose-300'],
            ];
            // Salary still to pay for this month: salary minus every payment made for it.
            $due = $this->thisMonth;
        @endphp
        <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5" data-test="to-pay-card">
            <div @class(['absolute -top-12 -right-12 size-32 rounded-full', 'bg-emerald-100/70 dark:bg-emerald-500/10' => $due && $due['left'] <= 0, 'bg-amber-100/70 dark:bg-amber-500/10' => ! $due || $due['left'] > 0])></div>
            <div class="relative flex items-center gap-3">
                <span @class([$iconTile, 'bg-emerald-500 text-white shadow-md shadow-emerald-500/30' => $due && $due['left'] <= 0, 'bg-amber-500 text-white shadow-md shadow-amber-500/30' => ! $due || $due['left'] > 0])><flux:icon :icon="$due && $due['left'] <= 0 ? 'check-badge' : 'wallet'" variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('To pay this month') }}</p>
                @if ($due)
                    <span @class(['rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $due['left'] <= 0, 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $due['left'] > 0]) title="{{ __('Paid so far') }}">{{ $due['percent'] }}%</span>
                @endif
            </div>
            <p @class(['relative text-[1.875rem] leading-none font-bold tracking-tight tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $due && $due['left'] <= 0, 'text-zinc-900 dark:text-white' => ! $due || $due['left'] > 0])>
                {{ $due ? ($due['left'] <= 0 ? __('All paid') : $money($due['left'])) : '—' }}
            </p>
            <p class="relative mt-auto text-xs text-zinc-500">
                @if ($due)
                    {{ __(':paid paid of :salary', ['paid' => $money($due['paid']), 'salary' => $money($due['salary'])]) }} · {{ $periodLabel }}
                @else
                    {{ __('Not on payroll') }}
                @endif
            </p>
        </div>

        {{-- Advance: salary already paid for months after this one --}}
        @php
            $advance = $payments->filter(fn ($sp) => $sp->period > $this->period);
            $advanceMonths = $advance->pluck('period')->unique()->sort()->map(fn ($p) => str(NepaliDate::periodLabel($p))->before(' '));
        @endphp
        <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5" data-test="advance-card">
            <div class="absolute -top-12 -right-12 size-32 rounded-full bg-violet-100/70 dark:bg-violet-500/10"></div>
            <div class="relative flex items-center gap-3">
                <span class="{{ $iconTile }} bg-violet-500 text-white shadow-md shadow-violet-500/30"><flux:icon.forward variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Advance paid') }}</p>
                @if ($advance->isNotEmpty())
                    <span class="rounded-md bg-violet-50 px-1.5 py-0.5 text-xs font-semibold text-violet-700 tabular-nums dark:bg-violet-500/10 dark:text-violet-300">{{ trans_choice(':count month|:count months', $advanceMonths->count()) }}</span>
                @endif
            </div>
            <p @class(['relative text-[1.875rem] leading-none font-bold tracking-tight tabular-nums', 'text-violet-700 dark:text-violet-300' => $advance->isNotEmpty(), 'text-zinc-300 dark:text-zinc-600' => $advance->isEmpty()])>{{ $money($advance->sum('amount')) }}</p>
            <p class="relative mt-auto truncate text-xs text-zinc-500">
                {{ $advance->isNotEmpty() ? __('For :months', ['months' => $advanceMonths->join(', ')]) : __('No salary paid ahead yet') }}
            </p>
        </div>

        @foreach ($attCards as $card)
            <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5">
                <div class="{{ $card['glow'] }} absolute -top-12 -right-12 size-32 rounded-full"></div>
                <div class="relative flex items-center gap-3">
                    <span class="{{ $iconTile }} {{ $card['tile'] }}"><flux:icon :icon="$card['icon']" variant="mini" class="size-4.5" /></span>
                    <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $card['label'] }}</p>
                </div>
                <p class="relative flex items-baseline gap-1.5 leading-none">
                    <span class="{{ $card['tone'] }} text-[1.875rem] font-bold tracking-tight tabular-nums">{{ $card['value'] }}</span>
                    <span class="text-sm text-zinc-400">{{ trans_choice('day|days', $card['value']) }}</span>
                </p>
                <p class="relative mt-auto text-xs text-zinc-500">{{ $card['sub'] }} · {{ __('all time') }}</p>
            </div>
        @endforeach
    </section>

    {{-- Tabs --}}
    @php
        $work = $this->work;
        $openWork = $work->whereNull('completed_at');
        $docs = $this->documents;
        $tabs = [
            'overview' => ['label' => __('Overview'), 'icon' => 'squares-2x2', 'count' => null],
            'work' => ['label' => __('Work'), 'icon' => 'clipboard-document-check', 'count' => $openWork->count() ?: null],
            'salary' => ['label' => __('Salary'), 'icon' => 'banknotes', 'count' => null],
            'documents' => ['label' => __('Documents'), 'icon' => 'document-text', 'count' => $docs->count() ?: null],
            'details' => ['label' => __('Details'), 'icon' => 'identification', 'count' => null],
        ];
        $markTone = [
            'present' => ['cell' => 'bg-emerald-50/80 dark:bg-emerald-500/10', 'pill' => 'bg-emerald-500 text-white', 'dot' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-300'],
            'half_day' => ['cell' => 'bg-amber-50/80 dark:bg-amber-500/10', 'pill' => 'bg-amber-400 text-amber-950', 'dot' => 'bg-amber-400', 'text' => 'text-amber-700 dark:text-amber-300'],
            'leave' => ['cell' => 'bg-sky-50/80 dark:bg-sky-500/10', 'pill' => 'bg-sky-500 text-white', 'dot' => 'bg-sky-500', 'text' => 'text-sky-700 dark:text-sky-300'],
            'absent' => ['cell' => 'bg-rose-50/80 dark:bg-rose-500/10', 'pill' => 'bg-rose-500 text-white', 'dot' => 'bg-rose-500', 'text' => 'text-rose-700 dark:text-rose-300'],
        ];
        $nextSalary = $e->nextSalaryDate();
    @endphp
    <nav class="flex gap-1 overflow-x-auto rounded-2xl bg-white p-1.5 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10" aria-label="{{ __('Employee sections') }}">
        @foreach ($tabs as $key => $t)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition',
                'bg-brand-500 text-white shadow-md shadow-brand-500/25' => $tab === $key,
                'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' => $tab !== $key,
            ]) @if ($tab === $key) aria-current="page" @endif data-test="emp-tab-{{ $key }}">
                <flux:icon :icon="$t['icon']" variant="micro" class="size-4" />{{ $t['label'] }}
                @if ($t['count'])
                    <span @class(['rounded-md px-1.5 py-0.5 text-[0.6875rem] tabular-nums', 'bg-white/20' => $tab === $key, 'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $tab !== $key])>{{ $t['count'] }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        @php
            $cal = $this->calendar;
            // Salary on this calendar: the salary day of the shown month and the days payments were made.
            $calPeriod = sprintf('%d-%02d', $this->calYear, $this->calMonth);
            $salaryDayNum = $e->salary_day ? min($e->salary_day, NepaliDate::monthLength($this->calYear, $this->calMonth)) : null;
            $periodPaid = (float) $payments->where('period', $calPeriod)->sum('amount');
            $salaryOf = (float) $e->salary;
            $paidOn = $payments->groupBy(fn ($sp) => $sp->paid_on->toDateString());
            $salaryTagState = match (true) {
                $salaryOf > 0 && $periodPaid >= $salaryOf => 'paid',
                $periodPaid > 0 => 'part',
                $salaryDayNum && NepaliDate::toAd($this->calYear, $this->calMonth, $salaryDayNum)->isBefore(today()) => 'late',
                default => 'due',
            };
            $salaryCell = [
                'paid' => ['cell' => 'bg-linear-to-br from-emerald-100/90 via-emerald-50 to-white ring-2 ring-emerald-400 ring-inset dark:from-emerald-500/20 dark:via-emerald-500/10 dark:to-transparent', 'badge' => 'bg-emerald-500 shadow-emerald-500/40', 'tag' => 'bg-emerald-500 text-white'],
                'part' => ['cell' => 'bg-linear-to-br from-amber-100/90 via-amber-50 to-white ring-2 ring-amber-400 ring-inset dark:from-amber-500/20 dark:via-amber-500/10 dark:to-transparent', 'badge' => 'bg-amber-400 shadow-amber-400/40', 'tag' => 'bg-amber-400 text-amber-950'],
                'late' => ['cell' => 'bg-linear-to-br from-rose-100/90 via-rose-50 to-white ring-2 ring-rose-400 ring-inset dark:from-rose-500/20 dark:via-rose-500/10 dark:to-transparent', 'badge' => 'bg-rose-500 shadow-rose-500/40', 'tag' => 'bg-rose-500 text-white'],
                'due' => ['cell' => 'bg-linear-to-br from-brand-100/90 via-brand-50 to-white ring-2 ring-brand-400 ring-inset dark:from-brand-500/25 dark:via-brand-500/10 dark:to-transparent', 'badge' => 'bg-brand-500 shadow-brand-500/40', 'tag' => 'bg-brand-500 text-white'],
            ][$salaryTagState];
            $salaryTag = [
                'paid' => ['bg-emerald-100 text-emerald-800 ring-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-200', __('Salary paid')],
                'part' => ['bg-amber-100 text-amber-800 ring-amber-300 dark:bg-amber-500/15 dark:text-amber-200', __('Salary part')],
                'late' => ['bg-rose-100 text-rose-800 ring-rose-300 dark:bg-rose-500/15 dark:text-rose-200', __('Salary unpaid')],
                'due' => ['bg-brand-100 text-brand-800 ring-brand-300 dark:bg-brand-500/20 dark:text-brand-100', __('Salary day')],
            ][$salaryTagState];
            $todayKey = today()->toDateString();
            $isThisMonth = [$this->calYear, $this->calMonth] === array_slice(NepaliDate::fromAd(today()), 0, 2);
        @endphp
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="flex min-w-0 flex-col gap-6">
                {{-- Attendance calendar --}}
                <section class="{{ $panel }} min-w-0 overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="{{ $iconTile }} bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.calendar-days variant="mini" class="size-4.5" /></span>
                            <div>
                                <h2 class="text-lg leading-tight font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $cal['label'] }}</h2>
                                <p class="text-xs text-zinc-500">{{ __('Attendance') }} · {{ $cal['range'] }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="flex items-center rounded-lg ring-1 ring-zinc-200 dark:ring-white/10">
                                <button type="button" wire:click="shiftMonth(-1)" class="flex size-9 items-center justify-center rounded-s-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Previous month') }}"><flux:icon.chevron-left variant="mini" class="size-4" /></button>
                                <button type="button" wire:click="goToday" @class(['h-9 border-x border-zinc-200 px-3 text-sm font-medium dark:border-white/10', 'text-brand-600 dark:text-brand-300' => $isThisMonth, 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-white/5' => ! $isThisMonth])>{{ __('Today') }}</button>
                                <button type="button" wire:click="shiftMonth(1)" class="flex size-9 items-center justify-center rounded-e-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Next month') }}"><flux:icon.chevron-right variant="mini" class="size-4" /></button>
                            </div>
                            <flux:button size="sm" icon="check-circle" wire:click="markWorkdaysPresent" wire:confirm="{{ __('Mark every unmarked working day this month (up to today) as present?') }}">{{ __('Mark workdays present') }}</flux:button>
                        </div>
                    </div>

                    {{-- Month summary --}}
                    <div class="grid grid-cols-3 gap-2 border-y border-zinc-100 bg-zinc-50/60 px-5 py-3 sm:grid-cols-6 dark:border-white/5 dark:bg-white/[0.02]">
                        @foreach (Attendance::STATUSES as $key => $st)
                            <div class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-2 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
                                <span class="{{ $markTone[$key]['dot'] }} size-2 shrink-0 rounded-full"></span>
                                <span class="min-w-0 flex-1 truncate text-xs text-zinc-500">{{ __($st['label']) }}</span>
                                <span class="text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $cal['counts'][$key] }}</span>
                            </div>
                        @endforeach
                        <div class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-2 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
                            <flux:icon.chart-pie variant="micro" class="size-3.5 shrink-0 text-brand-500" />
                            <span class="min-w-0 flex-1 truncate text-xs text-zinc-500">{{ __('Attendance') }}</span>
                            <span class="text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $cal['rate'] !== null ? $cal['rate'].'%' : '–' }}</span>
                        </div>
                        <div class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-2 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
                            <flux:icon.check-badge variant="micro" class="size-3.5 shrink-0 text-violet-500" />
                            <span class="min-w-0 flex-1 truncate text-xs text-zinc-500">{{ __('Work done') }}</span>
                            <span class="text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $cal['done'] }}</span>
                        </div>
                    </div>

                    {{-- Grid --}}
                    <div class="overflow-x-auto">
                        <div class="min-w-[42rem]">
                            <div class="grid grid-cols-7 border-b border-zinc-100 dark:border-white/5">
                                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $wd)
                                    <div @class(['px-2 py-2 text-center text-[0.6875rem] font-semibold tracking-wider uppercase', 'text-rose-500' => $i === 6, 'text-zinc-400' => $i !== 6])>{{ __($wd) }}</div>
                                @endforeach
                            </div>
                            @foreach ($cal['weeks'] as $week)
                                <div class="grid grid-cols-7 border-b border-zinc-100 last:border-0 dark:border-white/5">
                                    @foreach ($week as $i => $cell)
                                        @if ($cell === null)
                                            <div class="min-h-24 border-e border-zinc-100 bg-zinc-50/50 last:border-e-0 dark:border-white/5 dark:bg-white/[0.01]"></div>
                                        @else
                                            @php
                                                $key = $cell['ad']->toDateString();
                                                $isToday = $key === $todayKey;
                                                $isFuture = $cell['ad']->isAfter(today());
                                                $mark = $cell['status'];
                                                $isSalaryDay = $salaryDayNum === $cell['day'] && $e->status !== 'inactive';
                                            @endphp
                                            @if ($cell['ad']->isHoliday())
                                                <div wire:key="att-{{ $key }}" @class(['relative flex min-h-24 flex-col gap-1.5 border-e border-zinc-100 p-2 last:border-e-0 dark:border-white/5', $salaryCell['cell'] => $isSalaryDay, 'bg-rose-50/40 dark:bg-rose-500/[0.04]' => ! $isSalaryDay])>
                                                    <span class="flex items-center justify-between">
                                                        <span @class(['flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums', 'bg-brand-500 text-white' => $isToday, 'text-rose-500' => ! $isToday])>{{ $cell['day'] }}</span>
                                                        @if ($isSalaryDay)
                                                            <span class="{{ $salaryCell['badge'] }} flex size-6 items-center justify-center rounded-full text-white shadow-md ring-2 ring-white dark:ring-zinc-900" title="{{ __('Salary day') }}"><flux:icon.banknotes variant="micro" class="size-3.5" /></span>
                                                        @else
                                                            <span class="text-[0.625rem] text-zinc-300 tabular-nums dark:text-zinc-600">{{ $cell['ad']->format('j') }}</span>
                                                        @endif
                                                    </span>
                                                    <span class="inline-flex w-fit items-center gap-1 rounded-md bg-rose-100 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300"><flux:icon.sparkles variant="micro" class="size-3" />{{ __('Holiday') }}</span>
                                                    @if ($isSalaryDay)
                                                        <span class="{{ $salaryCell['tag'] }} inline-flex w-fit items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap shadow-sm">{{ $salaryTag[1] }}</span>
                                                        @if ($salaryOf)
                                                            <span class="text-[0.6875rem] font-semibold text-zinc-700 tabular-nums dark:text-zinc-200">{{ $money($periodPaid) }} <span class="font-normal text-zinc-400">/ {{ number_format($salaryOf) }}</span></span>
                                                        @endif
                                                    @endif
                                                    @if (isset($paidOn[$cell['ad']->toDateString()]))
                                                        <span class="inline-flex w-fit items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.arrow-up-right variant="micro" class="size-3" />{{ __('Paid :amount', ['amount' => $money($paidOn[$cell['ad']->toDateString()]->sum('amount'))]) }}</span>
                                                    @endif
                                                    @if ($cell['done']->isNotEmpty())
                                                        <span class="mt-auto inline-flex w-fit items-center gap-1 rounded-md bg-violet-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-violet-700 dark:bg-violet-500/10 dark:text-violet-300" title="{{ $cell['done']->pluck('title')->implode(', ') }}"><flux:icon.check variant="micro" class="size-3" />{{ trans_choice(':count task done|:count tasks done', $cell['done']->count()) }}</span>
                                                    @endif
                                                </div>
                                            @else
                                            <flux:dropdown wire:key="att-{{ $key }}" class="contents">
                                                <button type="button" @class([
                                                    'group relative flex min-h-24 w-full flex-col gap-1.5 border-e border-zinc-100 p-2 text-start transition last:border-e-0 hover:ring-2 hover:ring-brand-300 hover:ring-inset dark:border-white/5',
                                                    $salaryCell['cell'] => $isSalaryDay,
                                                    $markTone[$mark]['cell'] ?? '' => $mark && ! $isSalaryDay,
                                                    'bg-rose-50/30 dark:bg-rose-500/[0.03]' => ! $mark && ! $isSalaryDay && $i === 6,
                                                ]) aria-label="{{ __(':date, :status. Click to change.', ['date' => $cell['day'].' '.$cal['label'], 'status' => $mark ? __(Attendance::STATUSES[$mark]['label']) : __('not marked')]) }}">
                                                    <span class="flex items-center justify-between">
                                                        <span @class([
                                                            'flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                                            'bg-brand-500 text-white shadow-sm shadow-brand-500/40' => $isToday,
                                                            'text-zinc-400' => ! $isToday && $isFuture,
                                                            'text-zinc-800 dark:text-zinc-100' => ! $isToday && ! $isFuture,
                                                        ])>{{ $cell['day'] }}</span>
                                                        @if ($isSalaryDay)
                                                            <span class="{{ $salaryCell['badge'] }} flex size-6 items-center justify-center rounded-full text-white shadow-md ring-2 ring-white dark:ring-zinc-900" title="{{ __('Salary day') }}"><flux:icon.banknotes variant="micro" class="size-3.5" /></span>
                                                        @else
                                                            <span class="text-[0.625rem] text-zinc-300 tabular-nums dark:text-zinc-600">{{ $cell['ad']->format('j') }}</span>
                                                        @endif
                                                    </span>
                                                    @if ($mark)
                                                        <span class="{{ $markTone[$mark]['pill'] }} inline-flex w-fit items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-semibold" @if ($cell['note']) title="{{ $cell['note'] }}" @endif>
                                                            <flux:icon :icon="Attendance::STATUSES[$mark]['icon']" variant="micro" class="size-3" />{{ __(Attendance::STATUSES[$mark]['label']) }}
                                                        </span>
                                                        @if ($cell['in'])
                                                            <span class="inline-flex w-fit items-center gap-1 text-[0.6875rem] font-medium text-zinc-600 tabular-nums dark:text-zinc-300" title="{{ $cell['device'] ? __('Check-in from the attendance device') : __('Check-in') }}">
                                                                <flux:icon :icon="$cell['device'] ? 'finger-print' : 'clock'" variant="micro" class="size-3 text-zinc-400" />{{ $cell['in'] }}
                                                            </span>
                                                        @endif
                                                        @if ($mark === 'leave' && $cell['note'])
                                                            <span class="line-clamp-2 text-[0.6875rem] leading-snug text-sky-800 dark:text-sky-200" title="{{ $cell['note'] }}">{{ $cell['note'] }}</span>
                                                        @endif
                                                    @elseif (! $isFuture && $i !== 6)
                                                        <span class="text-[0.6875rem] text-zinc-300 opacity-0 transition group-hover:opacity-100 dark:text-zinc-600">{{ __('Mark') }}</span>
                                                    @endif
                                                    @if ($isSalaryDay)
                                                        <span class="{{ $salaryCell['tag'] }} inline-flex w-fit items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap shadow-sm">{{ $salaryTag[1] }}</span>
                                                        @if ($salaryOf)
                                                            <span class="text-[0.6875rem] font-semibold text-zinc-700 tabular-nums dark:text-zinc-200">{{ $money($periodPaid) }} <span class="font-normal text-zinc-400">/ {{ number_format($salaryOf) }}</span></span>
                                                        @endif
                                                    @endif
                                                    @if (isset($paidOn[$cell['ad']->toDateString()]))
                                                        <span class="inline-flex w-fit items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.arrow-up-right variant="micro" class="size-3" />{{ __('Paid :amount', ['amount' => $money($paidOn[$cell['ad']->toDateString()]->sum('amount'))]) }}</span>
                                                    @endif
                                                    @if ($cell['done']->isNotEmpty())
                                                        <span class="mt-auto inline-flex w-fit items-center gap-1 rounded-md bg-violet-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-violet-700 dark:bg-violet-500/10 dark:text-violet-300" title="{{ $cell['done']->pluck('title')->implode(', ') }}">
                                                            <flux:icon.check variant="micro" class="size-3" />{{ trans_choice(':count task done|:count tasks done', $cell['done']->count()) }}
                                                        </span>
                                                    @endif
                                                </button>
                                                <flux:menu>
                                                    <flux:menu.heading>{{ $cell['day'] }} {{ $cal['label'] }}</flux:menu.heading>
                                                    @foreach (Attendance::STATUSES as $sk => $sv)
                                                        <flux:menu.item :icon="$sv['icon']" wire:click="markDay('{{ $key }}', '{{ $sk }}')">{{ __($sv['label']) }}</flux:menu.item>
                                                    @endforeach
                                                    @if ($mark)
                                                        <flux:menu.separator />
                                                        <flux:menu.item icon="x-mark" wire:click="markDay('{{ $key }}', '')">{{ __('Clear') }}</flux:menu.item>
                                                    @endif
                                                </flux:menu>
                                            </flux:dropdown>
                                            @endif
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

            </div>

            {{-- Side --}}
            <aside class="flex flex-col gap-6">
                {{-- Salary time --}}
                <section class="relative overflow-hidden rounded-xl bg-linear-to-br from-brand-500 to-brand-700 p-5 text-white shadow-[0_18px_40px_-20px_rgb(49_81_254/0.7)]">
                    <div class="absolute -top-10 -right-10 size-36 rounded-full bg-white/10"></div>
                    <div class="absolute -right-6 -bottom-16 size-32 rounded-full bg-sun/30 blur-2xl"></div>
                    <p class="relative text-xs font-semibold tracking-wider text-white/70 uppercase">{{ __('Next salary') }}</p>
                    @if ($nextSalary)
                        @php $salaryIn = (int) today()->diffInDays($nextSalary); @endphp
                        <p class="relative mt-2 text-3xl leading-none font-semibold tracking-tight">{{ $salaryIn === 0 ? __('Today') : trans_choice('In :count day|In :count days', $salaryIn) }}</p>
                        <p class="relative mt-1.5 text-sm text-white/80">{{ $nextSalary->toNepali() }}@if ($e->salary) · {{ $money($e->salary) }}@endif</p>
                    @else
                        <p class="relative mt-2 text-lg font-semibold">{{ __('Salary day not set') }}</p>
                        <button type="button" wire:click="edit" class="relative mt-1 text-sm text-white/80 underline-offset-2 hover:underline">{{ __('Set the day salary is paid') }}</button>
                    @endif
                    @if ($pay)
                        <div class="relative mt-4 rounded-lg bg-white/10 p-3 ring-1 ring-white/15">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-white/75">{{ $periodLabel }}</span>
                                <span class="font-semibold">{{ $full ? __('Paid') : __(':amount left', ['amount' => $money($pay['left'])]) }}</span>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/20"><div class="h-full rounded-full bg-sun" style="width: {{ $pay['percent'] }}%"></div></div>
                        </div>
                        @unless ($full)
                            <button type="button" wire:click="openPay" class="relative mt-3 flex w-full items-center justify-center gap-1.5 rounded-lg bg-white py-2 text-sm font-semibold text-brand-700 transition hover:bg-white/90"><flux:icon.banknotes variant="micro" class="size-4" />{{ __('Pay salary') }}</button>
                        @endunless
                    @endif
                </section>


                {{-- All-time attendance --}}
                @php
                    $at = $this->attendanceTotals;
                    $atLook = [
                        'present' => ['bar' => 'bg-emerald-500', 'tile' => 'bg-emerald-50 text-emerald-600 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20'],
                        'half_day' => ['bar' => 'bg-amber-400', 'tile' => 'bg-amber-50 text-amber-600 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20'],
                        'leave' => ['bar' => 'bg-sky-500', 'tile' => 'bg-sky-50 text-sky-600 ring-sky-200/70 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20'],
                        'absent' => ['bar' => 'bg-rose-500', 'tile' => 'bg-rose-50 text-rose-600 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20'],
                    ];
                @endphp
                <section class="{{ $panel }} p-5" data-test="attendance-totals">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Attendance') }}</h2>
                            <p class="text-xs text-zinc-500">{{ __('All time') }} · {{ trans_choice(':count day marked|:count days marked', $at['marked']) }}</p>
                        </div>
                        @if ($at['rate'] !== null)
                            <div class="text-end">
                                <p @class(['text-2xl leading-none font-bold tracking-tight tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $at['rate'] >= 90, 'text-amber-600 dark:text-amber-400' => $at['rate'] >= 75 && $at['rate'] < 90, 'text-rose-600 dark:text-rose-400' => $at['rate'] < 75])>{{ $at['rate'] }}%</p>
                                <p class="mt-1 text-[0.6875rem] text-zinc-500">{{ __('attendance') }}</p>
                            </div>
                        @endif
                    </div>

                    @if ($at['marked'])
                        <div class="mt-4 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" role="img" aria-label="{{ __('Attendance split') }}">
                            @foreach (Attendance::STATUSES as $key => $st)
                                @if ($at[$key])
                                    <div class="{{ $atLook[$key]['bar'] }} h-full" style="width: {{ $at[$key] / $at['marked'] * 100 }}%" title="{{ __($st['label']) }}: {{ $at[$key] }}"></div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 grid grid-cols-2 gap-2.5">
                        @foreach (Attendance::STATUSES as $key => $st)
                            <div class="{{ $atLook[$key]['tile'] }} flex items-center gap-3 rounded-xl px-3 py-2.5 ring-1 ring-inset">
                                <flux:icon :icon="$st['icon']" variant="mini" class="size-5 shrink-0" />
                                <div class="min-w-0">
                                    <p class="text-xl leading-none font-bold text-zinc-900 tabular-nums dark:text-white">{{ $at[$key] }}</p>
                                    <p class="mt-1 truncate text-xs font-medium">{{ __($st['label']) }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @unless ($at['marked'])
                        <p class="mt-3 text-xs text-zinc-500">{{ __('Mark days on the calendar to build the record.') }}</p>
                    @endunless
                </section>

            </aside>
        </div>

        {{-- Salary history: paid salary payments only, listed like the finance page --}}
        @php
            $paidList = $payments->sortByDesc(fn ($sp) => $sp->paid_on->timestamp.'-'.str_pad($sp->id, 9, '0', STR_PAD_LEFT))->values();
            $paidTotal = (float) $paidList->sum('amount');
            $hth = 'px-3 py-2.5 text-start text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-500 uppercase';
        @endphp
        <section class="{{ $panel }} min-w-0 overflow-hidden" data-test="salary-history">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="{{ $iconTile }} bg-emerald-500 text-white shadow-md shadow-emerald-500/30"><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                    <div>
                        <h2 class="text-lg leading-tight font-semibold tracking-tight text-zinc-900 dark:text-white">{{ __('Salary history') }}</h2>
                        <p class="text-xs text-zinc-500">{{ trans_choice(':count payment made|:count payments made', $paidList->count()) }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    @if ($paidList->isNotEmpty())
                        <div class="text-end">
                            <p class="text-[0.6875rem] text-zinc-500">{{ __('Total paid') }}</p>
                            <p class="text-sm font-bold text-zinc-900 tabular-nums dark:text-white">{{ $money($paidTotal) }}</p>
                        </div>
                    @endif
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openPay">{{ __('Record payment') }}</flux:button>
                </div>
            </div>

            @if ($paidList->isEmpty())
                <div class="flex flex-col items-center gap-2 border-t border-zinc-100 px-5 py-10 text-center dark:border-white/5">
                    <span class="flex size-10 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-white/5"><flux:icon.banknotes variant="mini" class="size-5" /></span>
                    <p class="text-sm text-zinc-500">{{ __('No salary paid yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                    <table class="w-full min-w-[44rem] text-sm">
                        <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                            <tr>
                                <th class="{{ $hth }} ps-5">{{ __('Title') }}</th>
                                <th class="{{ $hth }}">{{ __('Paid date') }}</th>
                                <th class="{{ $hth }}">{{ __('Category') }}</th>
                                <th class="{{ $hth }}">{{ __('Note') }}</th>
                                <th class="{{ str_replace('text-start', 'text-end', $hth) }}">{{ __('Amount') }}</th>
                                <th class="{{ $hth }} w-12 pe-5"><span class="sr-only">{{ __('Delete') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach ($paidList as $sp)
                                <tr wire:key="history-sp-{{ $sp->id }}" class="group transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="px-3 py-2.5 ps-5">
                                        <div class="flex items-center gap-3">
                                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.arrow-up-right variant="micro" class="size-4" /></span>
                                            <p class="font-semibold whitespace-nowrap text-zinc-900 dark:text-white">{{ $sp->label() }}</p>
                                                @if ($sp->title)<p class="text-xs text-zinc-500">{{ __('For :month', ['month' => NepaliDate::periodLabel($sp->period)]) }}</p>@endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5 font-medium whitespace-nowrap text-zinc-900 dark:text-white">{{ $sp->paid_on->toNepali() }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium whitespace-nowrap text-zinc-700 dark:bg-white/10 dark:text-zinc-200"><flux:icon.user-group variant="micro" class="size-3.5" />{{ __('Salary') }}</span>
                                    </td>
                                    <td class="max-w-56 truncate px-3 py-2.5 text-zinc-500">{{ $sp->note ?: '—' }}</td>
                                    <td class="px-3 py-2.5 text-end whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-lg bg-emerald-50 px-2.5 py-1 text-sm font-bold text-emerald-700 tabular-nums ring-1 ring-emerald-200 ring-inset dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">{{ $money($sp->amount) }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 pe-5 text-end">
                                        <button type="button" wire:click="confirmDeleteSalaryPayment({{ $sp->id }})" class="rounded-md p-1 text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-500 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete payment') }}"><flux:icon.trash variant="micro" class="size-4" /></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @elseif ($tab === 'work')
        @php
            $monthStart = NepaliDate::monthRange(today())[0];
            $doneWork = $work->whereNotNull('completed_at')->sortByDesc('completed_at');
            $doneThisMonth = $doneWork->filter(fn ($t) => $t->completed_at->gte($monthStart))->count();
            $lateWork = $openWork->filter->isOverdue()->count();
        @endphp
        <section class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                [__('Open work'), $openWork->count(), $lateWork ? trans_choice(':count late|:count late', $lateWork) : __('Nothing late'), 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300', 'clipboard-document-list'],
                [__('Done this month'), $doneThisMonth, $periodLabel, 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300', 'check-badge'],
                [__('Done in total'), $doneWork->count(), trans_choice('across :count client|across :count clients', $work->pluck('client_id')->unique()->count()), 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300', 'trophy'],
            ] as [$wlabel, $wvalue, $wsub, $wtone, $wicon])
                <div class="{{ $panel }} flex items-center gap-4 p-5">
                    <span class="{{ $wtone }} flex size-11 items-center justify-center rounded-xl"><flux:icon :icon="$wicon" variant="mini" class="size-5" /></span>
                    <div>
                        <p class="text-sm text-zinc-500">{{ $wlabel }}</p>
                        <p class="text-2xl leading-tight font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $wvalue }}</p>
                        <p @class(['text-xs', 'text-rose-600 dark:text-rose-400' => $wlabel === __('Open work') && $lateWork, 'text-zinc-500' => ! ($wlabel === __('Open work') && $lateWork)])>{{ $wsub }}</p>
                    </div>
                </div>
            @endforeach
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            @foreach ([[__('Open work'), $openWork, 'clipboard-document-list'], [__('Completed'), $doneWork->take(30), 'check-badge']] as [$listLabel, $list, $listIcon])
                <section class="{{ $panel }} overflow-hidden">
                    <div class="flex items-center gap-2.5 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                        <flux:icon :icon="$listIcon" variant="mini" class="size-4.5 text-zinc-400" />
                        <h2 class="flex-1 font-semibold text-zinc-900 dark:text-white">{{ $listLabel }}</h2>
                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 tabular-nums dark:bg-white/10 dark:text-zinc-300">{{ $list->count() }}</span>
                    </div>
                    @forelse ($list as $t)
                        @php
                            $tt = \App\Models\Task::types()[$t->type] ?? \App\Models\Task::unknownType($t->type);
                            $tDone = (bool) $t->completed_at;
                        @endphp
                        <a href="{{ route('clients.show', ['client' => $t->client_id, 'tab' => 'tasks']) }}" wire:navigate wire:key="work-{{ $t->id }}" class="group flex items-center gap-3 border-b border-zinc-100 px-5 py-3 transition last:border-0 hover:bg-zinc-50 dark:border-white/5 dark:hover:bg-white/[0.02]">
                            <span @class(['flex size-5 shrink-0 items-center justify-center rounded-full border-[1.5px]', 'border-emerald-500 bg-emerald-500 text-white' => $tDone, 'border-zinc-300 text-transparent dark:border-zinc-600' => ! $tDone])><flux:icon.check variant="micro" class="size-3" /></span>
                            <div class="min-w-0 flex-1">
                                <p @class(['truncate text-sm', 'text-zinc-400 line-through' => $tDone, 'font-medium text-zinc-900 group-hover:text-brand-600 dark:text-white' => ! $tDone])>{{ $t->title }}</p>
                                <p class="mt-0.5 flex items-center gap-1.5 truncate text-xs text-zinc-500">
                                    <flux:icon :icon="$tt['icon']" variant="micro" class="size-3 shrink-0" />{{ $tt['label'] }}
                                    · <span class="truncate">{{ $t->client?->displayName() }}</span>
                                </p>
                            </div>
                            <span @class(['shrink-0 text-xs', 'font-medium text-rose-600 dark:text-rose-400' => ! $tDone && $t->isOverdue(), 'text-emerald-600 dark:text-emerald-400' => $tDone, 'text-zinc-500' => ! $tDone && ! $t->isOverdue()])>
                                {{ $tDone ? __('Done :date', ['date' => $t->completed_at->toNepali(withYear: false)]) : ($t->due_date ? $t->due_date->toNepali(withYear: false) : __('No date')) }}
                            </span>
                        </a>
                    @empty
                        <p class="px-5 py-10 text-center text-sm text-zinc-500">{{ $listLabel === __('Open work') ? __('Nothing assigned right now.') : __('Nothing finished yet.') }}</p>
                    @endforelse
                </section>
            @endforeach
        </div>
    @elseif ($tab === 'salary')
        <div class="flex flex-col gap-6">
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

        </div>
    @elseif ($tab === 'details')
        @php
            $age = $e->date_of_birth ? (int) $e->date_of_birth->diffInYears(today()) : null;
            $filled = collect(self::DETAIL_FIELDS)->filter(fn ($f) => filled($e->{$f}))->count();
            $completion = (int) round($filled / count(self::DETAIL_FIELDS) * 100);
            $sections = [
                ['title' => __('Personal'), 'icon' => 'user', 'tone' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300', 'rows' => [
                    ['cake', __('Date of birth'), $e->date_of_birth ? $e->date_of_birth->toNepali().' · '.trans_choice(':count year|:count years', $age) : null],
                    ['user', __('Gender'), $e->gender ? __(ucfirst($e->gender)) : null],
                    ['heart', __('Blood group'), $e->blood_group],
                    ['users', __('Marital status'), $e->marital_status ? __(ucfirst($e->marital_status)) : null],
                ]],
                ['title' => __('Family'), 'icon' => 'users', 'tone' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300', 'rows' => [
                    ['user', __("Father's name"), $e->father_name],
                    ['user', __("Mother's name"), $e->mother_name],
                    ['heart', __("Spouse's name"), $e->spouse_name],
                ]],
                ['title' => __('Address'), 'icon' => 'map-pin', 'tone' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300', 'rows' => [
                    ['home', __('Permanent address'), $e->permanent_address],
                    ['map-pin', __('Current address'), $e->current_address],
                    ['map', __('District'), $e->district],
                    ['globe-asia-australia', __('Province'), $e->province],
                ]],
                ['title' => __('Identity'), 'icon' => 'identification', 'tone' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300', 'rows' => [
                    ['identification', __('Citizenship no.'), $e->citizenship_no],
                    ['document-text', __('PAN no.'), $e->pan_no],
                ]],
                ['title' => __('Bank'), 'icon' => 'building-library', 'tone' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300', 'rows' => [
                    ['building-library', __('Bank'), $e->bank_name],
                    ['user', __('Account name'), $e->bank_account_name],
                    ['hashtag', __('Account no.'), $e->bank_account_no],
                ]],
                ['title' => __('Work & pay'), 'icon' => 'briefcase', 'tone' => 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300', 'rows' => [
                    ['briefcase', __('Role'), $e->designation],
                    [$meta['icon'], __('Department'), __($meta['label'])],
                    ['calendar', __('Joined'), $e->joined_on?->toNepali()],
                    ['wallet', __('Monthly salary'), $e->salary ? $money($e->salary) : null],
                    ['banknotes', __('Salary day'), $e->salary_day ? __('Day :day of each month', ['day' => $e->salary_day]) : null],
                    ['document-check', __('Agreement'), $e->contract_start || $e->contract_end ? ($e->contract_start?->toNepali() ?? '…').' → '.($e->contract_end?->toNepali() ?? '…') : null],
                ]],
            ];
        @endphp

        <div class="grid items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
            {{-- Profile summary --}}
            <aside class="flex flex-col gap-6">
                <section class="{{ $panel }} overflow-hidden">
                    <div class="{{ $look['cover'] }} h-16 bg-linear-to-r"></div>
                    <div class="-mt-9 flex flex-col items-center px-5 pb-5 text-center">
                        <span class="{{ $look['avatar'] }} flex size-18 items-center justify-center overflow-hidden rounded-2xl text-2xl font-semibold shadow-md ring-4 ring-white dark:ring-zinc-950">@if ($photoUrl = $e->avatarUrl())<img src="{{ $photoUrl }}" alt="" class="size-full rounded-[inherit] object-cover">@else{{ $e->initials() }}@endif</span>
                        <p class="mt-3 text-lg font-semibold text-zinc-900 dark:text-white">{{ $e->name }}</p>
                        <p class="text-sm text-zinc-500">{{ $e->designation ?? __('No role set') }} · {{ __($meta['label']) }}</p>
                        <div class="mt-3 flex flex-wrap justify-center gap-1.5">
                            @if ($e->blood_group)
                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.heart variant="micro" class="size-3" />{{ $e->blood_group }}</span>
                            @endif
                            @if ($age)
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ trans_choice(':count year old|:count years old', $age) }}</span>
                            @endif
                            @if ($e->district)
                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-white/10 dark:text-zinc-300"><flux:icon.map-pin variant="micro" class="size-3" />{{ $e->district }}</span>
                            @endif
                        </div>

                        <div class="mt-5 w-full text-start">
                            <div class="flex justify-between text-xs"><span class="font-medium text-zinc-600 dark:text-zinc-300">{{ __('Profile complete') }}</span><span class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $completion }}%</span></div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div @class(['h-full rounded-full transition-[width] duration-700', 'bg-emerald-500' => $completion >= 80, 'bg-amber-400' => $completion >= 40 && $completion < 80, 'bg-rose-500' => $completion < 40]) style="width: {{ $completion }}%"></div></div>
                        </div>
                        <flux:button variant="primary" icon="pencil-square" class="mt-5 w-full" wire:click="openDetails" data-test="edit-details">{{ __('Edit details') }}</flux:button>
                    </div>
                </section>

                {{-- Contact --}}
                <section class="{{ $panel }} p-5">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Contact') }}</h3>
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ([['phone', $e->phone, $e->phone ? 'tel:'.preg_replace('/[^\d+]/', '', $e->phone) : null], ['envelope', $e->email, $e->email ? 'mailto:'.$e->email : null]] as [$cIcon, $cValue, $cHref])
                            <a @if ($cHref) href="{{ $cHref }}" @endif class="flex items-center gap-3 rounded-xl bg-zinc-50 px-3 py-2.5 text-sm transition hover:bg-zinc-100 dark:bg-white/[0.03] dark:hover:bg-white/5">
                                <span class="flex size-8 items-center justify-center rounded-lg bg-white text-zinc-500 shadow-xs ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/10"><flux:icon :icon="$cIcon" variant="micro" class="size-4" /></span>
                                <span @class(['min-w-0 truncate', 'font-medium text-zinc-800 dark:text-zinc-100' => $cValue, 'text-zinc-400' => ! $cValue])>{{ $cValue ?: __('Not added') }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>

                {{-- Emergency --}}
                <section class="overflow-hidden rounded-xl bg-linear-to-br from-rose-500 to-rose-600 p-5 text-white shadow-[0_18px_40px_-20px_rgb(225_29_72/0.6)]">
                    <div class="flex items-center gap-2">
                        <flux:icon.lifebuoy variant="mini" class="size-5" />
                        <h3 class="text-sm font-semibold">{{ __('Emergency contact') }}</h3>
                    </div>
                    @if ($e->emergency_name || $e->emergency_phone)
                        <p class="mt-3 text-lg font-semibold">{{ $e->emergency_name ?: __('No name') }}</p>
                        <p class="text-sm text-white/80">{{ $e->emergency_relation ?: __('Relation not set') }}</p>
                        @if ($e->emergency_phone)
                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $e->emergency_phone) }}" class="mt-4 flex items-center justify-center gap-2 rounded-lg bg-white py-2 text-sm font-semibold text-rose-600 transition hover:bg-white/90"><flux:icon.phone variant="micro" class="size-4" />{{ $e->emergency_phone }}</a>
                        @endif
                    @else
                        <p class="mt-3 text-sm text-white/85">{{ __('No emergency contact yet.') }}</p>
                        <button type="button" wire:click="openDetails" class="mt-4 w-full rounded-lg bg-white/15 py-2 text-sm font-semibold ring-1 ring-white/30 transition hover:bg-white/25">{{ __('Add emergency contact') }}</button>
                    @endif
                </section>
            </aside>

            {{-- Detail sections --}}
            <div class="min-w-0 gap-6 lg:columns-2">
                @foreach ($sections as $section)
                    <section class="{{ $panel }} mb-6 break-inside-avoid overflow-hidden">
                        <div class="flex items-center gap-3 border-b border-zinc-100 px-5 py-3.5 dark:border-white/5">
                            <span class="{{ $section['tone'] }} flex size-8 items-center justify-center rounded-lg"><flux:icon :icon="$section['icon']" variant="micro" class="size-4" /></span>
                            <h3 class="flex-1 font-semibold text-zinc-900 dark:text-white">{{ $section['title'] }}</h3>
                            <button type="button" wire:click="{{ $section['title'] === __('Work & pay') ? 'edit' : 'openDetails' }}" class="rounded-md p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Edit :section', ['section' => $section['title']]) }}"><flux:icon.pencil-square variant="micro" class="size-4" /></button>
                        </div>
                        <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                            @foreach ($section['rows'] as [$icon, $label, $value])
                                <div class="flex items-center gap-3 px-5 py-3">
                                    <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0 text-zinc-400" />
                                    <dt class="w-36 shrink-0 text-zinc-500">{{ $label }}</dt>
                                    <dd @class(['min-w-0 flex-1 truncate text-end sm:text-start', 'font-medium text-zinc-800 dark:text-zinc-100' => $value, 'text-zinc-300 dark:text-zinc-600' => ! $value])>{{ $value ?: '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>
        </div>
    @else
        {{-- Documents --}}
        <div class="grid items-start gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
            <div class="flex flex-col gap-6">
            {{-- Employment agreement --}}
            @php
                $agreementDocs = $docs->where('type', 'agreement');
                $agreeDays = $e->contract_end ? (int) today()->diffInDays($e->contract_end, false) : null;
                $agreePct = $e->contract_start && $e->contract_end && $e->contract_end->gt($e->contract_start)
                    ? (int) max(0, min(100, round($e->contract_start->diffInDays(today()) / $e->contract_start->diffInDays($e->contract_end) * 100))) : null;
            @endphp
            <section class="{{ $panel }} p-5">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Employment agreement') }}</h2>
                    <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
                </div>
                @if ($e->contract_start || $e->contract_end)
                    <div class="mt-4 flex items-center gap-3">
                        <div class="min-w-0 flex-1 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/[0.03]">
                            <p class="text-[0.6875rem] text-zinc-500">{{ __('Starts') }}</p>
                            <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $e->contract_start?->toNepali() ?: '-' }}</p>
                        </div>
                        <flux:icon.arrow-right variant="micro" class="size-4 shrink-0 text-zinc-300" />
                        <div class="min-w-0 flex-1 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/[0.03]">
                            <p class="text-[0.6875rem] text-zinc-500">{{ __('Ends') }}</p>
                            <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $e->contract_end?->toNepali() ?: '-' }}</p>
                        </div>
                    </div>
                    @if ($agreePct !== null)
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div @class(['h-full rounded-full', 'bg-rose-500' => $agreeDays < 0, 'bg-amber-400' => $agreeDays >= 0 && $agreeDays <= 30, 'bg-brand-500' => $agreeDays > 30]) style="width: {{ $agreePct }}%"></div></div>
                    @endif
                    @if ($agreeDays !== null)
                        <p @class(['mt-1.5 text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $agreeDays < 0, 'text-amber-600 dark:text-amber-400' => $agreeDays >= 0 && $agreeDays <= 30, 'text-zinc-500' => $agreeDays > 30])>
                            {{ $agreeDays < 0 ? __('Ended :count days ago', ['count' => abs($agreeDays)]) : trans_choice(':count day left|:count days left', $agreeDays) }}
                        </p>
                    @endif
                @else
                    <p class="mt-3 text-sm text-zinc-500">{{ __('No agreement dates yet.') }}</p>
                @endif
                <div class="mt-4 flex flex-col gap-1.5">
                    @foreach ($agreementDocs as $doc)
                        <a href="{{ route('employee-documents.download', $doc) }}" class="flex items-center gap-2.5 rounded-lg bg-zinc-50 px-3 py-2 text-sm transition hover:bg-zinc-100 dark:bg-white/[0.03] dark:hover:bg-white/5">
                            <flux:icon.document-check variant="micro" class="size-4 text-emerald-600" />
                            <span class="min-w-0 flex-1 truncate font-medium text-zinc-700 dark:text-zinc-200">{{ $doc->name }}</span>
                            <flux:icon.arrow-down-tray variant="micro" class="size-4 text-zinc-400" />
                        </a>
                    @endforeach
                    <button type="button" x-on:click="$wire.docType = 'agreement'; $nextTick(() => document.getElementById('employee-upload')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))" class="flex items-center justify-center gap-1.5 rounded-lg border border-dashed border-zinc-300 py-2 text-xs font-medium text-zinc-500 transition hover:border-brand-400 hover:text-brand-600 dark:border-white/15">
                        <flux:icon.arrow-up-tray variant="micro" class="size-3.5" />{{ $agreementDocs->isEmpty() ? __('Upload the signed agreement below') : __('Upload another below') }}
                    </button>
                </div>
            </section>
            <section id="employee-upload" class="{{ $panel }} scroll-mt-6 p-5">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Upload a document') }}</h2>
                <p class="mt-0.5 text-xs text-zinc-500">{{ __('Kept private: only signed-in users can open it.') }}</p>
                <form wire:submit="uploadDocument" class="mt-4 flex flex-col gap-4">
                    <div>
                        <span class="mb-2 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Type') }}</span>
                        <div class="grid grid-cols-2 gap-1.5">
                            @foreach (EmployeeDocument::TYPES as $key => $dt)
                                <button type="button" @click="$wire.docType = '{{ $key }}'" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs font-medium ring-1 transition" :class="$wire.docType === '{{ $key }}' ? 'bg-brand-50 text-brand-700 ring-2 ring-brand-500 dark:bg-brand-500/10 dark:text-brand-200' : 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:hover:bg-white/5'">
                                    <flux:icon :icon="$dt['icon']" variant="micro" class="size-4" />{{ __($dt['label']) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        @include('partials.file-drop', ['model' => 'docUpload', 'file' => $docUpload])
                    </div>
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray" data-test="upload-employee-document">{{ __('Upload') }}</flux:button>
                </form>
            </section>
            </div>

            <section class="{{ $panel }} overflow-hidden">
                <div class="flex items-center gap-2.5 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <h2 class="flex-1 font-semibold text-zinc-900 dark:text-white">{{ __('Documents') }}</h2>
                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 tabular-nums dark:bg-white/10 dark:text-zinc-300">{{ $docs->count() }}</span>
                </div>
                @forelse ($docs->groupBy('type') as $dtype => $items)
                    <div wire:key="doc-group-{{ $dtype }}" class="border-b border-zinc-100 last:border-0 dark:border-white/5">
                        <p class="bg-zinc-50/70 px-5 py-2 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:bg-white/[0.02]">{{ __(EmployeeDocument::TYPES[$dtype]['label'] ?? 'Other') }}</p>
                        @foreach ($items as $doc)
                            <div wire:key="doc-{{ $doc->id }}" class="group flex items-center gap-3 px-5 py-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-white/5"><flux:icon :icon="EmployeeDocument::TYPES[$dtype]['icon'] ?? 'paper-clip'" variant="micro" class="size-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $doc->name }}</p>
                                    <p class="text-xs text-zinc-500">{{ $doc->created_at->toNepali() }} · {{ $doc->size >= 1048576 ? number_format($doc->size / 1048576, 1).' MB' : max(1, (int) round($doc->size / 1024)).' KB' }}</p>
                                </div>
                                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('employee-documents.download', $doc)" :aria-label="__('Download')" />
                                <button type="button" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="{{ __('Delete this document?') }}" class="rounded-md p-1.5 text-zinc-300 transition hover:bg-rose-50 hover:text-rose-500 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete') }}"><flux:icon.trash variant="micro" class="size-4" /></button>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 px-6 py-14 text-center">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.document-text class="size-6" /></span>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('No documents yet') }}</p>
                        <p class="text-xs text-zinc-500">{{ __('Upload their agreement, ID, CV or certificates.') }}</p>
                    </div>
                @endforelse
            </section>
        </div>
    @endif

    {{-- Edit personal details --}}
    <flux:modal name="employee-details" class="w-full max-w-3xl p-0!">
        <form wire:submit="saveDetails" class="flex flex-col">
            <div class="flex items-center gap-4 border-b border-zinc-100 px-6 py-5 dark:border-white/5">
                <span class="{{ $look['avatar'] }} flex size-11 items-center justify-center overflow-hidden rounded-xl text-sm font-semibold">@if ($photoUrl = $e->avatarUrl())<img src="{{ $photoUrl }}" alt="" class="size-full rounded-[inherit] object-cover">@else{{ $e->initials() }}@endif</span>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg">{{ __('Edit details') }}</flux:heading>
                    <p class="text-sm text-zinc-500">{{ $e->name }}</p>
                </div>
            </div>

            <div class="flex max-h-[65vh] flex-col gap-7 overflow-y-auto px-6 py-6">
                @php
                    $sectionTitle = 'mb-3 flex items-center gap-2 text-xs font-semibold tracking-wider text-zinc-500 uppercase';
                @endphp
                <div>
                    <p class="{{ $sectionTitle }}"><flux:icon.user variant="micro" class="size-4" />{{ __('Personal') }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-nepali-date wire:model="info.date_of_birth" :label="__('Date of birth')" />
                        <flux:select wire:model="info.gender" :label="__('Gender')">
                            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                            @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $gk => $gl)
                                <flux:select.option :value="$gk">{{ __($gl) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="info.blood_group" :label="__('Blood group')">
                            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                            @foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg)
                                <flux:select.option :value="$bg">{{ $bg }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="info.marital_status" :label="__('Marital status')">
                            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                            @foreach (['single' => 'Single', 'married' => 'Married', 'other' => 'Other'] as $mk => $ml)
                                <flux:select.option :value="$mk">{{ __($ml) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div>
                    <p class="{{ $sectionTitle }}"><flux:icon.users variant="micro" class="size-4" />{{ __('Family') }}</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="info.father_name" :label="__('Father\'s name')" />
                        <flux:input wire:model="info.mother_name" :label="__('Mother\'s name')" />
                        <flux:input wire:model="info.spouse_name" :label="__('Spouse\'s name')" />
                    </div>
                </div>

                <div>
                    <p class="{{ $sectionTitle }}"><flux:icon.map-pin variant="micro" class="size-4" />{{ __('Address') }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="info.permanent_address" :label="__('Permanent address')" :placeholder="__('e.g. Bharatpur-10, Chitwan')" />
                        <flux:input wire:model="info.current_address" :label="__('Current address')" :placeholder="__('e.g. Baneshwor, Kathmandu')" />
                        <flux:input wire:model="info.district" :label="__('District')" />
                        <flux:select wire:model="info.province" :label="__('Province')">
                            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                            @foreach (['Koshi', 'Madhesh', 'Bagmati', 'Gandaki', 'Lumbini', 'Karnali', 'Sudurpashchim'] as $pv)
                                <flux:select.option :value="$pv">{{ $pv }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="rounded-xl bg-rose-50/60 p-4 ring-1 ring-rose-100 dark:bg-rose-500/5 dark:ring-rose-500/20">
                    <p class="{{ $sectionTitle }} text-rose-600! dark:text-rose-300!"><flux:icon.lifebuoy variant="micro" class="size-4" />{{ __('Emergency contact') }}</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="info.emergency_name" :label="__('Name')" />
                        <flux:input wire:model="info.emergency_relation" :label="__('Relation')" :placeholder="__('e.g. Brother')" />
                        <flux:input wire:model="info.emergency_phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" />
                    </div>
                </div>

                <div class="grid gap-7 sm:grid-cols-2">
                    <div>
                        <p class="{{ $sectionTitle }}"><flux:icon.identification variant="micro" class="size-4" />{{ __('Identity') }}</p>
                        <div class="flex flex-col gap-4">
                            <flux:input wire:model="info.citizenship_no" :label="__('Citizenship no.')" />
                            <flux:input wire:model="info.pan_no" :label="__('PAN no.')" />
                        </div>
                    </div>
                    <div>
                        <p class="{{ $sectionTitle }}"><flux:icon.building-library variant="micro" class="size-4" />{{ __('Bank') }}</p>
                        <div class="flex flex-col gap-4">
                            <flux:input wire:model="info.bank_name" :label="__('Bank name')" :placeholder="__('e.g. NIC Asia Bank')" />
                            <flux:input wire:model="info.bank_account_name" :label="__('Account name')" />
                            <flux:input wire:model="info.bank_account_no" :label="__('Account no.')" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-details">{{ __('Save details') }}</flux:button>
            </div>
        </form>
    </flux:modal>

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
                    @foreach (Employee::departments() as $key => $d)
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
                <x-nepali-date wire:model="joinedOn" :label="__('Joined on')" />
                <flux:input wire:model="salaryDay" type="number" min="1" max="32" step="1" :label="__('Salary day')" :placeholder="__('e.g. 1')" :description="__('Nepali day of each month')" />
                <x-nepali-date wire:model="contractStart" :label="__('Agreement starts')" />
                <x-nepali-date wire:model="contractEnd" :label="__('Agreement ends')" />
                <flux:error name="contractEnd" class="sm:col-span-2" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Pay salary --}}
    <flux:modal name="pay-salary" class="w-full max-w-lg overflow-visible!">
        <form wire:submit="savePay" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Pay salary') }}</flux:heading>
                <flux:text class="mt-1">{{ $e->name }}{{ $e->salary ? ' · '.$money($e->salary).__('/mo') : '' }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="payTitle" icon="pencil" :label="__('Payment title')" :placeholder="__('e.g. Ashoj salary')" maxlength="120" data-test="pay-title" />
                <flux:input wire:model="payAmount" type="number" min="0" step="1" :label="__('Amount (:currency)', ['currency' => config('app.currency')])" required />
                <x-salary-month-picker :employee="$e" />
                <x-nepali-date wire:model="payDate" :label="__('Paid date')" />
                <div class="sm:col-span-2">
                    <flux:input wire:model="payNote" :label="__('Note')" :placeholder="__('e.g. Advance, bonus, bank transfer')" />
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save payment') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Leave reason --}}
    <flux:modal name="leave-reason" class="w-full max-w-md">
        <form wire:submit="saveLeave" class="flex flex-col gap-5">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300"><flux:icon.sun variant="mini" class="size-5" /></span>
                <div>
                    <flux:heading size="lg">{{ __('Mark leave') }}</flux:heading>
                    <flux:text class="mt-0.5">{{ $leaveDate ? __(':name · :date', ['name' => $e->name, 'date' => \Illuminate\Support\Carbon::parse($leaveDate)->toNepali()]) : $e->name }}</flux:text>
                </div>
            </div>
            <flux:textarea wire:model="leaveReason" :label="__('Reason for leave')" :placeholder="__('e.g. Sick, family function, Dashain holiday')" rows="3" required autofocus />
            <div class="flex flex-wrap gap-1.5">
                @foreach ([__('Sick'), __('Family function'), __('Festival'), __('Personal work'), __('Travel')] as $preset)
                    <button type="button" @click="$wire.leaveReason = @js($preset)" class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 transition hover:bg-sky-50 hover:text-sky-700 dark:bg-white/5 dark:text-zinc-300">{{ $preset }}</button>
                @endforeach
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-leave">{{ __('Mark leave') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove --}}
    {{-- Delete salary payment --}}
    <flux:modal name="salary-payment-delete" class="w-full max-w-md p-0!">
        @php $deleting = $deletingPaymentId ? $payments->firstWhere('id', $deletingPaymentId) : null; @endphp
        <div class="flex flex-col items-center px-6 pt-8 pb-6 text-center">
            <span class="relative flex size-14 items-center justify-center rounded-full bg-rose-50 text-rose-600 ring-8 ring-rose-50/50 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/5">
                <flux:icon.trash class="size-6" />
            </span>
            <flux:heading size="lg" class="mt-5">{{ __('Delete this salary payment?') }}</flux:heading>
            <flux:text class="mt-1 max-w-xs">{{ __('It will be removed from :name\'s salary history and from Finance. This cannot be undone.', ['name' => $e->name]) }}</flux:text>

            @if ($deleting)
                <div class="mt-5 flex w-full items-center gap-3 rounded-xl bg-zinc-50 p-4 text-start ring-1 ring-zinc-200/70 dark:bg-white/[0.03] dark:ring-white/10">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white text-emerald-600 shadow-xs ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/10"><flux:icon.banknotes variant="mini" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $deleting->label() }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ __('Paid :date', ['date' => $deleting->paid_on->toNepali()]) }}@if ($deleting->note) · {{ $deleting->note }}@endif</p>
                    </div>
                    <span class="text-base font-bold text-zinc-900 tabular-nums dark:text-white">{{ $money($deleting->amount) }}</span>
                </div>
            @endif
        </div>
        <div class="grid grid-cols-2 gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
            <flux:modal.close><flux:button variant="ghost" class="w-full">{{ __('Keep it') }}</flux:button></flux:modal.close>
            <flux:button variant="danger" icon="trash" class="w-full" wire:click="deleteSalaryPayment({{ (int) $deletingPaymentId }})" :disabled="! $deleting" data-test="confirm-delete-salary">{{ __('Delete payment') }}</flux:button>
        </div>
    </flux:modal>

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
