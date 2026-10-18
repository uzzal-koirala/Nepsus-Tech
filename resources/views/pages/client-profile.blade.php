<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Task;
use App\Models\WorkType;
use Flux\Flux;
use App\Support\NepaliDate;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Client profile')] class extends Component {
    use WithFileUploads;

    public Client $client;

    public const TABS = ['overview', 'tasks', 'history', 'payments', 'documents', 'notes'];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $taskTitle = '';
    public string $taskDue = '';
    public string $taskType = 'task';
    public ?int $taskId = null;
    public string $taskDescription = '';
    public string $taskStatus = 'todo';
    public string $taskPriority = 'medium';
    public string $taskStart = '';
    /** @var list<int> */
    public array $taskAssignees = [];
    public string $newTypeName = '';
    public ?int $viewTaskId = null;
    public ?string $deletingType = null;


    /** BS month shown on the work calendar. */
    public int $calYear = 0;
    public int $calMonth = 0;

    public string $payAmount = '';
    public string $payDue = '';
    public string $payMethod = '';
    public string $payReference = '';
    public string $payType = '';
    public string $payNote = '';
    public $payProof;

    public string $contractStart = '';
    public string $contractEnd = '';
    public bool $agreementSigned = false;
    public $agreementFile;

    public $upload;
    public string $uploadType = 'invoice';

    public string $notes = '';

    public function mount(Client $client): void
    {
        $this->client = $client;
        [$this->calYear, $this->calMonth] = NepaliDate::fromAd(today());
    }

    // Work calendar

    public function shiftMonth(int $by): void
    {
        $index = $this->calYear * 12 + $this->calMonth - 1 + max(-1, min(1, $by));
        [$this->calYear, $this->calMonth] = [intdiv($index, 12), $index % 12 + 1];
    }

    public function goToday(): void
    {
        [$this->calYear, $this->calMonth] = NepaliDate::fromAd(today());
    }

    /**
     * Weeks of the shown BS month (Sunday first). Each day: BS day number, AD date, and its tasks and payments.
     *
     * @return array{label: string, range: string, weeks: list<list<array|null>>, tasks: \Illuminate\Support\Collection}
     */
    #[Computed]
    public function calendar(): array
    {
        $length = NepaliDate::monthLength($this->calYear, $this->calMonth);
        $first = NepaliDate::toAd($this->calYear, $this->calMonth, 1);
        $last = NepaliDate::toAd($this->calYear, $this->calMonth, $length);

        $inMonth = fn ($date) => $date && $date->between($first, $last->copy()->endOfDay());
        $tasks = $this->tasks->filter(fn ($t) => $inMonth($t->due_date));
        $payments = $this->payments->filter(fn ($p) => $inMonth($p->due_date));
        $tasksByDay = $tasks->groupBy(fn ($t) => $t->due_date->toDateString());
        $paymentsByDay = $payments->groupBy(fn ($p) => $p->due_date->toDateString());

        $cells = array_fill(0, $first->dayOfWeek, null);
        for ($day = 1; $day <= $length; $day++) {
            $ad = $first->copy()->addDays($day - 1);
            $key = $ad->toDateString();
            $cells[] = [
                'day' => $day,
                'ad' => $ad,
                'tasks' => $tasksByDay[$key] ?? collect(),
                'payments' => $paymentsByDay[$key] ?? collect(),
                'agreementEnds' => $this->client->contract_end?->toDateString() === $key,
            ];
        }
        $cells = array_pad($cells, (int) ceil(count($cells) / 7) * 7, null);

        return [
            'label' => NepaliDate::MONTHS[$this->calMonth - 1].' '.$this->calYear,
            'range' => $first->format('j M').' – '.$last->format('j M Y'),
            'weeks' => array_chunk($cells, 7),
            'tasks' => $tasks,
        ];
    }

    /** Copies last BS month's work onto the same days of the shown month (not done yet). */
    public function repeatLastMonth(): void
    {
        $index = $this->calYear * 12 + $this->calMonth - 2;
        [$py, $pm] = [intdiv($index, 12), $index % 12 + 1];
        $from = NepaliDate::toAd($py, $pm, 1);
        $to = NepaliDate::toAd($py, $pm, NepaliDate::monthLength($py, $pm));
        $length = NepaliDate::monthLength($this->calYear, $this->calMonth);

        $previous = $this->client->tasks()->with('assignees')->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)->get();

        foreach ($previous as $task) {
            $day = min(NepaliDate::fromAd($task->due_date)[2], $length);
            $this->client->tasks()->create([
                'title' => $task->title,
                'description' => $task->description,
                'type' => $task->type,
                'priority' => $task->priority,
                'due_date' => NepaliDate::toAd($this->calYear, $this->calMonth, $day),
            ])->assignees()->sync($task->assignees->pluck('id'));
        }

        unset($this->tasks, $this->calendar);
        Flux::toast(variant: $previous->isEmpty() ? 'warning' : 'success', text: $previous->isEmpty()
            ? __('Nothing was planned in :month.', ['month' => NepaliDate::MONTHS[$pm - 1]])
            : trans_choice(':count item copied from :month.|:count items copied from :month.', $previous->count(), ['month' => NepaliDate::MONTHS[$pm - 1]]));
    }

    public function addWorkOn(string $date): void
    {
        $this->newTask();
        $this->taskDue = \Illuminate\Support\Carbon::parse($date)->toDateString();
    }

    #[Computed]
    public function tasks()
    {
        return $this->client->tasks()
            ->with('assignees')
            ->orderByRaw('completed_at is not null')
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function payments()
    {
        return $this->client->payments()->orderByDesc('due_date')->latest('id')->get();
    }

    #[Computed]
    public function documents()
    {
        return $this->client->documents()->latest()->get();
    }

    /** Work types (editable from the work popup). */
    #[Computed]
    public function types(): array
    {
        return Task::types();
    }

    /** People who can be assigned to work. */
    #[Computed]
    public function team()
    {
        return Employee::where('status', '!=', 'inactive')->orderBy('name')->get();
    }

    public function addWorkType(): void
    {
        $data = $this->validate(['newTypeName' => ['required', 'string', 'max:40']], attributes: ['newTypeName' => __('type name')]);

        $existing = WorkType::whereRaw('lower(name) = ?', [mb_strtolower(trim($data['newTypeName']))])->first();
        $type = $existing ?? WorkType::add(trim($data['newTypeName']));

        $this->taskType = $type->slug;
        $this->reset('newTypeName');
        unset($this->types);
    }

    public function confirmDeleteType(string $slug): void
    {
        $this->deletingType = $slug;
        Flux::modal('type-delete')->show();
    }

    public function deleteWorkType(string $slug): void
    {
        $this->deletingType = null;
        Flux::modal('type-delete')->close();
        if (WorkType::count() <= 1) {
            Flux::toast(variant: 'warning', text: __('Keep at least one type of work.'));

            return;
        }

        WorkType::where('slug', $slug)->delete();
        unset($this->types);
        // Its box goes away; any work in it moves to the general type so nothing is lost.
        $fallback = array_key_exists('task', $this->types) ? 'task' : (string) array_key_first($this->types);
        Task::where('type', $slug)->update(['type' => $fallback]);
        unset($this->tasks);

        if ($this->taskType === $slug) {
            $this->taskType = array_key_first($this->types);
        }
    }

    private function defaultType(): string
    {
        $wanted = $this->client->service_type === 'social_media' ? 'post' : 'task';

        return array_key_exists($wanted, $this->types) ? $wanted : (string) array_key_first($this->types);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS) ? $tab : 'overview';
    }

    /** The earliest unpaid payment, for the "Next up" card. */
    #[Computed]
    public function nextPayment(): ?Payment
    {
        return $this->payments->whereNull('paid_at')->sortBy(fn ($p) => $p->due_date?->timestamp ?? PHP_INT_MAX)->first();
    }

    #[Computed]
    public function stats(): array
    {
        $payments = $this->payments;
        $tasks = $this->tasks;

        return [
            'billed' => $payments->sum('amount'),
            'received' => $payments->whereNotNull('paid_at')->sum('amount'),
            'overduePayments' => $payments->filter(fn ($p) => $p->status() === 'overdue')->count(),
            'tasksTotal' => $tasks->count(),
            'tasksDone' => $tasks->whereNotNull('completed_at')->count(),
            'tasksOverdue' => $tasks->filter->isOverdue()->count(),
        ];
    }

    // Tasks

    public function newTask(string $status = 'todo', ?string $type = null): void
    {
        $this->reset('taskTitle', 'taskDue', 'taskId', 'taskDescription', 'taskStart', 'taskAssignees', 'newTypeName');
        $this->taskType = $type !== null && array_key_exists($type, $this->types) ? $type : $this->defaultType();
        $this->taskStatus = array_key_exists($status, Task::STATUSES) ? $status : 'todo';
        $this->taskPriority = 'medium';
        $this->resetErrorBag();
        Flux::modal('task-form')->show();
    }

    public function openTask(int $id): void
    {
        $this->viewTaskId = $this->client->tasks()->findOrFail($id)->id;
        Flux::modal('task-view')->show();
    }

    #[Computed]
    public function viewTask(): ?Task
    {
        return $this->viewTaskId ? $this->client->tasks()->with('assignees')->find($this->viewTaskId) : null;
    }

    public function editTask(int $id): void
    {
        Flux::modal('task-view')->close();
        $task = $this->client->tasks()->findOrFail($id);

        $this->resetErrorBag();
        $this->taskId = $task->id;
        $this->taskTitle = $task->title;
        $this->taskDescription = (string) $task->description;
        $this->taskType = $task->type;
        $this->taskStatus = $task->stage();
        $this->taskPriority = $task->priority;
        $this->taskStart = (string) $task->start_date?->toDateString();
        $this->taskDue = (string) $task->due_date?->toDateString();
        $this->taskAssignees = $task->assignees->pluck('id')->all();
        $this->reset('newTypeName');
        Flux::modal('task-form')->show();
    }

    public function addTask(): void
    {
        $data = $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDescription' => ['nullable', 'string', 'max:2000'],
            'taskStart' => ['nullable', 'date'],
            'taskDue' => array_filter(['nullable', 'date', $this->taskStart ? 'after_or_equal:taskStart' : null]),
            'taskType' => ['required', Rule::exists('work_types', 'slug')],
            'taskAssignees' => ['array'],
            'taskAssignees.*' => ['integer', Rule::exists('employees', 'id')],
            'taskStatus' => ['required', Rule::in(array_keys(Task::STATUSES))],
            'taskPriority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
        ], attributes: ['taskDue' => __('due date'), 'taskStart' => __('start date')]);

        $task = $this->taskId ? $this->client->tasks()->findOrFail($this->taskId) : $this->client->tasks()->make();
        $task->fill([
            'title' => $data['taskTitle'],
            'description' => $data['taskDescription'] ?: null,
            'type' => $data['taskType'],
            'status' => $data['taskStatus'],
            'priority' => $data['taskPriority'],
            'start_date' => $data['taskStart'] ?: null,
            'due_date' => $data['taskDue'] ?: null,
            'completed_at' => $data['taskStatus'] === 'done' ? ($task->completed_at ?? now()) : null,
        ])->save();
        $task->assignees()->sync($data['taskAssignees'] ?? []);

        Flux::modal('task-form')->close();
        Flux::toast(variant: 'success', text: $this->taskId ? __('Task updated.') : __('Task added.'));
        $this->reset('taskTitle', 'taskDue', 'taskId', 'taskDescription', 'taskStart', 'taskAssignees');
    }

    public function toggleTask(int $id): void
    {
        $task = $this->client->tasks()->findOrFail($id);
        $task->update($task->completed_at ? ['completed_at' => null, 'status' => 'todo'] : ['completed_at' => now(), 'status' => 'done']);
    }

    public function setTaskStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, Task::STATUSES), 422);
        $task = $this->client->tasks()->findOrFail($id);
        $task->update(['status' => $status, 'completed_at' => $status === 'done' ? ($task->completed_at ?? now()) : null]);
    }

    /** Adds one employee to every open task of a work type. */
    public function assignBox(string $type, int $employeeId): void
    {
        $employee = Employee::findOrFail($employeeId);
        $tasks = $this->client->tasks()->where('type', $type)->whereNull('completed_at')->get();

        foreach ($tasks as $task) {
            $task->assignees()->syncWithoutDetaching([$employee->id]);
        }

        unset($this->tasks);
        Flux::toast(variant: 'success', text: trans_choice(':name assigned to :count task.|:name assigned to :count tasks.', $tasks->count(), ['name' => $employee->name]));
    }

    public function completeBox(string $type): void
    {
        $this->client->tasks()->where('type', $type)->whereNull('completed_at')->update(['status' => 'done', 'completed_at' => now()]);
        unset($this->tasks);
    }

    public function setTaskPriority(int $id, string $priority): void
    {
        abort_unless(array_key_exists($priority, Task::PRIORITIES), 422);
        $this->client->tasks()->findOrFail($id)->update(['priority' => $priority]);
    }

    public function deleteTask(int $id): void
    {
        $this->client->tasks()->findOrFail($id)->delete();

        if ($this->viewTaskId === $id) {
            $this->viewTaskId = null;
            Flux::modal('task-view')->close();
        }
    }

    // Payments

    // Payment plan

    public function newPayment(): void
    {
        $this->reset('payAmount', 'payDue', 'payMethod', 'payReference', 'payNote', 'payProof');
        $this->payType = __(Payment::TYPES[$this->client->isRecurring() ? 'monthly' : ($this->payments->isEmpty() ? 'advance' : 'partial')]['label']);
        $this->payAmount = $this->client->price === null ? '' : rtrim(rtrim((string) $this->client->price, '0'), '.');
        $this->payDue = today()->toDateString();
        $this->resetErrorBag();
        Flux::modal('payment-form')->show();
    }

    public function savePayment(): void
    {
        $data = $this->validate([
            'payAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payDue' => ['required', 'date'],
            'payMethod' => ['required', Rule::in(Payment::METHODS)],
            'payReference' => ['nullable', 'string', 'max:255'],
            'payType' => ['required', 'string', 'max:60'],
            'payNote' => ['nullable', 'string', 'max:255'],
            'payProof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], attributes: ['payDue' => __('payment date'), 'payMethod' => __('received in'), 'payProof' => __('payment proof')]);

        $this->client->payments()->create([
            'amount' => $data['payAmount'],
            'type' => trim($data['payType']),
            'due_date' => $data['payDue'] ?: null,
            'method' => $data['payMethod'],
            'reference' => $data['payReference'] ?: null,
            'note' => $data['payNote'] ?: null,
            'paid_at' => Date::parse($data['payDue'] ?: today())->setTimeFrom(now()),
            'proof_path' => $this->payProof?->store('payment-proofs/'.$this->client->id, 'local'),
        ]);
        $this->reset('payProof');

        Flux::modal('payment-form')->close();
        Flux::toast(variant: 'success', text: __('Payment recorded.'));
    }

    public function updatedPayProof(): void
    {
        try {
            $this->validateOnly('payProof', ['payProof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192']], attributes: ['payProof' => __('payment proof')]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('payProof');
            throw $e;
        }
    }

    public function togglePaid(int $id): void
    {
        $payment = $this->client->payments()->findOrFail($id);
        $payment->update(['paid_at' => $payment->paid_at ? null : now()]);
    }

    public function deletePayment(int $id): void
    {
        $this->client->payments()->findOrFail($id)->delete();
        Flux::toast(text: __('Payment deleted.'));
    }

    // Agreement

    public function editAgreement(): void
    {
        $this->contractStart = (string) $this->client->contract_start?->toDateString();
        $this->contractEnd = (string) $this->client->contract_end?->toDateString();
        $this->agreementSigned = $this->client->agreement_signed_at !== null;
        $this->reset('agreementFile');
        $this->resetErrorBag();
        Flux::modal('agreement-form')->show();
    }

    public function saveAgreement(): void
    {
        $data = $this->validate([
            'contractStart' => ['nullable', 'date'],
            'contractEnd' => ['nullable', 'date', 'after_or_equal:contractStart'],
            'agreementSigned' => ['boolean'],
            'agreementFile' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,png,jpg,jpeg,webp'],
        ]);

        $this->client->update([
            'contract_start' => $data['contractStart'] ?: null,
            'contract_end' => $data['contractEnd'] ?: null,
            'agreement_signed_at' => $data['agreementSigned'] ? ($this->client->agreement_signed_at ?? now()) : null,
        ]);

        if ($this->agreementFile) {
            $this->storeDocument($this->agreementFile, 'agreement');
        }

        $this->reset('agreementFile');
        Flux::modal('agreement-form')->close();
        Flux::toast(variant: 'success', text: __('Agreement saved.'));
    }

    // Documents

    public function newDocument(): void
    {
        $this->reset('upload');
        $this->uploadType = 'invoice';
        $this->resetErrorBag();
        Flux::modal('document-form')->show();
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,png,jpg,jpeg,webp'],
            'uploadType' => ['required', Rule::in(array_keys(Document::TYPES))],
        ]);

        $this->storeDocument($this->upload, $this->uploadType);
        $this->reset('upload');

        Flux::modal('document-form')->close();
        Flux::toast(variant: 'success', text: __('Document uploaded.'));
    }

    public function deleteDocument(int $id): void
    {
        $this->client->documents()->findOrFail($id)->delete();
        Flux::toast(text: __('Document deleted.'));
    }

    private function storeDocument($file, string $type): void
    {
        $this->client->documents()->create([
            'type' => $type,
            'name' => $file->getClientOriginalName(),
            'path' => $file->store("clients/{$this->client->id}", 'local'),
            'size' => $file->getSize(),
        ]);
    }

    // Notes

    public function editNotes(): void
    {
        $this->notes = (string) $this->client->notes;
        $this->resetErrorBag();
        Flux::modal('notes-form')->show();
    }

    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $this->client->update(['notes' => $this->notes ?: null]);

        Flux::modal('notes-form')->close();
        Flux::toast(variant: 'success', text: __('Notes saved.'));
    }
}; ?>

@php
    $c = $client;
    $pkg = $c->package;
    $theme = $pkg?->theme();
    $stats = $this->stats;
    $cycles = ['monthly' => __('/mo'), 'yearly' => __('/yr'), 'one_time' => ' · '.__('one-time')];

    $badges = [
        'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300',
        'inactive' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/15 dark:bg-white/5 dark:text-zinc-400',
    ];
    $dots = ['active' => 'bg-emerald-500', 'inactive' => 'bg-zinc-400'];
    $payBadges = [
        'paid' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300',
        'pending' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/15 dark:bg-white/5 dark:text-zinc-300',
        'overdue' => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300',
    ];
    $socials = [
        'facebook' => ['label' => 'Facebook', 'class' => 'hover:bg-[#1877F2]/10 hover:text-[#1877F2]'],
        'instagram' => ['label' => 'Instagram', 'class' => 'hover:bg-[#E4405F]/10 hover:text-[#E4405F]'],
        'tiktok' => ['label' => 'TikTok', 'class' => 'hover:bg-zinc-900/[0.06] hover:text-zinc-900 dark:hover:bg-white/10 dark:hover:text-white'],
        'google' => ['label' => __('Google Business Profile'), 'class' => 'hover:bg-[#4285F4]/10 hover:text-[#4285F4]'],
    ];

    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $types = $this->types;
    $palette = WorkType::PALETTE;
    // Colours per work type slug; unknown (deleted) types fall back to grey.
    $workTones = collect($types)->map(fn ($t) => $palette[$t['color']] ?? $palette['zinc'])->all();
    $typeOf = fn (?string $slug) => $types[$slug] ?? Task::unknownType($slug);
    $toneOf = fn (?string $slug) => $workTones[$slug] ?? $palette['zinc'];
    $statusTones = [
        'todo' => ['dot' => 'bg-zinc-400', 'pill' => 'bg-zinc-200/70 text-zinc-700 dark:bg-white/10 dark:text-zinc-200'],
        'in_progress' => ['dot' => 'bg-brand-500', 'pill' => 'bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200'],
        'review' => ['dot' => 'bg-amber-400', 'pill' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200'],
        'done' => ['dot' => 'bg-emerald-500', 'pill' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200'],
    ];
    $priorityTones = [
        'low' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20',
        'medium' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'high' => 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
        'urgent' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
    ];
    $methodLook = [
        'Bank' => ['icon' => 'building-library', 'tile' => 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300', 'hint' => __('Bank transfer or deposit')],
        'eSewa' => ['icon' => 'device-phone-mobile', 'tile' => 'bg-[#60BB46]/15 text-[#3f8f2b] dark:text-[#7fd067]', 'hint' => __('eSewa wallet')],
        'Khalti' => ['icon' => 'wallet', 'tile' => 'bg-[#5C2D91]/12 text-[#5C2D91] dark:bg-[#5C2D91]/30 dark:text-violet-200', 'hint' => __('Khalti wallet')],
        'Cash' => ['icon' => 'banknotes', 'tile' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'hint' => __('Paid in cash')],
        'Cheque' => ['icon' => 'document-text', 'tile' => 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300', 'hint' => __('Cheque payment')],
        'Other' => ['icon' => 'ellipsis-horizontal-circle', 'tile' => 'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-400', 'hint' => __('Anything else')],
    ];
    $panelHead = 'flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-white/5';
    $iconTile = 'flex size-9 shrink-0 items-center justify-center rounded-lg';
    $paidPct = $stats['billed'] > 0 ? (int) round($stats['received'] / $stats['billed'] * 100) : 0;
    $taskPct = $stats['tasksTotal'] > 0 ? (int) round($stats['tasksDone'] / $stats['tasksTotal'] * 100) : 0;
    $fileSize = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';

    $agreementDocs = $this->documents->where('type', 'agreement');
    $otherDocs = $this->documents->where('type', '!=', 'agreement');
    $hasAgreement = $c->contract_start || $c->contract_end || $c->agreement_signed_at || $agreementDocs->isNotEmpty();
    $contractPct = null;
    if ($c->contract_start && $c->contract_end && $c->contract_end->gt($c->contract_start)) {
        $contractPct = (int) max(0, min(100, round($c->contract_start->diffInDays(today()) / $c->contract_start->diffInDays($c->contract_end) * 100)));
    }
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Breadcrumb --}}
    <nav class="flex items-center gap-1.5 text-sm" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('clients.index') }}" wire:navigate class="font-medium text-zinc-500 transition hover:text-brand-600 dark:text-zinc-400">{{ __('Clients') }}</a>
        <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-300" />
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-100">{{ $c->displayName() }}</span>
    </nav>

    {{-- Header --}}
    @php
        $since = $c->contract_start ?? $c->created_at;

        // Countdown for the cover: months left for monthly clients, days to the deadline for projects.
        $countdown = null;
        $today = today();
        if ($c->isRecurring() && $c->contract_start && $c->contract_end) {
            $totalMonths = max(1, (int) round($c->contract_start->diffInMonths($c->contract_end->copy()->addDay())));
            $usedMonths = (int) min($totalMonths, max(0, floor($c->contract_start->diffInMonths($today))));
            $daysToEnd = (int) $today->diffInDays($c->contract_end, false);
            $monthsLeft = max(0, $totalMonths - $usedMonths);
            $countdown = [
                'icon' => 'calendar-days',
                'label' => __('Agreement'),
                'value' => match (true) {
                    $daysToEnd < 0 => __('Ended'),
                    $monthsLeft <= 1 => trans_choice(':count day left|:count days left', $daysToEnd),
                    default => trans_choice(':count month left|:count months left', $monthsLeft),
                },
                'sub' => $daysToEnd < 0
                    ? __('Ended :date · renew the agreement', ['date' => $c->contract_end->toNepali()])
                    : __('Month :now of :total · ends :date', ['now' => min($totalMonths, $usedMonths + 1), 'total' => $totalMonths, 'date' => $c->contract_end->toNepali()]),
                'pct' => (int) round($usedMonths / $totalMonths * 100),
                'alert' => $daysToEnd < 0 || $daysToEnd <= 30,
            ];
        } elseif ($c->isRecurring() && ($periodEnd = $c->periodEnd())) {
            $daysToEnd = (int) $today->diffInDays($periodEnd);
            $countdown = [
                'icon' => 'arrow-path',
                'label' => __('Billing period'),
                'value' => $daysToEnd === 0 ? __('Renews today') : trans_choice('Renews in :count day|Renews in :count days', $daysToEnd),
                'sub' => __('Period ends :date', ['date' => $periodEnd->toNepali()]),
                'pct' => (int) max(0, min(100, round(100 - $daysToEnd / max(1, $c->billing_months * 30) * 100))),
                'alert' => $daysToEnd <= 3,
            ];
        } elseif (! $c->isRecurring() && $c->contract_end) {
            $daysToEnd = (int) $today->diffInDays($c->contract_end, false);
            $countdown = [
                'icon' => 'flag',
                'label' => __('Project deadline'),
                'value' => match (true) {
                    $daysToEnd < 0 => trans_choice(':count day overdue|:count days overdue', abs($daysToEnd)),
                    $daysToEnd === 0 => __('Due today'),
                    default => trans_choice(':count day left|:count days left', $daysToEnd),
                },
                'sub' => __('Deadline :date', ['date' => $c->contract_end->toNepali()]),
                'pct' => $contractPct ?? 100,
                'alert' => $daysToEnd <= 7,
            ];
        }

        $wa = null;
        if ($c->phone) {
            $wa = preg_replace('/\D/', '', $c->phone);
            $wa = strlen($wa) === 10 && str_starts_with($wa, '9') ? '977'.$wa : $wa;
        }
        $metaLabel = 'text-[0.6875rem] font-medium tracking-wider text-zinc-400 uppercase';
        $metaValue = 'mt-1 flex min-w-0 items-center gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-100';
        $metaIcon = 'flex size-7 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400';
    @endphp
    <header class="{{ $panel }} overflow-hidden">
        {{-- Cover --}}
        <div class="{{ $theme['cover'] ?? 'from-brand-400 via-brand-500 to-brand-700 text-white' }} relative h-32 overflow-hidden bg-linear-to-br sm:h-36">
            <div class="absolute -top-16 left-1/3 size-56 rounded-full bg-white/20 blur-3xl"></div>
            <div class="absolute -right-10 -bottom-24 size-64 rounded-full bg-sun/40 blur-3xl"></div>
            <div class="absolute inset-0 opacity-30 [background-image:radial-gradient(currentColor_1px,transparent_1px)] [background-size:16px_16px] [mask-image:linear-gradient(to_left,black,transparent_65%)]"></div>
            <svg class="absolute inset-x-0 bottom-0 h-10 w-full text-white/10" viewBox="0 0 1200 40" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 40V22C200 0 400 0 600 18s400 22 600 0v22Z" /></svg>

            @if ($countdown)
                <div class="absolute top-1/2 right-6 hidden w-72 -translate-y-1/2 rounded-2xl bg-white p-4 text-zinc-900 shadow-[0_18px_40px_-18px_rgb(15_23_42/0.5)] ring-1 ring-black/5 sm:block dark:bg-zinc-900 dark:text-white dark:ring-white/10" data-test="profile-countdown">
                    <div class="flex items-center gap-2">
                        <span @class(['flex size-7 items-center justify-center rounded-lg', 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $countdown['alert'], 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300' => ! $countdown['alert']])>
                            <flux:icon :icon="$countdown['icon']" variant="micro" class="size-4" />
                        </span>
                        <span class="text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ $countdown['label'] }}</span>
                        @if ($countdown['alert'])
                            <span class="ms-auto rounded-full bg-amber-100 px-2 py-0.5 text-[0.6875rem] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">{{ __('Soon') }}</span>
                        @endif
                    </div>
                    <p @class(['mt-2.5 text-2xl leading-none font-bold tracking-tight', 'text-amber-600 dark:text-amber-400' => $countdown['alert'], 'text-zinc-900 dark:text-white' => ! $countdown['alert']])>{{ $countdown['value'] }}</p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div @class(['h-full rounded-full transition-[width] duration-700', 'bg-amber-400' => $countdown['alert'], 'bg-brand-500' => ! $countdown['alert']]) style="width: {{ $countdown['pct'] }}%"></div></div>
                    <p class="mt-2 truncate text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $countdown['sub'] }}</p>
                </div>
            @endif
        </div>

        <div class="px-5 pb-5 sm:px-7">
            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
                {{-- Identity --}}
                <div class="flex min-w-0 items-end gap-4 sm:gap-5">
                    <span class="relative -mt-10 shrink-0 sm:-mt-14">
                        <x-client-avatar :client="$c" tint="bg-linear-to-br from-brand-50 to-brand-100 text-brand-700 dark:from-brand-500/30 dark:to-brand-500/20 dark:text-white" class="size-20 rounded-2xl text-2xl sm:size-28 sm:rounded-3xl sm:text-4xl shadow-[0_18px_40px_-18px_rgb(15_23_42/0.45)] ring-[5px] ring-white dark:ring-zinc-950" />
                        <span class="{{ $dots[$c->status] }} absolute end-0 bottom-0 size-5 rounded-full ring-4 ring-white dark:ring-zinc-950" title="{{ ucfirst($c->status) }}"></span>
                    </span>
                    <div class="min-w-0 pb-1">
                        <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-[-0.025em] text-zinc-900 sm:text-[1.875rem] dark:text-white">
                            <span class="truncate">{{ $c->displayName() }}</span>
                            @if ($c->agreement_signed_at)<x-verified-badge class="size-6" />@endif
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="{{ $badges[$c->status] }} inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset">
                                <span class="{{ $dots[$c->status] }} size-1.5 rounded-full"></span>{{ __($c->status) }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-700 dark:bg-white/10 dark:text-zinc-200">
                                <flux:icon :icon="Client::SERVICE_TYPES[$c->service_type]['icon'] ?? 'cube'" variant="micro" class="size-3.5" />
                                {{ __(Client::SERVICE_TYPES[$c->service_type]['label'] ?? 'Client') }}
                            </span>
                            @if ($pkg)
                                <span class="{{ $theme['chip'] }} inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold">
                                    <flux:icon :icon="$pkg->icon" variant="micro" class="size-3.5" />{{ $pkg->name }}
                                </span>
                            @endif
                            @if ($countdown)
                                <span @class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold sm:hidden', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $countdown['alert'], 'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200' => ! $countdown['alert']])>
                                    <flux:icon :icon="$countdown['icon']" variant="micro" class="size-3.5" />{{ $countdown['value'] }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex flex-wrap items-center gap-2 pb-1">
                    @if ($c->socialLinks())
                        <div class="flex items-center rounded-xl bg-zinc-50 p-1 ring-1 ring-zinc-200/70 dark:bg-white/5 dark:ring-white/10">
                            @foreach ($c->socialLinks() as $network => $link)
                                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="{{ $socials[$network]['class'] }} flex size-8 items-center justify-center rounded-lg text-zinc-400 transition" title="{{ $socials[$network]['label'] }}">
                                    <x-social-icon :name="$network" class="size-4" />
                                    <span class="sr-only">{{ $socials[$network]['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($c->phone)
                        <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener noreferrer" class="flex h-10 items-center gap-2 rounded-lg bg-[#25D366]/10 px-3 text-sm font-medium text-[#128C7E] ring-1 ring-[#25D366]/25 transition hover:bg-[#25D366]/15 dark:text-[#25D366]" title="WhatsApp">
                            <x-social-icon name="whatsapp" class="size-4" /><span class="max-sm:sr-only">WhatsApp</span>
                        </a>
                        <flux:button :href="'tel:'.preg_replace('/[^\d+]/', '', $c->phone)" icon="phone" :aria-label="__('Call')" class="max-sm:px-2.5">{{ __('Call') }}</flux:button>
                    @endif
                    <flux:button icon="pencil-square" :href="route('clients.index', ['open' => $c->id])" wire:navigate>{{ __('Edit') }}</flux:button>
                    <flux:dropdown align="end">
                        <flux:button variant="primary" icon="plus" icon:trailing="chevron-down" class="shadow-lg shadow-brand-500/25" data-test="profile-add-menu">{{ __('Add') }}</flux:button>
                        <flux:menu>
                            <flux:menu.item icon="check-circle" wire:click="newTask">{{ __('Task') }}</flux:menu.item>
                            <flux:menu.item icon="banknotes" wire:click="newPayment">{{ __('Payment') }}</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="arrow-up-tray" wire:click="newDocument">{{ __('Document') }}</flux:menu.item>
                            <flux:menu.item icon="document-check" wire:click="editAgreement">{{ __('Agreement') }}</flux:menu.item>
                            <flux:menu.item icon="pencil" wire:click="editNotes">{{ __('Note') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            {{-- Key details --}}
            <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-zinc-100 pt-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5 dark:border-white/5">
                <div class="min-w-0">
                    <dt class="{{ $metaLabel }}">{{ __('Contact person') }}</dt>
                    <dd class="{{ $metaValue }}"><span class="{{ $metaIcon }}"><flux:icon.user variant="micro" class="size-3.5" /></span><span class="truncate">{{ $c->contactName() }}</span></dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $metaLabel }}">{{ __('Phone') }}</dt>
                    <dd class="{{ $metaValue }}">
                        <span class="{{ $metaIcon }}"><flux:icon.phone variant="micro" class="size-3.5" /></span>
                        @if ($c->phone)
                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $c->phone) }}" class="truncate tabular-nums hover:text-brand-600 dark:hover:text-brand-300">{{ $c->phone }}</a>
                        @else
                            <span class="font-normal text-zinc-400">{{ __('Not added') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $metaLabel }}">{{ __('Email') }}</dt>
                    <dd class="{{ $metaValue }}">
                        <span class="{{ $metaIcon }}"><flux:icon.envelope variant="micro" class="size-3.5" /></span>
                        @if ($c->email)
                            <a href="mailto:{{ $c->email }}" class="truncate hover:text-brand-600 dark:hover:text-brand-300" title="{{ $c->email }}">{{ $c->email }}</a>
                        @else
                            <span class="font-normal text-zinc-400">{{ __('Not added') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $metaLabel }}">{{ __('Client since') }}</dt>
                    <dd class="{{ $metaValue }}" title="{{ $since->format('j M Y') }}"><span class="{{ $metaIcon }}"><flux:icon.calendar variant="micro" class="size-3.5" /></span><span class="truncate">{{ $since->toNepali() }}</span></dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $metaLabel }}">{{ __('Agreement') }}</dt>
                    <dd class="{{ $metaValue }}">
                        @if ($c->agreement_signed_at)
                            <span class="{{ $metaIcon }} bg-emerald-50! text-emerald-600! dark:bg-emerald-500/10!"><flux:icon.document-check variant="micro" class="size-3.5" /></span>
                            <span class="truncate">{{ __('Signed') }}@if ($c->agreement_signed_at)<span class="font-normal text-zinc-500"> · {{ $c->agreement_signed_at->toNepali() }}</span>@endif</span>
                        @else
                            <span class="{{ $metaIcon }} bg-amber-50! text-amber-600! dark:bg-amber-500/10!"><flux:icon.document variant="micro" class="size-3.5" /></span>
                            <button type="button" wire:click="editAgreement" class="truncate text-amber-700 hover:underline dark:text-amber-400">{{ __('Not signed yet') }}</button>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </header>

    {{-- Summary --}}
    @php
        $due = max(0, $stats['billed'] - $stats['received']);
        $np = $this->nextPayment;
        $openCount = $stats['tasksTotal'] - $stats['tasksDone'];
        $deadlineTone = fn (int $days) => match (true) {
            $days < 0 => 'text-rose-600 dark:text-rose-400',
            $days <= 3 => 'text-amber-600 dark:text-amber-400',
            default => 'text-zinc-500',
        };
        $agreementDays = $c->contract_end ? (int) today()->diffInDays($c->contract_end, false) : null;
    @endphp
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{-- Package: name, price and billing together --}}
        <div class="{{ $theme['card'] ?? 'from-brand-50 ring-brand-200/80 dark:from-brand-500/[0.09] dark:ring-brand-500/25' }} relative flex flex-col gap-4 overflow-hidden rounded-xl bg-white bg-linear-to-br to-white to-70% p-5 ring-1 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:to-zinc-950">
            <div class="relative flex items-center gap-3">
                <span class="{{ $theme['cover'] ?? 'from-brand-400 via-brand-500 to-brand-700 text-white' }} flex size-9 shrink-0 items-center justify-center rounded-lg bg-linear-to-br shadow-md shadow-zinc-900/10">
                    <flux:icon :icon="$pkg->icon ?? (Client::SERVICE_TYPES[$c->service_type]['icon'] ?? 'cube')" variant="mini" class="size-4.5" />
                </span>
                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $pkg ? __(':name package', ['name' => $pkg->name]) : ($c->isRecurring() ? __('No package') : __(Client::SERVICE_TYPES[$c->service_type]['label'] ?? 'Project')) }}</p>
            </div>
            @if ($c->price !== null)
                <p class="relative flex items-baseline gap-1 tabular-nums">
                    <span class="text-sm font-medium text-zinc-400">{{ config('app.currency') }}</span>
                    <span class="text-[1.875rem] leading-none font-semibold tracking-tight text-zinc-900 dark:text-white">{{ number_format((float) $c->price) }}</span>
                    @if ($c->service_type === 'video')
                        <span class="text-sm text-zinc-400">{{ __('per video') }}</span>
                    @endif
                </p>
            @else
                <p class="relative text-[1.625rem] leading-none font-semibold text-zinc-300 dark:text-zinc-600">{{ __('Price not set') }}</p>
            @endif
            <p class="relative mt-auto inline-flex items-center gap-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                <flux:icon :icon="$c->isRecurring() ? 'arrow-path' : 'calendar'" variant="micro" class="size-3.5 text-zinc-400" />{{ $c->billingText() }}
            </p>
        </div>

        {{-- Payment overview --}}
        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span @class([$iconTile, 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' => $stats['overduePayments'], 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => ! $stats['overduePayments']])><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Outstanding') }}</p>
                @if ($stats['overduePayments'])
                    <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $stats['overduePayments']) }}</span>
                @endif
            </div>
            <div>
                <p @class(['text-[1.625rem] leading-none font-semibold tracking-tight tabular-nums', 'text-rose-600 dark:text-rose-400' => $stats['overduePayments'], 'text-zinc-900 dark:text-white' => ! $stats['overduePayments']])>{{ Client::money($due) }}</p>
                <p class="mt-1.5 text-xs text-zinc-500">{{ __(':received received of :billed', ['received' => Client::money($stats['received']), 'billed' => Client::money($stats['billed'])]) }}</p>
            </div>
            <div class="mt-auto flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-xs ring-1 ring-zinc-100 dark:bg-white/[0.03] dark:ring-white/5">
                @if ($np && $np->due_date)
                    @php $npDays = $np->dueInDays(); @endphp
                    <flux:icon.calendar variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                    <span class="min-w-0 flex-1 truncate text-zinc-600 dark:text-zinc-300" title="{{ $np->due_date->format('j M Y') }}">{{ __('Next') }} <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $np->due_date->toNepali(withYear: false) }}</span></span>
                    <span class="{{ $deadlineTone($npDays) }} shrink-0 font-medium tabular-nums">{{ Client::money($np->amount) }}</span>
                @elseif ($this->payments->isEmpty())
                    <button type="button" wire:click="newPayment" class="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:underline dark:text-brand-300"><flux:icon.plus variant="micro" class="size-3.5" />{{ __('Record a payment') }}</button>
                @else
                    <flux:icon.check-circle variant="micro" class="size-3.5 text-emerald-500" />
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ __('Everything received') }}</span>
                @endif
            </div>
        </div>

        {{-- Tasks --}}
        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="{{ $iconTile }} bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.check-circle variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Tasks') }}</p>
                <span class="text-xs font-semibold text-brand-600 tabular-nums dark:text-brand-300">{{ $taskPct }}%</span>
            </div>
            <div>
                <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $stats['tasksDone'] }}<span class="text-base font-normal text-zinc-400"> / {{ $stats['tasksTotal'] }} {{ __('done') }}</span></p>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-brand-500 transition-[width] duration-700" style="width: {{ $taskPct }}%"></div></div>
            </div>
            <div class="mt-auto flex items-center gap-2 text-xs">
                @if ($stats['tasksTotal'])
                    <button type="button" wire:click="setTab('tasks')" class="rounded-md bg-zinc-100 px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-200 dark:bg-white/5 dark:text-zinc-300">{{ trans_choice(':count open|:count open', $openCount) }}</button>
                    @if ($stats['tasksOverdue'])
                        <span class="rounded-md bg-rose-50 px-2 py-1 font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $stats['tasksOverdue']) }}</span>
                    @endif
                @else
                    <button type="button" wire:click="newTask" class="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:underline dark:text-brand-300"><flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add the first task') }}</button>
                @endif
            </div>
        </div>

        {{-- Agreement ends --}}
        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span @class([$iconTile, 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => $c->agreement_signed_at, 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300' => ! $c->agreement_signed_at])><flux:icon.document-check variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Agreement ends') }}</p>
                @if ($c->agreement_signed_at)
                    <span class="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('Signed') }}</span>
                @else
                    <span class="rounded-md bg-amber-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ __('Not signed') }}</span>
                @endif
            </div>
            @if ($c->contract_end)
                <div>
                    <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 dark:text-white" title="{{ $c->contract_end->format('j M Y') }}">{{ $c->contract_end->toNepali() }}</p>
                    <p @class(['mt-1.5 text-xs font-medium', 'text-rose-600 dark:text-rose-400' => $agreementDays < 0, 'text-amber-600 dark:text-amber-400' => $agreementDays >= 0 && $agreementDays <= 30, 'text-zinc-500' => $agreementDays > 30])>
                        @if ($agreementDays < 0)
                            {{ trans_choice('Ended :count day ago|Ended :count days ago', abs($agreementDays)) }}
                        @elseif ($agreementDays === 0)
                            {{ __('Ends today') }}
                        @else
                            {{ trans_choice(':count day left|:count days left', $agreementDays) }}
                        @endif
                    </p>
                </div>
                <div class="mt-auto flex flex-col gap-1.5">
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div @class(['h-full rounded-full transition-[width] duration-700', 'bg-rose-500' => $agreementDays < 0, 'bg-amber-400' => $agreementDays >= 0 && $agreementDays <= 30, 'bg-emerald-500' => $agreementDays > 30]) style="width: {{ $contractPct ?? 100 }}%"></div></div>
                    <p class="text-xs text-zinc-500">{{ $c->contract_start ? __('Started :date', ['date' => $c->contract_start->toNepali()]) : __('No start date') }}</p>
                </div>
            @else
                <p class="text-[1.625rem] leading-none font-semibold text-zinc-300 dark:text-zinc-600">{{ __('No end date') }}</p>
                <button type="button" wire:click="editAgreement" class="mt-auto inline-flex items-center gap-1.5 text-start text-xs font-medium text-brand-600 hover:underline dark:text-brand-300"><flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add agreement dates') }}</button>
            @endif
        </div>
    </section>

    {{-- Tabs --}}
    @php
        $openTasks = $this->tasks->whereNull('completed_at');
        $tabs = [
            'overview' => ['label' => __('Overview'), 'icon' => 'squares-2x2', 'count' => null],
            'tasks' => ['label' => __('Tasks'), 'icon' => 'check-circle', 'count' => $openTasks->count() ?: null],
            'payments' => ['label' => __('Payments'), 'icon' => 'banknotes', 'count' => $this->payments->count() ?: null, 'alert' => $stats['overduePayments']],
            'documents' => ['label' => __('Documents'), 'icon' => 'document-text', 'count' => $this->documents->count() ?: null],
            'notes' => ['label' => __('Notes'), 'icon' => 'pencil-square', 'count' => null],
        ];
    @endphp
    <nav class="-mx-1 flex gap-1 overflow-x-auto rounded-2xl bg-white p-1.5 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10" aria-label="{{ __('Client sections') }}">
        @php $activeTab = $tab === 'history' ? 'tasks' : $tab; @endphp
        @foreach ($tabs as $key => $t)
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white shadow-md shadow-brand-500/25' => $activeTab === $key,
                    'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' => $activeTab !== $key,
                ])
                @if ($activeTab === $key) aria-current="page" @endif
                data-test="tab-{{ $key }}"
            >
                <flux:icon :icon="$t['icon']" variant="micro" class="size-4" />
                {{ $t['label'] }}
                @if (! empty($t['alert']))
                    <span class="rounded-md bg-rose-500 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-white tabular-nums">{{ $t['alert'] }}</span>
                @elseif ($t['count'])
                    <span @class(['rounded-md px-1.5 py-0.5 text-[0.6875rem] tabular-nums', 'bg-white/20' => $activeTab === $key, 'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $activeTab !== $key])>{{ $t['count'] }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        @php
            $cal = $this->calendar;
            $todayKey = today()->toDateString();
            $isThisMonth = [$this->calYear, $this->calMonth] === array_slice(NepaliDate::fromAd(today()), 0, 2);
            $monthTasks = $cal['tasks'];
            $monthDone = $monthTasks->whereNotNull('completed_at')->count();
            $monthPct = $monthTasks->count() ? (int) round($monthDone / $monthTasks->count() * 100) : 0;
            $byType = $monthTasks->groupBy('type');
            $weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        @endphp
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
            <div class="flex min-w-0 flex-col gap-6">
                {{-- Work calendar --}}
                <section class="{{ $panel }} overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="{{ $iconTile }} bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.calendar-days variant="mini" class="size-4.5" /></span>
                            <div>
                                <h2 class="text-lg leading-tight font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $cal['label'] }}</h2>
                                <p class="text-xs text-zinc-500">{{ __('Work calendar') }} · {{ $cal['range'] }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="flex items-center rounded-lg ring-1 ring-zinc-200 dark:ring-white/10">
                                <button type="button" wire:click="shiftMonth(-1)" class="flex size-9 items-center justify-center rounded-s-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Previous month') }}"><flux:icon.chevron-left variant="mini" class="size-4" /></button>
                                <button type="button" wire:click="goToday" @class(['h-9 border-x border-zinc-200 px-3 text-sm font-medium dark:border-white/10', 'text-brand-600 dark:text-brand-300' => $isThisMonth, 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-white/5' => ! $isThisMonth])>{{ __('Today') }}</button>
                                <button type="button" wire:click="shiftMonth(1)" class="flex size-9 items-center justify-center rounded-e-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Next month') }}"><flux:icon.chevron-right variant="mini" class="size-4" /></button>
                            </div>
                            <flux:dropdown align="end">
                                <flux:button icon="ellipsis-horizontal" :aria-label="__('Calendar options')" />
                                <flux:menu>
                                    <flux:menu.item icon="document-duplicate" wire:click="repeatLastMonth" wire:confirm="{{ __('Copy last month\'s work onto the same days of :month?', ['month' => $cal['label']]) }}">{{ __('Repeat last month\'s plan') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                            <flux:button variant="primary" icon="plus" wire:click="newTask" class="shadow-md shadow-brand-500/20">{{ __('Add work') }}</flux:button>
                        </div>
                    </div>

                    {{-- Legend with this month's counts --}}
                    @php
                        $monthPayments = collect($cal['weeks'])->flatten(1)->filter()->flatMap(fn ($d) => $d['payments']);
                        $chip = 'inline-flex items-center gap-1.5 rounded-full py-1 ps-2 pe-1 text-xs font-medium ring-1 ring-inset transition';
                        $count = 'rounded-full px-1.5 py-px text-[0.6875rem] font-semibold tabular-nums';
                    @endphp
                    <div class="flex flex-wrap items-center gap-1.5 border-y border-zinc-100 bg-zinc-50/60 px-5 py-2.5 dark:border-white/5 dark:bg-white/[0.02]">
                        @foreach ($types as $key => $type)
                            @php
                                $all = isset($byType[$key]) ? $byType[$key]->count() : 0;
                                $done = $all ? $byType[$key]->whereNotNull('completed_at')->count() : 0;
                                $complete = $all && $done === $all;
                            @endphp
                            @continue(! $all)
                            <span @class([
                                $chip,
                                'bg-white text-zinc-700 shadow-xs ring-zinc-200 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10' => $all,
                                'text-zinc-400 ring-transparent' => ! $all,
                            ]) title="{{ __(':done of :all :type done this month', ['done' => $done, 'all' => $all, 'type' => __($type['label'])]) }}">
                                <span @class([$workTones[$key]['dot'], 'size-2 rounded-full', 'opacity-40' => ! $all])></span>
                                {{ $type['label'] }}
                                @if ($all)
                                    <span @class([$count, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $complete, 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => ! $complete])>
                                        @if ($complete)&#10003; @endif{{ $done }}/{{ $all }}
                                    </span>
                                @else
                                    <span class="pe-1.5"></span>
                                @endif
                            </span>
                        @endforeach
                        @php
                            $payAll = $monthPayments->count();
                            $payDone = $monthPayments->whereNotNull('paid_at')->count();
                        @endphp
                        @if ($byType->isEmpty() && ! $payAll)
                            <span class="text-xs text-zinc-400">{{ __('No work planned this month yet. Hover a day and click Add.') }}</span>
                        @endif
                        @if ($payAll)
                        <span @class([
                            $chip,
                            'bg-white text-zinc-700 shadow-xs ring-zinc-200 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10' => $payAll,
                            'text-zinc-400 ring-transparent' => ! $payAll,
                        ])>
                            <flux:icon.banknotes variant="micro" @class(['size-3.5 text-emerald-500', 'opacity-40' => ! $payAll]) />
                            {{ __('Payment') }}
                            @if ($payAll)
                                <span @class([$count, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $payDone === $payAll, 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => $payDone !== $payAll])>{{ $payDone }}/{{ $payAll }}</span>
                            @endif
                        </span>
                        @endif
                    </div>

                    {{-- Grid --}}
                    <div class="overflow-x-auto">
                        <div class="min-w-[44rem]">
                            <div class="grid grid-cols-7 border-b border-zinc-100 dark:border-white/5">
                                @foreach ($weekdays as $i => $wd)
                                    <div @class(['px-2 py-2 text-center text-[0.6875rem] font-semibold tracking-wider uppercase', 'text-rose-500' => $i === 6, 'text-zinc-400' => $i !== 6])>{{ __($wd) }}</div>
                                @endforeach
                            </div>
                            @foreach ($cal['weeks'] as $week)
                                <div class="grid grid-cols-7 border-b border-zinc-100 last:border-0 dark:border-white/5">
                                    @foreach ($week as $i => $cell)
                                        @if ($cell === null)
                                            <div class="min-h-28 border-e border-zinc-100 bg-zinc-50/50 last:border-e-0 dark:border-white/5 dark:bg-white/[0.01]"></div>
                                        @else
                                            @php
                                                $key = $cell['ad']->toDateString();
                                                $isToday = $key === $todayKey;
                                                $isPast = $cell['ad']->lt(today());
                                                $items = $cell['tasks']->count() + $cell['payments']->count();
                                            @endphp
                                            <div wire:key="day-{{ $key }}" @class([
                                                'group relative flex min-h-28 flex-col gap-1 border-e border-zinc-100 p-1.5 transition last:border-e-0 hover:bg-brand-50/40 dark:border-white/5 dark:hover:bg-brand-500/5',
                                                'bg-brand-50/60 dark:bg-brand-500/10' => $isToday,
                                                'bg-rose-50/30 dark:bg-rose-500/[0.03]' => $i === 6 && ! $isToday,
                                            ])>
                                                <div class="flex items-center justify-between px-0.5">
                                                    <span @class([
                                                        'flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                                        'bg-brand-500 text-white shadow-sm shadow-brand-500/40' => $isToday,
                                                        'text-zinc-400' => ! $isToday && $isPast,
                                                        'text-zinc-800 dark:text-zinc-100' => ! $isToday && ! $isPast,
                                                    ])>{{ $cell['day'] }}</span>
                                                    <span class="text-[0.625rem] text-zinc-300 tabular-nums dark:text-zinc-600">{{ $cell['ad']->format('j') }}</span>
                                                </div>

                                                @if ($cell['agreementEnds'])
                                                    <span class="flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[0.6875rem] font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"><flux:icon.flag variant="micro" class="size-3 shrink-0" /><span class="truncate">{{ __('Agreement ends') }}</span></span>
                                                @endif

                                                @foreach ($cell['payments'] as $p)
                                                    @php $ps = $p->status(); @endphp
                                                    <button type="button" wire:click="setTab('payments')" @class([
                                                        'flex items-center gap-1 rounded-md px-1.5 py-0.5 text-start text-[0.6875rem] font-medium',
                                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $ps === 'paid',
                                                        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $ps === 'overdue',
                                                        'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200' => $ps === 'pending',
                                                    ]) title="{{ __('Payment') }} · {{ Client::money($p->amount) }}">
                                                        <flux:icon.banknotes variant="micro" class="size-3 shrink-0" /><span class="truncate tabular-nums">{{ Client::money($p->amount) }}</span>
                                                    </button>
                                                @endforeach

                                                @foreach ($cell['tasks']->take(3) as $t)
                                                    @php $tone = $toneOf($t->type); @endphp
                                                    <button type="button" wire:key="cal-task-{{ $t->id }}" wire:click="toggleTask({{ $t->id }})" @class([
                                                        'flex w-full items-center gap-1.5 rounded-md px-1.5 py-1 text-start text-[0.6875rem] leading-tight font-medium ring-1 ring-inset transition hover:shadow-sm',
                                                        $tone['chip'] => ! $t->completed_at,
                                                        'bg-zinc-50 text-zinc-400 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10' => $t->completed_at,
                                                    ]) title="{{ $typeOf($t->type)['label'] }}: {{ $t->title }} · {{ $t->completed_at ? __('done, click to reopen') : __('click to mark done') }}">
                                                        @if ($t->completed_at)
                                                            <span class="flex size-3.5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><flux:icon.check variant="micro" class="size-2.5" /></span>
                                                        @else
                                                            <flux:icon :icon="$typeOf($t->type)['icon']" variant="micro" class="size-3 shrink-0 opacity-80" />
                                                        @endif
                                                        <span @class(['truncate', 'line-through' => $t->completed_at])>{{ $t->title }}</span>
                                                    </button>
                                                @endforeach
                                                @if ($cell['tasks']->count() > 3)
                                                    <button type="button" wire:click="setTab('tasks')" class="px-1.5 text-start text-[0.6875rem] font-medium text-zinc-500 hover:text-brand-600">+{{ $cell['tasks']->count() - 3 }} {{ __('more') }}</button>
                                                @endif

                                                <button type="button" wire:click="addWorkOn('{{ $key }}')" @class([
                                                    'mt-auto flex items-center justify-center gap-1 rounded-md border border-dashed border-brand-300 py-0.5 text-[0.6875rem] font-medium text-brand-600 opacity-0 transition group-hover:opacity-100 focus:opacity-100 dark:border-brand-500/40 dark:text-brand-300',
                                                    'flex-1' => $items === 0,
                                                ]) aria-label="{{ __('Add work on :date', ['date' => $cell['day'].' '.$cal['label']]) }}">
                                                    <flux:icon.plus variant="micro" class="size-3" />{{ __('Add') }}
                                                </button>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                {{-- To do --}}
                @php
                    $todo = $this->tasks->whereNull('completed_at')->filter(fn ($t) => ! $t->due_date || $t->isOverdue());
                @endphp
                @if ($todo->isNotEmpty())
                    <section class="{{ $panel }}">
                        <div class="{{ $panelHead }}">
                            <div>
                                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Needs attention') }}</h2>
                                <p class="text-xs text-zinc-500">{{ __('Overdue work and work without a date') }}</p>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="setTab('tasks')">{{ __('All tasks') }}</flux:button>
                        </div>
                        <ul class="flex flex-col gap-1 p-2">
                            @foreach ($todo->take(6) as $task)
                                @include('partials.work-row', ['task' => $task, 'payment' => null, 'prefix' => 'todo'])
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>

            {{-- Side --}}
            <div class="flex flex-col gap-6">
                {{-- This month --}}
                <section class="relative overflow-hidden rounded-xl bg-linear-to-br from-brand-500 to-brand-700 p-5 text-white shadow-[0_18px_40px_-20px_rgb(49_81_254/0.7)]">
                    <div class="absolute -top-10 -right-10 size-36 rounded-full bg-white/10"></div>
                    <div class="absolute -right-6 -bottom-16 size-32 rounded-full bg-sun/30 blur-2xl"></div>
                    <p class="relative text-xs font-semibold tracking-wider text-white/70 uppercase">{{ $isThisMonth ? __('This month') : $cal['label'] }}</p>
                    <div class="relative mt-2 flex items-end justify-between gap-3">
                        <p class="text-3xl leading-none font-semibold tracking-tight tabular-nums">{{ $monthDone }}<span class="text-lg font-normal text-white/60"> / {{ $monthTasks->count() }}</span></p>
                        <span class="rounded-full bg-white/15 px-2 py-0.5 text-xs font-semibold tabular-nums">{{ $monthPct }}%</span>
                    </div>
                    <p class="relative mt-1 text-xs text-white/70">{{ __('pieces of work delivered') }}</p>
                    <div class="relative mt-4 h-2 overflow-hidden rounded-full bg-white/20"><div class="h-full rounded-full bg-sun transition-[width] duration-700" style="width: {{ $monthPct }}%"></div></div>

                    @if ($byType->isNotEmpty())
                        <div class="relative mt-5 grid grid-cols-2 gap-2">
                            @foreach ($types as $key => $type)
                                @continue(! isset($byType[$key]))
                                @php
                                    $all = $byType[$key]->count();
                                    $done = $byType[$key]->whereNotNull('completed_at')->count();
                                @endphp
                                <div class="flex items-center gap-2 rounded-lg bg-white/10 px-2.5 py-2 ring-1 ring-white/10">
                                    <flux:icon :icon="$type['icon']" variant="micro" class="size-3.5 shrink-0 text-white/80" />
                                    <span class="min-w-0 flex-1 truncate text-xs">{{ $type['label'] }}</span>
                                    <span class="text-xs font-semibold tabular-nums">{{ $done }}/{{ $all }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <button type="button" wire:click="newTask" class="relative mt-5 flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-white/40 py-2.5 text-xs font-medium text-white/90 hover:bg-white/10">
                            <flux:icon.plus variant="micro" class="size-3.5" />{{ __('Plan this month\'s work') }}
                        </button>
                    @endif
                </section>

                {{-- Month agenda --}}
                @php
                    $agendaItems = collect($cal['weeks'])->flatten(1)->filter()->flatMap(fn ($d) => $d['payments']->map(fn ($p) => ['payment' => $p, 'task' => null, 'done' => (bool) $p->paid_at])
                        ->concat($d['tasks']->map(fn ($t) => ['payment' => null, 'task' => $t, 'done' => (bool) $t->completed_at])));
                    $agendaLists = [
                        'all' => $agendaItems,
                        'open' => $agendaItems->where('done', false),
                        'done' => $agendaItems->where('done', true),
                    ];
                    $openItems = $monthTasks->whereNull('completed_at')->count();
                @endphp
                <section class="{{ $panel }} overflow-hidden" x-data="{ show: 'all' }">
                    <div class="px-5 pt-5 pb-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Scheduled work') }}</h2>
                                <p class="mt-0.5 text-xs text-zinc-500">{{ $cal['label'] }}</p>
                            </div>
                            <flux:button size="sm" icon="plus" wire:click="newTask">{{ __('Add') }}</flux:button>
                        </div>

                        <div class="mt-4 flex items-center gap-3">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-linear-to-r from-brand-400 to-brand-600 transition-[width] duration-700" style="width: {{ $monthPct }}%"></div></div>
                            <span class="text-xs text-zinc-500 tabular-nums"><span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $monthDone }}</span> / {{ $monthTasks->count() }} {{ __('done') }}</span>
                        </div>

                        <div class="mt-4 grid grid-cols-3 gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                            @foreach (['all' => [__('All'), $monthTasks->count()], 'open' => [__('To do'), $openItems], 'done' => [__('Done'), $monthDone]] as $key => [$label, $count])
                                <button type="button" @click="show = '{{ $key }}'" class="flex items-center justify-center gap-1.5 rounded-lg py-1.5 text-xs font-medium transition" :class="show === '{{ $key }}' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-white'">
                                    {{ $label }}
                                    <span class="tabular-nums" :class="show === '{{ $key }}' ? 'text-brand-600 dark:text-brand-300' : 'text-zinc-400'">{{ $count }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @foreach ($agendaLists as $key => $list)
                        <div x-show="show === '{{ $key }}'" @if ($key !== 'all') x-cloak @endif class="border-t border-zinc-100 dark:border-white/5">
                            @if ($list->isEmpty())
                                <div class="flex flex-col items-center gap-2 px-5 py-10 text-center">
                                    <span class="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.calendar-days variant="mini" class="size-5" /></span>
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $key === 'done' ? __('Nothing done yet') : __('Nothing scheduled') }}</p>
                                    <p class="text-xs text-zinc-500">{{ __('Click a day on the calendar to plan work.') }}</p>
                                </div>
                            @else
                                <ul class="flex flex-col gap-1 p-2">
                                    @foreach ($list->take(4) as $item)
                                        @include('partials.work-row', ['task' => $item['task'], 'payment' => $item['payment'], 'prefix' => 'ag-'.$key])
                                    @endforeach
                                </ul>
                                @if ($list->count() > 4)
                                    <div class="px-4 pb-4">
                                        <button type="button" wire:click="setTab('tasks')" class="group flex w-full items-center justify-center gap-1.5 rounded-xl bg-zinc-50 py-2.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200/70 transition hover:bg-brand-50 hover:text-brand-700 hover:ring-brand-200 dark:bg-white/[0.03] dark:text-zinc-200 dark:ring-white/10" data-test="agenda-view-all">
                                            {{ __('View all :count', ['count' => $list->count()]) }}
                                            <flux:icon.arrow-right variant="micro" class="size-4 transition group-hover:translate-x-0.5" />
                                        </button>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </section>
            </div>
        </div>
    @elseif ($tab === 'tasks' || $tab === 'history')
        @php
            $allTasks = $this->tasks;
            $shown = $allTasks;
            $byStage = $shown->groupBy(fn ($t) => $t->stage());
            // History holds finished work outside the board: from earlier months, or from boxes with nothing open.
            $monthStart = NepaliDate::monthRange(today())[0];
            $openTypes = $allTasks->reject(fn ($t) => $t->stage() === 'done')->pluck('type')->unique();
            $boardTasks = $allTasks->filter(fn ($t) => $openTypes->contains($t->type) && ($t->stage() !== 'done' || $t->completed_at->gte($monthStart)));
            $historyTasks = $allTasks->filter(fn ($t) => $t->stage() === 'done')->diffKeys($boardTasks);
            $historyCount = $historyTasks->count();
            $order = array_flip(array_keys($types));
            $sortTypes = fn ($groups) => $groups->sortBy(fn ($g, $k) => $order[$k] ?? PHP_INT_MAX);
            // A box for every type of work, empty or not.
            $pinned = collect(array_keys($types))->mapWithKeys(fn ($k) => [$k => collect()]);
            $boxes = $sortTypes($pinned->merge($boardTasks->groupBy('type')));
            $historyBoxes = $sortTypes($historyTasks->groupBy('type'));
            $doneCount = isset($byStage['done']) ? $byStage['done']->count() : 0;
            $lateCount = $shown->filter->isOverdue()->count();
            $donePct = $shown->count() ? (int) round($doneCount / $shown->count() * 100) : 0;
            $range = function ($t) {
                if (! $t->start_date && ! $t->due_date) {
                    return null;
                }
                if ($t->start_date && $t->due_date && ! $t->start_date->isSameDay($t->due_date)) {
                    return $t->start_date->toNepali(withYear: false).' – '.$t->due_date->toNepali(withYear: false);
                }

                return ($t->due_date ?? $t->start_date)->toNepali(withYear: false);
            };
        @endphp

        @if ($tab === 'history')
            <div class="flex flex-col gap-5">
                <section class="{{ $panel }} flex flex-wrap items-center gap-4 p-5 sm:px-6">
                    <button type="button" wire:click="setTab('tasks')" class="inline-flex h-10 items-center gap-2 rounded-lg bg-white px-3.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 transition hover:bg-zinc-50 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10" data-test="back-to-tasks">
                        <flux:icon.arrow-left variant="micro" class="size-4" />{{ __('Back to tasks') }}
                    </button>
                    <div class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.clock variant="mini" class="size-5" /></span>
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ __('History') }}</h2>
                            <p class="text-sm text-zinc-500">{{ trans_choice(':count finished task|:count finished tasks', $historyCount) }}</p>
                        </div>
                    </div>
                </section>

                @if ($historyBoxes->isEmpty())
                    <section class="{{ $panel }} px-6 py-14 text-center">
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Nothing in history yet') }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('A box moves here once all its work is done.') }}</p>
                    </section>
                @else
                    <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($historyBoxes as $kind => $items)
                            @php $kt = $typeOf($kind); @endphp
                            <section wire:key="history-box-{{ $kind }}" class="{{ $panel }} overflow-hidden" x-data="{ open: true }">
                                <button type="button" @click="open = ! open" class="flex w-full items-center gap-2.5 bg-zinc-50/80 px-4 py-3 text-start dark:bg-white/[0.02]" :aria-expanded="open">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-zinc-200/70 text-zinc-500 dark:bg-white/10"><flux:icon :icon="$kt['icon']" variant="micro" class="size-4" /></span>
                                    <span class="flex-1 text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ $kt['label'] }}</span>
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 tabular-nums dark:bg-emerald-500/10 dark:text-emerald-300">{{ $items->count() }} {{ __('done') }}</span>
                                    <flux:icon.chevron-down variant="micro" class="size-4 text-zinc-400 transition" ::class="open ? '' : '-rotate-90'" />
                                </button>
                                <div x-show="open" x-collapse>
                                    <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                                        @foreach ($items->sortByDesc('completed_at') as $t)
                                            @include('partials.type-task-row', ['t' => $t, 'prefix' => 'history'])
                                        @endforeach
                                    </ul>
                                </div>
                            </section>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
        <div class="flex flex-col gap-5">
            {{-- Head --}}
            <section class="{{ $panel }} flex flex-col gap-4 p-5 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ __('Tasks') }}</h2>
                        <p class="mt-0.5 text-sm text-zinc-500">
                            {{ __(':done of :all done', ['done' => $doneCount, 'all' => $shown->count()]) }}
                            @if ($lateCount)
                                · <span class="font-medium text-rose-600 dark:text-rose-400">{{ trans_choice(':count late|:count late', $lateCount) }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="hidden items-center gap-3 sm:flex">
                            <div class="h-2 w-40 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-linear-to-r from-brand-400 to-brand-600 transition-[width] duration-700" style="width: {{ $donePct }}%"></div></div>
                            <span class="text-sm font-semibold text-zinc-800 tabular-nums dark:text-zinc-100">{{ $donePct }}%</span>
                        </div>
                        <button type="button" wire:click="setTab('history')" class="inline-flex h-10 items-center gap-2 rounded-lg bg-white px-3.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 transition hover:bg-zinc-50 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10" data-test="task-history-button">
                            <flux:icon.clock variant="micro" class="size-4" />{{ __('History') }}
                            <span class="rounded-full bg-emerald-100 px-1.5 text-xs font-semibold text-emerald-700 tabular-nums dark:bg-emerald-500/15 dark:text-emerald-300">{{ $historyCount }}</span>
                        </button>
                        <flux:button variant="primary" icon="plus" wire:click="newTask" class="shadow-md shadow-brand-500/20" data-test="add-task-button">{{ __('Add task') }}</flux:button>
                    </div>
                </div>
            </section>

            {{-- Board: one box per type of work --}}
            @php
                // A box stays on the board while it has open work; work finished this month stays in it, crossed out.
                // When everything in a box is done, the box moves to History.
            @endphp


            @php
                $columns = [[], [], [], []];
                foreach ($boxes->keys()->values() as $i => $key) {
                    $columns[$i % 4][$key] = $boxes[$key];
                }
            @endphp
            <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($columns as $column)
                    <div class="flex flex-col gap-4">
                        @foreach ($column as $kind => $items)
                            @php
                                $kt = $typeOf($kind);
                                $ktone = $toneOf($kind);
                                $rows = $items->sortBy(fn ($t) => [array_search($t->stage(), array_keys(Task::STATUSES)), $t->due_date?->timestamp ?? PHP_INT_MAX, $t->id])->values();
                                $openHere = $rows->reject(fn ($t) => $t->stage() === 'done');
                                $doneHere = $rows->count() - $openHere->count();
                                $lateHere = $openHere->filter->isOverdue()->count();
                            @endphp
                            <section wire:key="box-{{ $kind }}" class="{{ $panel }} flex flex-col overflow-hidden" x-data="{ open: true }">
                                <div class="{{ $ktone['chip'] }} flex items-center gap-2.5 px-4 py-3">
                                    <button type="button" @click="open = ! open" class="flex min-w-0 flex-1 items-center gap-2.5 text-start" :aria-expanded="open">
                                        <span class="{{ $ktone['dot'] }} flex size-8 shrink-0 items-center justify-center rounded-lg text-white shadow-sm"><flux:icon :icon="$kt['icon']" variant="micro" class="size-4" /></span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold">{{ $kt['label'] }}</span>
                                            <span class="block text-[0.6875rem] opacity-80">@if ($rows->isEmpty()){{ __('Nothing yet') }}@else{{ $doneHere }}/{{ $rows->count() }} {{ __('done') }}@if ($lateHere) · {{ trans_choice(':count late|:count late', $lateHere) }}@endif @endif</span>
                                        </span>
                                        <flux:icon.chevron-down variant="micro" class="ms-auto size-4 shrink-0 opacity-60 transition" ::class="open ? '' : '-rotate-90'" />
                                    </button>
                                    <button type="button" wire:click="newTask('todo', '{{ $kind }}')" class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/70 transition hover:bg-white dark:bg-white/10 dark:hover:bg-white/20" aria-label="{{ __('Add :type', ['type' => $kt['label']]) }}" title="{{ __('Add :type', ['type' => $kt['label']]) }}"><flux:icon.plus variant="micro" class="size-4" /></button>
                                    <flux:dropdown align="end">
                                        <button type="button" class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/70 transition hover:bg-white dark:bg-white/10 dark:hover:bg-white/20" aria-label="{{ __(':type settings', ['type' => $kt['label']]) }}" title="{{ __('Settings') }}" data-test="box-settings-{{ $kind }}"><flux:icon.cog-6-tooth variant="micro" class="size-4" /></button>
                                        <flux:menu>
                                            <flux:menu.submenu :heading="__('Assign all to')" icon="user-plus">
                                                @forelse ($this->team as $member)
                                                    <flux:menu.item wire:click="assignBox('{{ $kind }}', {{ $member->id }})">{{ $member->name }}</flux:menu.item>
                                                @empty
                                                    <flux:menu.item disabled>{{ __('No employees yet') }}</flux:menu.item>
                                                @endforelse
                                            </flux:menu.submenu>
                                            <flux:menu.item icon="plus" wire:click="newTask('todo', '{{ $kind }}')">{{ __('Add :type', ['type' => $kt['label']]) }}</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="check-circle" wire:click="completeBox('{{ $kind }}')" wire:confirm="{{ __('Mark all :type work as done?', ['type' => $kt['label']]) }}">{{ __('Mark all as done') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>

                                <div x-show="open" x-collapse>
                                    @if ($rows->isEmpty())
                                        <button type="button" wire:click="newTask('todo', '{{ $kind }}')" class="flex w-full items-center justify-center gap-1.5 px-4 py-6 text-sm text-zinc-400 transition hover:bg-zinc-50 hover:text-brand-600 dark:hover:bg-white/[0.03]">
                                            <flux:icon.plus variant="micro" class="size-4" />{{ __('Add :type', ['type' => $kt['label']]) }}
                                        </button>
                                    @else
                                        <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                                            @foreach ($rows as $t)
                                                @include('partials.type-task-row', ['t' => $t, 'prefix' => 'box'])
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </section>
                        @endforeach
                    </div>
                @endforeach
            </div>

        </div>
        @endif
    @elseif ($tab === 'payments')
        <section class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                [__('Billed'), $stats['billed'], 'text-zinc-900 dark:text-white'],
                [__('Received'), $stats['received'], 'text-emerald-600 dark:text-emerald-400'],
                [__('Still due'), $stats['billed'] - $stats['received'], $stats['overduePayments'] ? 'text-rose-600 dark:text-rose-400' : 'text-amber-600 dark:text-amber-400'],
            ] as [$label, $value, $tone])
                <div class="{{ $panel }} px-5 py-4">
                    <p class="text-xs text-zinc-500">{{ $label }}</p>
                    <p class="{{ $tone }} mt-1 text-xl font-semibold tracking-tight tabular-nums">{{ Client::money($value) }}</p>
                </div>
            @endforeach
        </section>
            {{-- Payments --}}
            @php
                // Only money actually received is listed here.
                $allPayments = $this->payments->whereNotNull('paid_at')->sortByDesc('paid_at');
            @endphp
            <section class="{{ $panel }} overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                        <div>
                            <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Received payments') }}</h2>
                            <p class="text-xs text-zinc-500">{{ trans_choice(':count payment|:count payments', $allPayments->count()) }} · {{ Client::money($allPayments->sum('amount')) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="newPayment" data-test="record-payment-button">{{ __('Record payment') }}</flux:button>
                    </div>
                </div>

                @if ($allPayments->isEmpty())
                    <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><flux:icon.banknotes class="size-6" /></span>
                        <p class="text-sm text-zinc-500">{{ __('No payments received yet.') }}</p>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="newPayment">{{ __('Record the first payment') }}</flux:button>
                    </div>
                @else
                    <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                        @foreach ($allPayments as $payment)
                            @php
                                $ps = $payment->status();
                                $inDays = $payment->dueInDays();
                                $soon = $ps === 'pending' && $inDays !== null && $inDays <= Payment::remindDays();
                                $shownDate = $payment->paid_at ?? $payment->due_date;
                                $bs = $shownDate ? NepaliDate::fromAd($shownDate) : null;
                                $look = $methodLook[$payment->method] ?? ($payment->method ? ['icon' => 'credit-card', 'tile' => 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300'] : null);
                                $tile = match (true) {
                                    $ps === 'paid' => 'bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
                                    $ps === 'overdue' => 'bg-rose-50 text-rose-700 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
                                    $soon => 'bg-amber-50 text-amber-700 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
                                    default => 'bg-zinc-50 text-zinc-600 ring-zinc-200/70 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
                                };
                            @endphp
                            <li wire:key="payment-{{ $payment->id }}" class="group flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition hover:bg-zinc-50/70 sm:flex-nowrap dark:hover:bg-white/[0.02]">
                                {{-- Date --}}
                                <div class="flex w-48 shrink-0 items-center gap-3" title="{{ $shownDate?->format('l, j M Y') }}">
                                    <span class="{{ $tile }} flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset"><flux:icon.calendar-days variant="mini" class="size-5" /></span>
                                    <div class="min-w-0">
                                        <p class="text-[0.6875rem] font-medium tracking-wide text-zinc-400 uppercase">{{ $payment->paid_at ? __('Received on') : __('Due on') }}</p>
                                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $shownDate ? $shownDate->toNepali() : __('No date') }}</p>
                                    </div>
                                </div>

                                {{-- Amount and type --}}
                                <div class="min-w-0 flex-1">
                                    <p class="text-base font-semibold text-zinc-900 tabular-nums dark:text-white">{{ Client::money($payment->amount) }}</p>
                                    <div class="mt-1 flex min-w-0 flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700 ring-1 ring-brand-200/70 ring-inset dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
                                            <flux:icon :icon="Payment::TYPES[$payment->type]['icon'] ?? 'tag'" variant="micro" class="size-3.5" />{{ $payment->typeLabel() }}
                                        </span>
                                        @if ($payment->reference)
                                            <span class="truncate text-xs text-zinc-500 tabular-nums">#{{ $payment->reference }}</span>
                                        @endif
                                        @if ($payment->note)
                                            <span class="truncate text-xs text-zinc-500">· {{ $payment->note }}</span>
                                        @endif
                                        @if ($payment->proof_path)
                                            <a href="{{ route('payments.proof', $payment) }}" target="_blank" class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200/70 ring-inset transition hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20" title="{{ __('View payment proof') }}">
                                                <flux:icon :icon="$payment->proofIsImage() ? 'photo' : 'paper-clip'" variant="micro" class="size-3.5" />{{ __('Proof') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>

                                {{-- Received in --}}
                                <div class="hidden w-36 shrink-0 md:block">
                                    @if ($look)
                                        <span class="inline-flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                                            <span class="{{ $look['tile'] }} flex size-7 items-center justify-center rounded-lg"><flux:icon :icon="$look['icon']" variant="micro" class="size-3.5" /></span>
                                            {{ __($payment->method) }}
                                        </span>
                                    @else
                                        <span class="text-xs text-zinc-400">{{ __('Not received yet') }}</span>
                                    @endif
                                </div>

                                {{-- Status --}}
                                <div class="w-40 shrink-0 text-end sm:text-start">
                                    @if ($ps === 'paid')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-circle variant="micro" class="size-3.5" />{{ __('Received') }}</span>
                                    @elseif ($ps === 'overdue')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.exclamation-circle variant="micro" class="size-3.5" />{{ trans_choice('Overdue :count day|Overdue :count days', abs($inDays)) }}</span>
                                    @elseif ($soon)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"><flux:icon.bell-alert variant="micro" class="size-3.5" />{{ $inDays === 0 ? __('Due today') : trans_choice('Due tomorrow|Due in :count days', $inDays) }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:bg-white/10 dark:text-zinc-300"><flux:icon.clock variant="micro" class="size-3.5" />{{ __('Pending') }}</span>
                                    @endif
                                </div>

                                {{-- Actions --}}
                                <flux:dropdown align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" :aria-label="__('Payment actions')" />
                                    <flux:menu>
                                        <flux:menu.item :icon="$payment->paid_at ? 'arrow-uturn-left' : 'check-circle'" wire:click="togglePaid({{ $payment->id }})">
                                            {{ $payment->paid_at ? __('Mark as unpaid') : __('Mark as received') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deletePayment({{ $payment->id }})" wire:confirm="{{ __('Delete this payment?') }}">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
    @elseif ($tab === 'documents')
        <div class="grid items-start gap-6 lg:grid-cols-2">
            {{-- Documents --}}
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <div class="flex items-center gap-2.5">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Documents') }}</h2>
                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-500 tabular-nums dark:bg-white/5">{{ $this->documents->count() }}</span>
                    </div>
                    <flux:button size="sm" icon="arrow-up-tray" wire:click="newDocument" data-test="upload-document-button">{{ __('Upload') }}</flux:button>
                </div>
                <div class="flex flex-col gap-2 p-5">
                    @forelse ($this->documents as $doc)
                        @include('partials.document-row', ['doc' => $doc, 'fileSize' => $fileSize])
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500">{{ __('Invoices, proposals and other files will show here.') }}</p>
                    @endforelse
                </div>
            </section>

            {{-- Agreement --}}
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Agreement') }}</h2>
                    @if ($hasAgreement)
                        <flux:button size="sm" icon="pencil-square" wire:click="editAgreement">{{ __('Edit') }}</flux:button>
                    @endif
                </div>

                @if (! $hasAgreement)
                    <div class="flex flex-col items-center gap-3 px-5 py-10 text-center">
                        <span class="{{ $iconTile }} bg-amber-50 text-amber-600 dark:bg-amber-500/10"><flux:icon.document-check variant="mini" class="size-4.5" /></span>
                        <div>
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __('No agreement yet') }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500">{{ __('Add the contract period and upload the signed copy.') }}</p>
                        </div>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="editAgreement" data-test="add-agreement-button">{{ __('Add agreement') }}</flux:button>
                    </div>
                @else
                    <div class="flex flex-col gap-4 p-5">
                        <div class="flex items-center justify-between">
                            <span @class([
                                'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                                'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300' => $c->agreement_signed_at,
                                'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300' => ! $c->agreement_signed_at,
                            ])>
                                <flux:icon :icon="$c->agreement_signed_at ? 'check-badge' : 'clock'" variant="micro" class="size-3.5" />
                                {{ $c->agreement_signed_at ? __('Signed :date', ['date' => $c->agreement_signed_at->toNepali()]) : __('Waiting for signature') }}
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-lg bg-zinc-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                <p class="text-[0.6875rem] text-zinc-500">{{ __('Starts') }}</p>
                                <p class="mt-0.5 text-sm font-medium text-zinc-800 dark:text-zinc-100" title="{{ $c->contract_start?->format('j M Y') }}">{{ $c->contract_start?->toNepali() ?: '-' }}</p>
                            </div>
                            <div class="rounded-lg bg-zinc-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                <p class="text-[0.6875rem] text-zinc-500">{{ __('Ends') }}</p>
                                <p class="mt-0.5 text-sm font-medium text-zinc-800 dark:text-zinc-100" title="{{ $c->contract_end?->format('j M Y') }}">{{ $c->contract_end?->toNepali() ?: '-' }}</p>
                            </div>
                        </div>

                        @if ($contractPct !== null)
                            <div class="flex flex-col gap-1.5">
                                <div class="flex justify-between text-xs text-zinc-500">
                                    <span>{{ __('Contract period') }}</span>
                                    <span class="tabular-nums">{{ $contractPct }}%</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-zinc-800 dark:bg-white" style="width: {{ $contractPct }}%"></div></div>
                            </div>
                        @endif

                        @foreach ($agreementDocs as $doc)
                            @include('partials.document-row', ['doc' => $doc, 'fileSize' => $fileSize])
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    @else
            {{-- Notes --}}
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Notes') }}</h2>
                    <flux:button size="sm" icon="pencil-square" wire:click="editNotes">{{ $c->notes ? __('Edit') : __('Add notes') }}</flux:button>
                </div>
                <div class="p-5">
                    @if ($c->notes)
                        <p class="text-sm leading-relaxed whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $c->notes }}</p>
                    @else
                        <p class="text-sm text-zinc-500">{{ __('Keep internal notes about this client here. Only your team sees them.') }}</p>
                    @endif
                </div>
            </section>
    @endif

    {{-- Task view (open a card) --}}
    <flux:modal name="task-view" class="w-full max-w-xl p-0!" :closable="false">
        @if ($vt = $this->viewTask)
            @php
                $vtt = $typeOf($vt->type);
                $vtone = $toneOf($vt->type);
                $vstage = $vt->stage();
                $vlate = $vt->isOverdue();
                $vdays = $vt->due_date ? (int) today()->diffInDays($vt->due_date, false) : null;
            @endphp
            <div class="flex flex-col">
                {{-- Head --}}
                <div class="{{ $vtone['chip'] }} relative rounded-t-xl px-6 pt-5 pb-6">
                    <div class="flex items-center gap-2">
                        <span class="{{ $vtone['dot'] }} flex size-8 items-center justify-center rounded-lg text-white shadow-sm"><flux:icon :icon="$vtt['icon']" variant="micro" class="size-4" /></span>
                        <span class="text-sm font-semibold">{{ $vtt['label'] }}</span>
                        <flux:modal.close class="ms-auto">
                            <button type="button" class="flex size-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-white/70 hover:text-zinc-900" aria-label="{{ __('Close') }}"><flux:icon.x-mark variant="mini" class="size-5" /></button>
                        </flux:modal.close>
                    </div>
                    <h2 @class(['mt-4 text-xl leading-snug font-semibold tracking-tight text-zinc-900 dark:text-white', 'line-through opacity-60' => $vstage === 'done'])>{{ $vt->title }}</h2>
                    <p class="mt-1 text-xs text-zinc-500">{{ __('Added :date', ['date' => $vt->created_at->toNepali()]) }}@if ($vt->completed_at) · {{ __('Done :date', ['date' => $vt->completed_at->toNepali()]) }}@endif</p>
                </div>

                <div class="flex flex-col gap-5 px-6 py-5">
                    {{-- Status --}}
                    <div>
                        <p class="mb-2 text-xs font-medium tracking-wide text-zinc-400 uppercase">{{ __('Status') }}</p>
                        <div class="grid grid-cols-2 gap-1 rounded-xl bg-zinc-100 p-1 sm:grid-cols-4 dark:bg-white/5">
                            @foreach (Task::STATUSES as $sk => $sv)
                                <button type="button" wire:click="setTaskStatus({{ $vt->id }}, '{{ $sk }}')" @class([
                                    'flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-medium transition',
                                    'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' => $vstage === $sk,
                                    'text-zinc-500 hover:text-zinc-800 dark:hover:text-white' => $vstage !== $sk,
                                ])>
                                    <span class="{{ $statusTones[$sk]['dot'] }} size-2 rounded-full"></span>{{ __($sv['label']) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Facts --}}
                    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-zinc-400 uppercase">{{ __('Priority') }}</dt>
                            <dd class="mt-1.5">
                                <flux:dropdown>
                                    <button type="button" class="{{ $priorityTones[$vt->priority] ?? $priorityTones['medium'] }} inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-sm font-medium ring-1 ring-inset">
                                        <flux:icon.flag variant="micro" class="size-3.5" />{{ __(Task::PRIORITIES[$vt->priority] ?? 'Medium') }}<flux:icon.chevron-down variant="micro" class="size-3.5 opacity-60" />
                                    </button>
                                    <flux:menu>
                                        @foreach (Task::PRIORITIES as $pk => $pl)
                                            <flux:menu.item icon="flag" wire:click="setTaskPriority({{ $vt->id }}, '{{ $pk }}')">{{ __($pl) }}</flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-zinc-400 uppercase">{{ __('Dates') }}</dt>
                            <dd @class(['mt-1.5 flex items-center gap-1.5 text-sm font-medium', 'text-rose-600 dark:text-rose-400' => $vlate, 'text-zinc-800 dark:text-zinc-100' => ! $vlate])>
                                <flux:icon.calendar variant="micro" class="size-4 opacity-60" />
                                @if ($vt->start_date || $vt->due_date)
                                    {{ $vt->start_date && $vt->due_date && ! $vt->start_date->isSameDay($vt->due_date) ? $vt->start_date->toNepali(withYear: false).' – '.$vt->due_date->toNepali() : ($vt->due_date ?? $vt->start_date)->toNepali() }}
                                @else
                                    <span class="font-normal text-zinc-400">{{ __('No date') }}</span>
                                @endif
                            </dd>
                            @if ($vdays !== null && $vstage !== 'done')
                                <p @class(['mt-0.5 text-xs', 'text-rose-600 dark:text-rose-400' => $vdays < 0, 'text-zinc-500' => $vdays >= 0])>
                                    {{ $vdays < 0 ? trans_choice(':count day late|:count days late', abs($vdays)) : ($vdays === 0 ? __('Due today') : trans_choice('Due tomorrow|Due in :count days', $vdays)) }}
                                </p>
                            @endif
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium tracking-wide text-zinc-400 uppercase">{{ __('Assigned to') }}</dt>
                            <dd class="mt-2 flex flex-wrap gap-2">
                                @forelse ($vt->assignees as $person)
                                    <span class="inline-flex items-center gap-2 rounded-full bg-zinc-50 py-1 ps-1 pe-3 ring-1 ring-zinc-200/70 dark:bg-white/5 dark:ring-white/10">
                                        <x-employee-avatar :employee="$person" class="size-7 text-[0.625rem]" />
                                        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $person->name }}</span>
                                    </span>
                                @empty
                                    <button type="button" wire:click="editTask({{ $vt->id }})" class="inline-flex items-center gap-2 rounded-full border border-dashed border-zinc-300 px-3 py-1.5 text-sm text-zinc-500 transition hover:border-brand-400 hover:text-brand-600 dark:border-white/20"><flux:icon.user-plus variant="micro" class="size-4" />{{ __('Assign someone') }}</button>
                                @endforelse
                            </dd>
                        </div>
                    </dl>

                    {{-- Description --}}
                    <div>
                        <p class="mb-2 text-xs font-medium tracking-wide text-zinc-400 uppercase">{{ __('Description') }}</p>
                        @if ($vt->description)
                            <p class="rounded-xl bg-zinc-50 px-4 py-3 text-sm leading-relaxed whitespace-pre-line text-zinc-700 dark:bg-white/[0.03] dark:text-zinc-200">{{ $vt->description }}</p>
                        @else
                            <p class="text-sm text-zinc-400">{{ __('No description.') }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                    <flux:button variant="ghost" icon="trash" class="text-rose-600! hover:bg-rose-50!" wire:click="deleteTask({{ $vt->id }})" wire:confirm="{{ __('Delete this task?') }}">{{ __('Delete') }}</flux:button>
                    <div class="ms-auto flex gap-2">
                        <flux:button icon="pencil-square" wire:click="editTask({{ $vt->id }})">{{ __('Edit') }}</flux:button>
                        @if ($vstage === 'done')
                            <flux:button icon="arrow-uturn-left" wire:click="toggleTask({{ $vt->id }})">{{ __('Reopen') }}</flux:button>
                        @else
                            <flux:button variant="primary" icon="check" wire:click="toggleTask({{ $vt->id }})">{{ __('Mark as done') }}</flux:button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Add / edit task --}}
    @php
        $empTints = [
            'design' => 'bg-rose-100 text-rose-700', 'development' => 'bg-brand-100 text-brand-700', 'marketing' => 'bg-amber-100 text-amber-800',
            'content' => 'bg-emerald-100 text-emerald-700', 'sales' => 'bg-sky-100 text-sky-700', 'management' => 'bg-violet-100 text-violet-700', 'hr' => 'bg-teal-100 text-teal-700',
        ];
        $teamJs = $this->team->map(fn ($e) => [
            'id' => $e->id, 'name' => $e->name, 'role' => $e->designation ?? $e->departmentMeta()['label'],
            'initials' => $e->initials(), 'tint' => $empTints[$e->department] ?? $empTints['development'],
        ])->values();
        $typesJs = collect($types)->map(fn ($t, $slug) => ['label' => $t['label'], 'tile' => ($palette[$t['color']] ?? $palette['zinc'])['chip']]);
        $label = 'mb-1.5 block text-sm font-medium text-zinc-800 dark:text-white';
        $trigger = 'flex h-11 w-full items-center gap-2.5 rounded-xl bg-white px-3 text-start ring-1 ring-zinc-200 transition hover:ring-zinc-300 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:ring-white/10';
        $panelBox = 'absolute inset-x-0 top-full z-40 mt-1.5 overflow-hidden rounded-xl bg-white shadow-[0_20px_50px_-12px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10';
    @endphp
    <flux:modal name="task-form" class="w-full max-w-2xl overflow-visible p-0!" :closable="false">
        <form wire:submit="addTask" class="flex flex-col"
            x-data="{
                typeOpen: false, peopleOpen: false, q: '',
                team: @js($teamJs), types: @js($typesJs),
                has(id) { return ($wire.taskAssignees || []).includes(id) },
                toggle(id) { $wire.taskAssignees = this.has(id) ? $wire.taskAssignees.filter(x => x !== id) : [...($wire.taskAssignees || []), id] },
                get chosen() { return this.team.filter(e => this.has(e.id)) },
                get found() { const s = this.q.toLowerCase(); return this.team.filter(e => ! s || e.name.toLowerCase().includes(s) || (e.role || '').toLowerCase().includes(s)) },
            }">
            <div class="flex items-start gap-4 border-b border-zinc-100 px-6 py-5 dark:border-white/5">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-400 to-brand-600 text-white shadow-md shadow-brand-500/30"><flux:icon.clipboard-document-check variant="mini" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg">{{ $taskId ? __('Edit work') : __('Add work') }}</flux:heading>
                    <p class="truncate text-sm text-zinc-500">{{ $c->displayName() }}</p>
                </div>
                <flux:modal.close><flux:button variant="ghost" size="sm" icon="x-mark" :aria-label="__('Close')" /></flux:modal.close>
            </div>

            <div class="flex flex-col gap-5 px-6 py-5">
                <flux:input wire:model="taskTitle" :label="__('Task name')" :placeholder="__('e.g. Dashain offer post')" required autofocus />

                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- Type of work --}}
                    <div class="relative" @click.outside="typeOpen = false" @keydown.escape.stop="typeOpen = false">
                        <span class="{{ $label }}">{{ __('Type of work') }}</span>
                        <button type="button" @click="typeOpen = ! typeOpen; peopleOpen = false" class="{{ $trigger }}" :aria-expanded="typeOpen" aria-haspopup="listbox" data-test="work-type-button">
                            @foreach ($types as $key => $type)
                                <span x-show="$wire.taskType === @js($key)" class="flex min-w-0 items-center gap-2.5" x-cloak>
                                    <span class="{{ $toneOf($key)['chip'] }} flex size-7 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset"><flux:icon :icon="$type['icon']" variant="micro" class="size-3.5" /></span>
                                    <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $type['label'] }}</span>
                                </span>
                            @endforeach
                            <span x-show="! types[$wire.taskType]" class="text-sm text-zinc-400">{{ __('Choose a type') }}</span>
                            <flux:icon.chevron-up-down variant="mini" class="ms-auto size-4 shrink-0 text-zinc-400" />
                        </button>
                        <div x-show="typeOpen" x-transition.origin.top x-cloak class="{{ $panelBox }}" role="listbox">
                            <div class="max-h-60 overflow-y-auto p-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                @foreach ($types as $key => $type)
                                    <div wire:key="type-opt-{{ $key }}" class="group flex items-center gap-1 rounded-lg transition hover:bg-zinc-50 dark:hover:bg-white/5" :class="$wire.taskType === @js($key) && 'bg-brand-50/70 dark:bg-brand-500/10'">
                                        <button type="button" role="option" @click="$wire.taskType = @js($key); typeOpen = false" class="flex min-w-0 flex-1 items-center gap-2.5 px-2 py-1.5 text-start">
                                            <span class="{{ $toneOf($key)['chip'] }} flex size-7 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset"><flux:icon :icon="$type['icon']" variant="micro" class="size-3.5" /></span>
                                            <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $type['label'] }}</span>
                                            <flux:icon.check variant="mini" class="ms-auto size-4 text-brand-600" x-show="$wire.taskType === @js($key)" />
                                        </button>
                                        <button type="button" wire:click="confirmDeleteType('{{ $key }}')" class="me-1 rounded-md p-1.5 text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-500 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete :name', ['name' => $type['label']]) }}">
                                            <flux:icon.trash variant="micro" class="size-3.5" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex items-center gap-1.5 border-t border-zinc-100 bg-zinc-50/70 p-2 dark:border-white/5 dark:bg-white/[0.02]">
                                <input wire:model="newTypeName" type="text" maxlength="40" placeholder="{{ __('Add a new type…') }}" @keydown.enter.prevent="$wire.addWorkType()" class="h-8 min-w-0 flex-1 rounded-lg border-0 bg-white px-2.5 text-sm ring-1 ring-zinc-200 placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:text-white dark:ring-white/10" data-test="new-type-input" />
                                <button type="button" wire:click="addWorkType" class="inline-flex h-8 items-center gap-1 rounded-lg bg-brand-500 px-2.5 text-xs font-semibold text-white transition hover:bg-brand-600"><flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add') }}</button>
                            </div>
                            <flux:error name="newTypeName" class="px-3 pb-2" />
                        </div>
                        <flux:error name="taskType" />
                    </div>

                    {{-- Assign --}}
                    <div class="relative" @click.outside="peopleOpen = false" @keydown.escape.stop="peopleOpen = false">
                        <span class="{{ $label }}">{{ __('Assign to') }}</span>
                        <button type="button" @click="peopleOpen = ! peopleOpen; typeOpen = false" class="{{ $trigger }}" :aria-expanded="peopleOpen" aria-haspopup="listbox" data-test="assign-button">
                            <template x-if="chosen.length === 0">
                                <span class="flex items-center gap-2.5 text-sm text-zinc-400">
                                    <span class="flex size-7 items-center justify-center rounded-full border border-dashed border-zinc-300 dark:border-white/20"><flux:icon.user-plus variant="micro" class="size-3.5" /></span>
                                    {{ __('Assign people') }}
                                </span>
                            </template>
                            <template x-if="chosen.length > 0">
                                <span class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex -space-x-1.5">
                                        <template x-for="e in chosen.slice(0, 4)" :key="e.id">
                                            <span class="flex size-7 items-center justify-center rounded-full text-[0.625rem] font-semibold ring-2 ring-white dark:ring-zinc-900" :class="e.tint" x-text="e.initials" :title="e.name"></span>
                                        </template>
                                    </span>
                                    <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100" x-text="chosen.length === 1 ? chosen[0].name : chosen.length + ' ' + @js(__('people'))"></span>
                                </span>
                            </template>
                            <flux:icon.chevron-up-down variant="mini" class="ms-auto size-4 shrink-0 text-zinc-400" />
                        </button>
                        <div x-show="peopleOpen" x-transition.origin.top x-cloak class="{{ $panelBox }}" role="listbox" aria-multiselectable="true">
                            <div class="border-b border-zinc-100 p-2 dark:border-white/5">
                                <div class="relative">
                                    <flux:icon.magnifying-glass variant="micro" class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                                    <input x-model="q" type="text" placeholder="{{ __('Search the team') }}" class="h-8 w-full rounded-lg border-0 bg-zinc-50 ps-8 pe-2.5 text-sm ring-1 ring-zinc-200 placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:text-white dark:ring-white/10" />
                                </div>
                            </div>
                            <div class="max-h-60 overflow-y-auto p-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                <template x-for="e in found" :key="e.id">
                                    <button type="button" role="option" @click="toggle(e.id)" :aria-selected="has(e.id)" class="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-start transition hover:bg-zinc-50 dark:hover:bg-white/5">
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full text-[0.6875rem] font-semibold" :class="e.tint" x-text="e.initials"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white" x-text="e.name"></span>
                                            <span class="block truncate text-xs text-zinc-500" x-text="e.role"></span>
                                        </span>
                                        <span class="flex size-5 shrink-0 items-center justify-center rounded-md border-[1.5px] transition" :class="has(e.id) ? 'border-brand-500 bg-brand-500 text-white' : 'border-zinc-300 text-transparent dark:border-zinc-600'"><flux:icon.check variant="micro" class="size-3.5" /></span>
                                    </button>
                                </template>
                                <p x-show="found.length === 0" class="px-3 py-6 text-center text-sm text-zinc-400">
                                    {{ $this->team->isEmpty() ? __('Add employees first to assign work.') : __('Nobody matches.') }}
                                </p>
                            </div>
                        </div>
                        <flux:error name="taskAssignees" />
                    </div>
                </div>

                <flux:textarea wire:model="taskDescription" :label="__('Description')" :placeholder="__('Details, captions, links or notes for the team')" rows="3" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Status') }}</span>
                        <div class="mt-2 grid grid-cols-2 gap-1.5">
                            @foreach (Task::STATUSES as $key => $st)
                                <button type="button" @click="$wire.taskStatus = '{{ $key }}'" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs font-medium ring-1 transition" :class="$wire.taskStatus === '{{ $key }}' ? 'bg-brand-50 text-brand-700 ring-2 ring-brand-500 dark:bg-brand-500/10 dark:text-brand-200' : 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:hover:bg-white/5'">
                                    <span class="{{ $statusTones[$key]['dot'] }} size-2 rounded-full"></span>{{ __($st['label']) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Priority') }}</span>
                        <div class="mt-2 grid grid-cols-2 gap-1.5">
                            @foreach (Task::PRIORITIES as $key => $plabel)
                                <button type="button" @click="$wire.taskPriority = '{{ $key }}'" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs font-medium ring-1 transition" :class="$wire.taskPriority === '{{ $key }}' ? @js($priorityTones[$key].' ring-2') : 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:hover:bg-white/5'">
                                    <flux:icon.flag variant="micro" class="size-3.5" />{{ __($plabel) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-nepali-date wire:model="taskStart" :label="__('Start date')" />
                    <x-nepali-date wire:model="taskDue" :label="__('Due date')" />
                </div>
                <flux:error name="taskDue" />
            </div>

            <div class="flex justify-end gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-task-button">{{ $taskId ? __('Save changes') : __('Add work') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Record payment --}}
    @php
        $billed = (float) $stats['billed'];
        $received = (float) $stats['received'];
        $outstanding = max(0, $billed - $received);
        $field = 'w-full rounded-xl border-0 bg-white py-2.5 pe-3.5 ps-10 text-sm text-zinc-900 ring-1 ring-zinc-200 transition placeholder:text-zinc-400 hover:ring-zinc-300 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:text-white dark:ring-white/10';
        $fieldIcon = 'pointer-events-none absolute start-3.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400';
        $label = 'mb-1.5 block text-sm font-medium text-zinc-800 dark:text-white';
    @endphp
    <flux:modal name="payment-form" class="w-full max-w-2xl overflow-visible p-0!" :closable="false">
        <form wire:submit="savePayment" class="flex flex-col"
            x-data="{
                billed: {{ $billed }}, received: {{ $received }}, methodOpen: false,
                get amount() { return Math.max(0, parseFloat($wire.payAmount) || 0) },
                get after() { return Math.max(0, this.billed - this.received - this.amount) },
                get pct() { return this.billed > 0 ? Math.min(100, Math.round((this.received + this.amount) / this.billed * 100)) : 0 },
                get before() { return this.billed > 0 ? Math.min(100, Math.round(this.received / this.billed * 100)) : 0 },
                money(v) { return @js(config('app.currency')) + ' ' + Math.round(v).toLocaleString('en-US') },
            }">
            {{-- Summary across the top --}}
            <div class="relative overflow-hidden rounded-t-xl bg-linear-to-br from-emerald-500 via-emerald-600 to-teal-700 px-6 pt-5 pb-6 text-white">
                <div class="absolute -top-16 -right-10 size-48 rounded-full bg-white/10"></div>
                <div class="absolute -bottom-20 left-1/3 size-48 rounded-full bg-sun/20 blur-3xl"></div>

                <div class="relative flex items-center gap-3">
                    <x-client-avatar :client="$c" tint="bg-white/20 text-white" class="size-11 rounded-xl text-sm ring-2 ring-white/30" />
                    <div class="min-w-0 flex-1">
                        <flux:heading size="lg" class="text-white!">{{ __('Record payment') }}</flux:heading>
                        <p class="truncate text-sm text-white/75">{{ __('From :name', ['name' => $c->displayName()]) }}</p>
                    </div>
                    <flux:modal.close>
                        <button type="button" class="flex size-9 items-center justify-center rounded-lg text-white/70 transition hover:bg-white/15 hover:text-white" aria-label="{{ __('Close') }}"><flux:icon.x-mark variant="mini" class="size-5" /></button>
                    </flux:modal.close>
                </div>

                <div class="relative mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="rounded-xl bg-white/10 px-3 py-2.5 ring-1 ring-white/15">
                        <p class="text-[0.6875rem] text-white/70">{{ __('Total billed') }}</p>
                        <p class="mt-0.5 truncate text-sm font-semibold tabular-nums">{{ Client::money($billed) }}</p>
                    </div>
                    <div class="rounded-xl bg-white/10 px-3 py-2.5 ring-1 ring-white/15">
                        <p class="text-[0.6875rem] text-white/70">{{ __('Received so far') }}</p>
                        <p class="mt-0.5 truncate text-sm font-semibold tabular-nums">{{ Client::money($received) }}</p>
                    </div>
                    <div class="rounded-xl bg-white/10 px-3 py-2.5 ring-1 ring-white/15">
                        <p class="text-[0.6875rem] text-white/70">{{ __('This payment') }}</p>
                        <p class="mt-0.5 truncate text-sm font-semibold text-sun tabular-nums" x-text="'+ ' + money(amount)">+ {{ Client::money(0) }}</p>
                    </div>
                    <div class="rounded-xl bg-white px-3 py-2.5 text-emerald-900 shadow-sm">
                        <p class="text-[0.6875rem] text-emerald-700/80">{{ __('Outstanding after') }}</p>
                        <p class="mt-0.5 truncate text-sm font-bold tabular-nums" x-text="money(after)">{{ Client::money($outstanding) }}</p>
                    </div>
                </div>

                <div class="relative mt-4 flex items-center gap-3">
                    <div class="relative h-2 flex-1 overflow-hidden rounded-full bg-white/20">
                        <div class="absolute inset-y-0 left-0 rounded-full bg-sun transition-[width] duration-500" :style="`width: ${pct}%`"></div>
                        <div class="absolute inset-y-0 left-0 rounded-full bg-white/90 transition-[width] duration-500" :style="`width: ${before}%`"></div>
                    </div>
                    <span class="text-xs font-semibold tabular-nums"><span x-text="pct"></span>% {{ __('received') }}</span>
                </div>
            </div>

            {{-- Form --}}
            <div class="flex flex-col gap-5 px-6 py-6">
                {{-- Amount --}}
                <div>
                    <label for="pay-amount" class="{{ $label }}">{{ __('Amount') }}</label>
                    <div class="flex items-center rounded-2xl bg-zinc-50 ring-1 ring-zinc-200 transition focus-within:bg-white focus-within:ring-2 focus-within:ring-emerald-500 dark:bg-white/5 dark:ring-white/10">
                        <span class="ms-2 rounded-lg bg-white px-2.5 py-1.5 text-xs font-bold text-zinc-500 shadow-xs ring-1 ring-zinc-200 dark:bg-white/10 dark:ring-white/10">{{ config('app.currency') }}</span>
                        <input id="pay-amount" wire:model="payAmount" type="number" min="0" step="0.01" inputmode="decimal" required placeholder="0" class="w-full border-0 bg-transparent px-3 py-3 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums placeholder:text-zinc-300 focus:ring-0 focus:outline-none dark:text-white" />
                    </div>
                    <flux:error name="payAmount" />
                </div>

                {{-- Type + date --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="pay-type" class="{{ $label }}">{{ __('Payment type') }}</label>
                        <div class="relative">
                            <flux:icon.tag variant="micro" class="{{ $fieldIcon }}" />
                            <input id="pay-type" wire:model="payType" type="text" maxlength="60" required placeholder="{{ __('e.g. Advance payment') }}" class="{{ $field }}" data-test="payment-type-input" />
                        </div>
                        <flux:error name="payType" />
                    </div>
                    <div>
                        <p class="{{ $label }}">{{ __('Payment date') }}</p>
                        <x-nepali-date wire:model="payDue" />
                        <flux:error name="payDue" />
                    </div>
                </div>

                {{-- Received in --}}
                <div class="relative" @click.outside="methodOpen = false" @keydown.escape.stop="methodOpen = false">
                    <span class="{{ $label }}">{{ __('Received in') }}</span>
                    <button type="button" @click="methodOpen = ! methodOpen" class="flex w-full items-center gap-3 rounded-xl bg-white px-3 py-2.5 text-start ring-1 ring-zinc-200 transition hover:ring-zinc-300 focus:ring-2 focus:ring-brand-500 focus:outline-none dark:bg-white/5 dark:ring-white/10" :aria-expanded="methodOpen" aria-haspopup="listbox" data-test="payment-method-button">
                        <template x-if="! $wire.payMethod">
                            <span class="flex items-center gap-3">
                                <span class="flex size-9 items-center justify-center rounded-lg border border-dashed border-zinc-300 text-zinc-400 dark:border-white/20"><flux:icon.wallet variant="mini" class="size-4.5" /></span>
                                <span class="text-sm text-zinc-400">{{ __('Choose where it was received') }}</span>
                            </span>
                        </template>
                        @foreach ($methodLook as $key => $m)
                            <span x-show="$wire.payMethod === @js($key)" class="flex items-center gap-3" x-cloak>
                                <span class="{{ $m['tile'] }} flex size-9 items-center justify-center rounded-lg"><flux:icon :icon="$m['icon']" variant="mini" class="size-4.5" /></span>
                                <span>
                                    <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ __($key) }}</span>
                                    <span class="block text-xs text-zinc-500">{{ $m['hint'] }}</span>
                                </span>
                            </span>
                        @endforeach
                        <flux:icon.chevron-up-down variant="mini" class="ms-auto size-4 text-zinc-400" />
                    </button>
                    <div x-show="methodOpen" x-transition.origin.top x-cloak class="absolute inset-x-0 top-full z-30 mt-1.5 grid grid-cols-2 gap-1 rounded-xl bg-white p-1.5 shadow-[0_20px_50px_-12px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10" role="listbox">
                        @foreach ($methodLook as $key => $m)
                            <button type="button" role="option" @click="$wire.payMethod = @js($key); methodOpen = false" :aria-selected="$wire.payMethod === @js($key)" class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-start transition hover:bg-zinc-50 dark:hover:bg-white/5" :class="$wire.payMethod === @js($key) && 'bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/30'">
                                <span class="{{ $m['tile'] }} flex size-8 shrink-0 items-center justify-center rounded-lg"><flux:icon :icon="$m['icon']" variant="micro" class="size-4" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ __($key) }}</span>
                                    <span class="block truncate text-[0.6875rem] text-zinc-500">{{ $m['hint'] }}</span>
                                </span>
                                <flux:icon.check-circle variant="mini" class="size-4 text-brand-600" x-show="$wire.payMethod === @js($key)" />
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="payMethod" />
                </div>

                {{-- Reference + note --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="pay-ref" class="{{ $label }}">{{ __('Reference') }} <span class="font-normal text-zinc-400">{{ __('optional') }}</span></label>
                        <div class="relative">
                            <flux:icon.hashtag variant="micro" class="{{ $fieldIcon }}" />
                            <input id="pay-ref" wire:model="payReference" type="text" maxlength="255" placeholder="{{ __('Invoice no. or transaction ID') }}" class="{{ $field }}" />
                        </div>
                    </div>
                    <div>
                        <label for="pay-note" class="{{ $label }}">{{ __('Note') }} <span class="font-normal text-zinc-400">{{ __('optional') }}</span></label>
                        <div class="relative">
                            <flux:icon.pencil variant="micro" class="{{ $fieldIcon }}" />
                            <input id="pay-note" wire:model="payNote" type="text" maxlength="255" placeholder="{{ __('Anything to remember') }}" class="{{ $field }}" />
                        </div>
                    </div>
                </div>

                {{-- Proof of payment --}}
                <div>
                    <span class="{{ $label }}">{{ __('Payment proof') }} <span class="font-normal text-zinc-400">{{ __('optional') }}</span></span>
                    @if ($payProof)
                        <div class="flex items-center gap-3 rounded-xl bg-emerald-50/60 p-2.5 ring-1 ring-emerald-200/70 dark:bg-emerald-500/10 dark:ring-emerald-500/20">
                            @if (str_starts_with((string) $payProof->getMimeType(), 'image/'))
                                <img src="{{ $payProof->temporaryUrl() }}" alt="" class="size-14 shrink-0 rounded-lg object-cover ring-1 ring-black/5">
                            @else
                                <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-white text-rose-500 ring-1 ring-black/5 dark:bg-white/10"><flux:icon.document-text class="size-6" /></span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $payProof->getClientOriginalName() }}</p>
                                <p class="text-xs text-emerald-700 dark:text-emerald-300">{{ __('Ready to attach') }}</p>
                            </div>
                            <button type="button" wire:click="$set('payProof', null)" class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-white hover:text-rose-500 dark:hover:bg-white/10" aria-label="{{ __('Remove file') }}"><flux:icon.x-mark variant="mini" class="size-4" /></button>
                        </div>
                    @else
                        <label x-data="{ over: false }" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="over = false; $refs.proof.files = $event.dataTransfer.files; $refs.proof.dispatchEvent(new Event('change'))"
                            class="flex cursor-pointer items-center gap-4 rounded-xl border-2 border-dashed px-4 py-3.5 transition" :class="over ? 'border-emerald-400 bg-emerald-50/60 dark:bg-emerald-500/10' : 'border-zinc-200 hover:border-emerald-300 hover:bg-emerald-50/30 dark:border-white/10 dark:hover:bg-white/[0.03]'">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.camera variant="mini" class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __('Add a photo or screenshot of the payment') }}</span>
                                <span class="block text-xs text-zinc-500">{{ __('Drop it here or click to choose · JPG, PNG, WebP or PDF, up to 8 MB') }}</span>
                            </span>
                            <input x-ref="proof" type="file" wire:model="payProof" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*" class="sr-only" data-test="payment-proof-input">
                        </label>
                    @endif
                    <div wire:loading wire:target="payProof" class="mt-1.5 text-xs text-zinc-500">{{ __('Uploading…') }}</div>
                    <flux:error name="payProof" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <button type="submit" class="inline-flex h-10 items-center gap-2 rounded-lg bg-linear-to-r from-emerald-500 to-emerald-600 px-4 text-sm font-semibold text-white shadow-md shadow-emerald-500/30 transition hover:from-emerald-600 hover:to-emerald-700 disabled:opacity-60" wire:loading.attr="disabled" wire:target="savePayment" data-test="save-payment-button">
                    <flux:icon.check variant="mini" class="size-4" />
                    <span x-text="amount > 0 ? @js(__('Save')) + ' ' + money(amount) : @js(__('Save payment'))">{{ __('Save payment') }}</span>
                </button>
            </div>
        </form>
    </flux:modal>

    {{-- Agreement --}}
    <flux:modal name="agreement-form" class="w-full max-w-lg">
        <form wire:submit="saveAgreement" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $hasAgreement ? __('Edit agreement') : __('Add agreement') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Contract period and the signed copy for :name.', ['name' => $c->name]) }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-nepali-date wire:model="contractStart" :label="__('Contract start')" />
                <x-nepali-date wire:model="contractEnd" :label="__('Contract end')" />
            </div>
            <flux:switch wire:model="agreementSigned" :label="__('The client has signed the agreement')" />
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Agreement file') }} <span class="font-normal text-zinc-400">({{ __('optional') }})</span></span>
                @include('partials.file-drop', ['model' => 'agreementFile', 'file' => $agreementFile])
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-agreement-button">{{ __('Save agreement') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Upload document --}}
    <flux:modal name="document-form" class="w-full max-w-md">
        <form wire:submit="uploadDocument" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Upload document') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Invoices, proposals or any other file for :name.', ['name' => $c->name]) }}</flux:text>
            </div>
            <flux:select wire:model="uploadType" :label="__('Type')">
                @foreach (Document::TYPES as $key => $label)
                    <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>
            @include('partials.file-drop', ['model' => 'upload', 'file' => $upload])
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-document-button">{{ __('Upload') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Notes --}}
    <flux:modal name="notes-form" class="w-full max-w-lg">
        <form wire:submit="saveNotes" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ __('Notes') }}</flux:heading>
            <flux:textarea wire:model="notes" rows="6" :placeholder="__('Internal notes about this client')" :aria-label="__('Notes')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save notes') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    {{-- Delete a type of work --}}
    <flux:modal name="type-delete" class="w-full max-w-sm">
        @php
            $dt = $deletingType ? ($types[$deletingType] ?? null) : null;
            $dtCount = $deletingType ? Task::where('type', $deletingType)->count() : 0;
        @endphp
        @if ($dt)
            <div class="flex flex-col items-center gap-4 text-center">
                <div class="relative">
                    <span class="{{ $toneOf($deletingType)['chip'] }} flex size-16 items-center justify-center rounded-2xl ring-1 ring-inset"><flux:icon :icon="$dt['icon']" class="size-8" /></span>
                    <span class="absolute -right-2 -bottom-2 flex size-8 items-center justify-center rounded-full bg-rose-500 text-white shadow-md ring-4 ring-white dark:ring-zinc-900"><flux:icon.trash variant="micro" class="size-4" /></span>
                </div>
                <div>
                    <flux:heading size="lg">{{ __('Delete “:name”?', ['name' => $dt['label']]) }}</flux:heading>
                    <flux:text class="mt-1.5">{{ __('It is removed from the type of work list and its box disappears from every client.') }}</flux:text>
                </div>
                @if ($dtCount)
                    <p class="flex w-full items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-start text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                        <flux:icon.information-circle variant="mini" class="size-4 shrink-0" />
                        {{ trans_choice(':count task using it moves to Task, so nothing is lost.|:count tasks using it move to Task, so nothing is lost.', $dtCount) }}
                    </p>
                @endif
                <div class="flex w-full gap-2">
                    <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Keep it') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" icon="trash" wire:click="deleteWorkType('{{ $deletingType }}')" class="flex-1" data-test="confirm-delete-type">{{ __('Delete') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
