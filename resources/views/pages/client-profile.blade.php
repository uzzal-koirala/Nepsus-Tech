<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Payment;
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

    public const TABS = ['overview', 'tasks', 'payments', 'documents', 'notes'];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $taskTitle = '';
    public string $taskDue = '';

    public string $payAmount = '';
    public string $payDue = '';
    public string $payMethod = '';
    public string $payReference = '';
    public bool $payPaid = false;

    public string $planType = 'half_advance';
    public string $planAmount = '';
    public string $planStart = '';
    public string $planMonths = '';
    public string $planEnd = '';
    public string $planInstallments = '3';
    public bool $planAdvancePaid = false;

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
    }

    #[Computed]
    public function tasks()
    {
        return $this->client->tasks()
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

    public function newTask(): void
    {
        $this->reset('taskTitle', 'taskDue');
        $this->resetErrorBag();
        Flux::modal('task-form')->show();
    }

    public function addTask(): void
    {
        $data = $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDue' => ['nullable', 'date'],
        ]);

        $this->client->tasks()->create(['title' => $data['taskTitle'], 'due_date' => $data['taskDue'] ?: null]);
        $this->reset('taskTitle', 'taskDue');

        Flux::modal('task-form')->close();
        Flux::toast(variant: 'success', text: __('Task added.'));
    }

    public function toggleTask(int $id): void
    {
        $task = $this->client->tasks()->findOrFail($id);
        $task->update(['completed_at' => $task->completed_at ? null : now()]);
    }

    public function deleteTask(int $id): void
    {
        $this->client->tasks()->findOrFail($id)->delete();
    }

    // Payments

    // Payment plan

    public function openPlan(): void
    {
        $this->planType = $this->client->payment_plan ?? 'half_advance';
        $this->planAmount = (string) ($this->client->totalValue() ?? '');
        $this->planStart = (string) ($this->client->contract_start ?? today())->toDateString();
        $this->planMonths = (string) ($this->client->agreementMonths() ?? 1);
        $this->planEnd = (string) $this->client->contract_end?->toDateString();
        $this->planInstallments = (string) ($this->client->installments ?? 3);
        $this->planAdvancePaid = false;
        $this->resetErrorBag();

        Flux::modal('payment-plan')->show();
    }

    /** Payments the plan will add, recalculated as the fields change. */
    #[Computed]
    public function planPreview(): array
    {
        $amount = (float) $this->planAmount;

        if ($amount <= 0 || ! $this->planStart || ! array_key_exists($this->planType, Client::PAYMENT_PLANS)) {
            return [];
        }

        try {
            $start = Date::parse($this->planStart);
            $end = $this->client->service_type === 'social_media'
                ? ((int) $this->planMonths > 0 ? NepaliDate::periodEndAfter($start, (int) $this->planMonths) : null)
                : ($this->planEnd ? Date::parse($this->planEnd) : null);
        } catch (\Throwable) {
            return [];
        }

        return Client::paymentSchedule($amount, $this->planType, $start, $end, max(2, min(36, (int) $this->planInstallments)));
    }

    public function savePlan(): void
    {
        $this->validate([
            'planType' => ['required', Rule::in(array_keys(Client::PAYMENT_PLANS))],
            'planAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'planStart' => ['required', 'date'],
            'planMonths' => ['nullable', 'integer', 'min:1', 'max:120'],
            'planEnd' => ['nullable', 'date', 'after_or_equal:planStart'],
            'planInstallments' => ['exclude_unless:planType,installments', 'required', 'integer', 'min:2', 'max:36'],
            'planAdvancePaid' => ['boolean'],
        ]);

        foreach ($this->planPreview as $i => $row) {
            $this->client->payments()->create([
                'amount' => $row['amount'],
                'due_date' => $row['due'],
                'note' => $row['label'],
                'paid_at' => $i === 0 && $this->planAdvancePaid && $this->planType !== 'after_work' ? now() : null,
            ]);
        }

        $this->client->update([
            'payment_plan' => $this->planType,
            'installments' => $this->planType === 'installments' ? (int) $this->planInstallments : null,
        ]);

        Flux::modal('payment-plan')->close();
        Flux::toast(variant: 'success', text: trans_choice(':count payment added to the schedule.|:count payments added to the schedule.', count($this->planPreview)));
    }

    public function newPayment(): void
    {
        $this->reset('payAmount', 'payDue', 'payMethod', 'payReference', 'payPaid');
        $this->payAmount = $this->client->price === null ? '' : rtrim(rtrim((string) $this->client->price, '0'), '.');
        $this->payDue = today()->toDateString();
        $this->resetErrorBag();
        Flux::modal('payment-form')->show();
    }

    public function savePayment(): void
    {
        $data = $this->validate([
            'payAmount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payDue' => ['nullable', 'date'],
            'payMethod' => ['nullable', Rule::in(Payment::METHODS)],
            'payReference' => ['nullable', 'string', 'max:255'],
            'payPaid' => ['boolean'],
        ]);

        $this->client->payments()->create([
            'amount' => $data['payAmount'],
            'due_date' => $data['payDue'] ?: null,
            'method' => $data['payMethod'] ?: null,
            'reference' => $data['payReference'] ?: null,
            'paid_at' => $data['payPaid'] ? now() : null,
        ]);

        Flux::modal('payment-form')->close();
        Flux::toast(variant: 'success', text: __('Payment recorded.'));
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
                            <flux:menu.item icon="calendar-days" wire:click="openPlan">{{ __('Payment plan') }}</flux:menu.item>
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
                    <button type="button" wire:click="openPlan" class="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:underline dark:text-brand-300"><flux:icon.plus variant="micro" class="size-3.5" />{{ __('Set up a payment plan') }}</button>
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
        @foreach ($tabs as $key => $t)
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white shadow-md shadow-brand-500/25' => $tab === $key,
                    'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' => $tab !== $key,
                ])
                @if ($tab === $key) aria-current="page" @endif
                data-test="tab-{{ $key }}"
            >
                <flux:icon :icon="$t['icon']" variant="micro" class="size-4" />
                {{ $t['label'] }}
                @if (! empty($t['alert']))
                    <span class="rounded-md bg-rose-500 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-white tabular-nums">{{ $t['alert'] }}</span>
                @elseif ($t['count'])
                    <span @class(['rounded-md px-1.5 py-0.5 text-[0.6875rem] tabular-nums', 'bg-white/20' => $tab === $key, 'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $tab !== $key])>{{ $t['count'] }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <div class="grid items-start gap-6 xl:grid-cols-3">
            <div class="flex flex-col gap-6 xl:col-span-2">
            {{-- Next up --}}
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Next up') }}</h2>
                </div>

                @if ($np = $this->nextPayment)
                    @php
                        $npDays = $np->dueInDays();
                    @endphp
                    <div class="flex flex-wrap items-center gap-4 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                        <span @class([
                            $iconTile, 'size-11',
                            'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' => $npDays !== null && $npDays < 0,
                            'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300' => $npDays !== null && $npDays >= 0 && $npDays <= \App\Models\Payment::REMIND_DAYS,
                            'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => $npDays === null || $npDays > \App\Models\Payment::REMIND_DAYS,
                        ])>
                            <flux:icon.banknotes variant="mini" class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-zinc-500">{{ __('Next payment') }}{{ $np->note ? ' · '.$np->note : '' }}</p>
                            <p class="text-lg font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ Client::money($np->amount) }}</p>
                            <p @class(['text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $npDays !== null && $npDays < 0, 'text-zinc-500' => $npDays === null || $npDays >= 0])>
                                @if ($np->due_date)
                                    {{ $np->due_date->toNepali() }} ·
                                    {{ $npDays < 0 ? trans_choice('overdue by :count day|overdue by :count days', abs($npDays)) : ($npDays === 0 ? __('due today') : trans_choice('due tomorrow|due in :count days', $npDays)) }}
                                @else
                                    {{ __('No due date') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:button size="sm" variant="primary" icon="check" wire:click="togglePaid({{ $np->id }})">{{ __('Mark paid') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="setTab('payments')">{{ __('All payments') }}</flux:button>
                        </div>
                    </div>
                @else
                    <div class="flex items-center gap-3 border-b border-zinc-100 px-5 py-4 text-sm text-zinc-500 dark:border-white/5">
                        <span class="{{ $iconTile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check-badge variant="mini" class="size-4.5" /></span>
                        {{ $this->payments->isEmpty() ? __('No payments scheduled yet.') : __('All payments are received.') }}
                        <flux:button size="sm" variant="ghost" icon="calendar-days" wire:click="openPlan" class="ms-auto">{{ __('Payment plan') }}</flux:button>
                    </div>
                @endif

                <div class="flex items-center justify-between px-5 pt-4 pb-1">
                    <p class="text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Open tasks') }}</p>
                    <div class="flex items-center gap-1">
                        <flux:button size="xs" variant="ghost" icon="plus" wire:click="newTask">{{ __('Add') }}</flux:button>
                        @if ($openTasks->count() > 5)
                            <flux:button size="xs" variant="ghost" wire:click="setTab('tasks')">{{ __('See all :count', ['count' => $openTasks->count()]) }}</flux:button>
                        @endif
                    </div>
                </div>
                @forelse ($openTasks->take(5) as $task)
                    @include('partials.task-row', ['task' => $task, 'prefix' => 'next'])
                @empty
                    <p class="px-5 pt-2 pb-5 text-sm text-zinc-500">{{ __('Nothing open. Nice work.') }}</p>
                @endforelse
            </section>

            </div>
            <div class="flex flex-col gap-6">
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

            {{-- Details --}}
            <section class="{{ $panel }}">
                <h2 class="border-b border-zinc-100 px-5 py-4 font-semibold text-zinc-900 dark:border-white/5 dark:text-white">{{ __('Details') }}</h2>
                <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                    @foreach ([
                        __('Phone') => $c->phone,
                        __('Email') => $c->email,
                        __('Company') => $c->company,
                        __('Service') => __(Client::SERVICE_TYPES[$c->service_type]['label'] ?? '-'),
                        __('Billing') => $c->billingText(),
                        __('Total value') => $c->service_type === 'video' && $c->totalValue() ? Client::money($c->totalValue()) : null,
                        __('Work progress') => $c->progress.'%',
                        __('Added') => $c->created_at->toNepali(),
                    ] as $label => $value)
                        <div class="flex items-center justify-between gap-4 px-5 py-3">
                            <dt class="text-zinc-500">{{ $label }}</dt>
                            <dd class="truncate text-end font-medium text-zinc-800 dark:text-zinc-100">{{ $value ?: '-' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
            </div>
        </div>
    @elseif ($tab === 'tasks')
            {{-- Tasks --}}
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <div class="flex items-center gap-2.5">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Tasks') }}</h2>
                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-500 tabular-nums dark:bg-white/5">{{ $stats['tasksDone'] }}/{{ $stats['tasksTotal'] }}</span>
                    </div>
                    <flux:button size="sm" icon="plus" wire:click="newTask" data-test="add-task-button">{{ __('Add task') }}</flux:button>
                </div>

                @forelse ($this->tasks as $task)
                    @include('partials.task-row', ['task' => $task])
                @empty
                    <div class="flex flex-col items-center gap-3 px-5 py-12 text-center">
                        <span class="{{ $iconTile }} bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.check-circle variant="mini" class="size-4.5" /></span>
                        <p class="text-sm text-zinc-500">{{ __('No tasks yet for this client.') }}</p>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="newTask">{{ __('Add the first task') }}</flux:button>
                    </div>
                @endforelse
            </section>
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
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <div class="flex items-center gap-2.5">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Payments') }}</h2>
                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-500 tabular-nums dark:bg-white/5">{{ $this->payments->count() }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="calendar-days" wire:click="openPlan" data-test="payment-plan-button">{{ __('Payment plan') }}</flux:button>
                        <flux:button size="sm" icon="plus" wire:click="newPayment" data-test="record-payment-button">{{ __('Record payment') }}</flux:button>
                    </div>
                </div>

                @if ($this->payments->isEmpty())
                    <div class="flex flex-col items-center gap-3 px-5 py-12 text-center">
                        <span class="{{ $iconTile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                        <p class="text-sm text-zinc-500">{{ __('No payments recorded yet.') }}</p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <flux:button size="sm" variant="primary" icon="calendar-days" wire:click="openPlan">{{ __('Set up a payment plan') }}</flux:button>
                            <flux:button size="sm" icon="plus" wire:click="newPayment">{{ __('Record a payment') }}</flux:button>
                        </div>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-sm">
                            <thead>
                                <tr class="text-xs text-zinc-500">
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Due date') }}</th>
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Reference') }}</th>
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Method') }}</th>
                                    <th class="px-5 py-2.5 text-end font-medium">{{ __('Amount') }}</th>
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Status') }}</th>
                                    <th class="px-5 py-2.5"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                                @foreach ($this->payments as $payment)
                                    @php
                                        $ps = $payment->status();
                                    @endphp
                                    <tr wire:key="payment-{{ $payment->id }}" class="transition hover:bg-zinc-50/70 dark:hover:bg-white/[0.02]">
                                        <td class="px-5 py-3 text-zinc-700 dark:text-zinc-200" title="{{ $payment->due_date?->format('j M Y') }}">{{ $payment->due_date?->toNepali() ?: '-' }}</td>
                                        <td class="px-5 py-3 text-zinc-500 tabular-nums">{{ $payment->reference ?? '-' }}</td>
                                        <td class="px-5 py-3 text-zinc-500">{{ $payment->method ?? '-' }}</td>
                                        <td class="px-5 py-3 text-end font-semibold text-zinc-900 tabular-nums dark:text-white">{{ Client::money($payment->amount) }}</td>
                                        <td class="px-5 py-3 whitespace-nowrap">
                                            @php
                                                $inDays = $payment->dueInDays();
                                                $soon = $ps === 'pending' && $inDays !== null && $inDays <= \App\Models\Payment::REMIND_DAYS;
                                            @endphp
                                            <span @class([
                                                'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                                $payBadges[$ps] => ! $soon,
                                                'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300' => $soon,
                                            ])>
                                                @if ($soon)
                                                    <flux:icon.bell-alert variant="micro" class="size-3" />
                                                    {{ $inDays === 0 ? __('Due today') : trans_choice('Due tomorrow|Due in :count days', $inDays) }}
                                                @elseif ($ps === 'overdue')
                                                    {{ trans_choice('Overdue :count day|Overdue :count days', abs($inDays)) }}
                                                @else
                                                    {{ ['paid' => __('Paid'), 'pending' => __('Pending')][$ps] }}
                                                @endif
                                            </span>
                                            @if ($payment->paid_at)
                                                <span class="ms-1 text-xs text-zinc-400" title="{{ $payment->paid_at->format('j M Y') }}">{{ $payment->paid_at->toNepali(withYear: false) }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-end">
                                            <flux:dropdown align="end">
                                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" :aria-label="__('Payment actions')" />
                                                <flux:menu>
                                                    <flux:menu.item :icon="$payment->paid_at ? 'arrow-uturn-left' : 'check-circle'" wire:click="togglePaid({{ $payment->id }})">
                                                        {{ $payment->paid_at ? __('Mark as unpaid') : __('Mark as paid') }}
                                                    </flux:menu.item>
                                                    <flux:menu.item icon="trash" variant="danger" wire:click="deletePayment({{ $payment->id }})" wire:confirm="{{ __('Delete this payment?') }}">{{ __('Delete') }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
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

    {{-- Add task --}}
    <flux:modal name="task-form" class="w-full max-w-md">
        <form wire:submit="addTask" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Add task') }}</flux:heading>
                <flux:text class="mt-1">{{ __('A piece of work for :name.', ['name' => $c->name]) }}</flux:text>
            </div>
            <flux:input wire:model="taskTitle" :label="__('Task')" :placeholder="__('e.g. Design Facebook cover')" required />
            <x-nepali-date wire:model="taskDue" :label="__('Due date')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-task-button">{{ __('Add task') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Payment plan --}}
    <flux:modal name="payment-plan" class="w-full max-w-xl">
        <form wire:submit="savePlan" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Payment plan') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Choose how :name pays. The payments are added to the list below.', ['name' => $c->name]) }}</flux:text>
            </div>

            <div class="grid grid-cols-2 gap-2">
                @foreach (Client::PAYMENT_PLANS as $key => $plan)
                    <button
                        type="button"
                        wire:click="$set('planType', '{{ $key }}')"
                        @class([
                            'relative flex items-start gap-3 rounded-xl p-3 text-start ring-1 transition duration-200',
                            'bg-brand-50/70 ring-2 ring-brand-500 dark:bg-brand-500/10' => $planType === $key,
                            'bg-white ring-zinc-200 hover:ring-zinc-300 dark:bg-white/[0.03] dark:ring-white/10' => $planType !== $key,
                        ])
                    >
                        <span @class(['flex size-8 shrink-0 items-center justify-center rounded-lg', 'bg-brand-500 text-white' => $planType === $key, 'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $planType !== $key])>
                            <flux:icon :icon="$plan['icon']" variant="mini" class="size-4" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ __($plan['label']) }}</span>
                            <span class="block text-xs text-zinc-500">{{ __($plan['hint']) }}</span>
                        </span>
                    </button>
                @endforeach
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field class="sm:col-span-2">
                    <flux:label>{{ __('Total amount') }}</flux:label>
                    <flux:input.group>
                        <flux:input.group.prefix>{{ config('app.currency') }}</flux:input.group.prefix>
                        <flux:input wire:model.live.debounce.400ms="planAmount" type="number" min="0" step="1" inputmode="decimal" />
                    </flux:input.group>
                    <flux:error name="planAmount" />
                </flux:field>
                <x-nepali-date wire:model.live="planStart" :label="__('Start date')" />
                @if (in_array($planType, ['half_advance', 'after_work']) && $c->service_type !== 'social_media')
                    <x-nepali-date wire:model.live="planEnd" :label="__('End date')" />
                @elseif (in_array($planType, ['half_advance', 'after_work']))
                    <flux:field>
                        <flux:label>{{ __('Duration') }}</flux:label>
                        <flux:input.group>
                            <flux:input wire:model.live.debounce.400ms="planMonths" type="number" min="1" max="120" step="1" inputmode="numeric" placeholder="3" />
                            <flux:input.group.suffix>{{ __('month(s)') }}</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="planMonths" />
                    </flux:field>
                    @if ($planStart && (int) $planMonths > 0)
                        <div class="flex items-center gap-2.5 rounded-lg bg-emerald-50 px-3 py-2.5 text-xs text-emerald-800 ring-1 ring-emerald-100 sm:col-span-2 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
                            <flux:icon.document-check variant="micro" class="size-4 shrink-0" />
                            <span>
                                <span class="font-semibold">{{ Date::parse($planStart)->toNepali() }}</span>
                                <span class="opacity-60">→</span>
                                <span class="font-semibold">{{ NepaliDate::periodEndAfter(Date::parse($planStart), (int) $planMonths)->toNepali() }}</span>
                                <span class="opacity-75">· {{ trans_choice(':count month|:count months', (int) $planMonths) }}</span>
                            </span>
                        </div>
                    @endif
                @endif
                @if ($planType === 'installments')
                    <flux:field>
                        <flux:label>{{ __('Number of installments') }}</flux:label>
                        <flux:input.group>
                            <flux:input wire:model.live.debounce.400ms="planInstallments" type="number" min="2" max="36" step="1" />
                            <flux:input.group.suffix>{{ __('monthly') }}</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="planInstallments" />
                    </flux:field>
                @endif
            </div>

            @if ($planType !== 'after_work')
                <flux:checkbox wire:model.live="planAdvancePaid" :label="__('Advance / first payment already received')" />
            @endif

            @if ($this->planPreview)
                <div class="overflow-hidden rounded-xl ring-1 ring-zinc-200/80 dark:ring-white/10">
                    @foreach ($this->planPreview as $i => $row)
                        @php
                            $received = $i === 0 && $planAdvancePaid && $planType !== 'after_work';
                        @endphp
                        <div class="flex items-center gap-3 border-b border-zinc-100 px-3 py-2 text-sm last:border-0 dark:border-white/5">
                            <span @class(['flex size-6 shrink-0 items-center justify-center rounded-full text-[0.6875rem] font-semibold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $received, 'bg-zinc-100 text-zinc-500 dark:bg-white/5' => ! $received])>{{ $i + 1 }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-zinc-800 dark:text-zinc-100">{{ $row['label'] }}</span>
                                <span class="block text-xs text-zinc-400" title="{{ $row['due']->format('j M Y') }}">{{ $row['due']->toNepali() }}</span>
                            </span>
                            @if ($received)
                                <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Received') }}</span>
                            @endif
                            <span class="font-semibold text-zinc-900 tabular-nums dark:text-white">{{ Client::money($row['amount']) }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-zinc-400">{{ __('Enter the amount and start date to see the payments.') }}</p>
            @endif

            @if ($this->payments->isNotEmpty())
                <p class="-mt-2 text-xs text-amber-700 dark:text-amber-400">{{ trans_choice('This client already has :count payment. These will be added on top.|This client already has :count payments. These will be added on top.', $this->payments->count()) }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-plan-button">{{ __('Add payments') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Record payment --}}
    <flux:modal name="payment-form" class="w-full max-w-md">
        <form wire:submit="savePayment" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Record payment') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add an invoice or a payment for :name.', ['name' => $c->name]) }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="payAmount" type="number" min="0" step="0.01" :label="__('Amount (:currency)', ['currency' => config('app.currency')])" required class="sm:col-span-2" />
                <x-nepali-date wire:model="payDue" :label="__('Due date')" class="sm:col-span-2" />
                <flux:select wire:model="payMethod" :label="__('Method')">
                    <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                    @foreach (Payment::METHODS as $method)
                        <flux:select.option :value="$method">{{ $method }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="payReference" :label="__('Reference')" :placeholder="__('e.g. INV-1042')" />
                <flux:checkbox wire:model="payPaid" :label="__('Already received')" class="sm:col-span-2" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-payment-button">{{ __('Save payment') }}</flux:button>
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
</div>
