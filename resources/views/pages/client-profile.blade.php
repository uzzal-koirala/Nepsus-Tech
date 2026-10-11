<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Payment;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Client profile')] class extends Component {
    use WithFileUploads;

    public Client $client;

    public string $taskTitle = '';
    public string $taskDue = '';

    public string $payAmount = '';
    public string $payDue = '';
    public string $payMethod = '';
    public string $payReference = '';
    public bool $payPaid = false;

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
    <a href="{{ route('clients.index') }}" wire:navigate class="inline-flex w-fit items-center gap-1.5 text-sm font-medium text-zinc-500 transition hover:text-brand-600 dark:text-zinc-400">
        <flux:icon.arrow-left variant="micro" class="size-4" />
        {{ __('Clients') }}
    </a>

    {{-- Header --}}
    <header class="{{ $panel }} overflow-hidden">
        <div class="{{ $theme['cover'] ?? 'from-zinc-200 via-zinc-100 to-zinc-300 dark:from-zinc-800 dark:via-zinc-900 dark:to-zinc-800' }} relative h-28 bg-linear-to-r sm:h-32">
            <div class="absolute inset-0 opacity-30 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:18px_18px] [mask-image:linear-gradient(to_left,black,transparent_70%)]"></div>
            @if ($pkg)
                <flux:icon :icon="$pkg->icon" class="absolute -right-4 -bottom-8 size-40 opacity-20" />
            @endif
        </div>

        <div class="flex flex-col gap-5 px-5 pb-5 sm:px-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex min-w-0 items-end gap-4">
                    <span class="relative -mt-10 shrink-0">
                        <x-client-avatar :client="$c" tint="bg-brand-100 text-brand-700 dark:bg-brand-500/30 dark:text-white" class="size-20 rounded-2xl text-2xl shadow-lg ring-4 ring-white dark:ring-zinc-950" />
                        <span class="{{ $dots[$c->status] }} absolute -end-1 -bottom-1 size-4 rounded-full ring-[3px] ring-white dark:ring-zinc-950"></span>
                    </span>
                    <div class="min-w-0 pt-3">
                        <h1 class="truncate text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-[1.75rem] dark:text-white">{{ $c->name }}</h1>
                        <p class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $c->company ?? __('Independent') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 pt-3">
                    <div class="flex items-center">
                        @foreach ($c->socialLinks() as $network => $link)
                            <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="{{ $socials[$network]['class'] }} flex size-9 items-center justify-center rounded-lg text-zinc-400 transition" title="{{ $socials[$network]['label'] }}">
                                <x-social-icon :name="$network" class="size-4" />
                                <span class="sr-only">{{ $socials[$network]['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                    @if ($c->phone)
                        <flux:button :href="'tel:'.preg_replace('/[^\d+]/', '', $c->phone)" icon="phone">{{ __('Call') }}</flux:button>
                    @endif
                    <flux:button variant="primary" icon="pencil-square" :href="route('clients.index', ['open' => $c->id])" wire:navigate>{{ __('Edit') }}</flux:button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="{{ $badges[$c->status] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium capitalize ring-1 ring-inset">
                    <span class="{{ $dots[$c->status] }} size-1.5 rounded-full"></span>
                    {{ __($c->status) }}
                </span>
                @if ($pkg)
                    <span class="{{ $theme['chip'] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold">
                        <flux:icon :icon="$pkg->icon" variant="micro" class="size-3.5" />
                        {{ $pkg->name }}
                    </span>
                @endif
                @if ($c->phone)
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs text-zinc-600 tabular-nums dark:bg-white/5 dark:text-zinc-300">
                        <flux:icon.phone variant="micro" class="size-3.5" /> {{ $c->phone }}
                    </span>
                @endif
                <span class="text-xs text-zinc-400" title="{{ $c->created_at->format('j M Y') }}">{{ __('Client since') }} {{ $c->created_at->toNepaliMonth() }}</span>
            </div>
        </div>
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="{{ $iconTile }} {{ $theme['icon'] ?? 'bg-zinc-100 text-zinc-500 dark:bg-white/5' }}">
                    <flux:icon :icon="$pkg->icon ?? 'cube'" variant="mini" class="size-4.5" />
                </span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $pkg ? __(':name package', ['name' => $pkg->name]) : __('Package') }}</p>
            </div>
            @if ($c->price !== null)
                <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $c->formattedPrice() }}<span class="text-sm font-normal text-zinc-400">{{ $cycles[$c->billing_cycle] ?? '' }}</span></p>
            @else
                <p class="text-[1.625rem] leading-none font-semibold text-zinc-300 dark:text-zinc-600">{{ __('Not set') }}</p>
            @endif
            <p class="mt-auto text-xs text-zinc-500">{{ ['monthly' => __('Billed every month'), 'yearly' => __('Billed every year'), 'one_time' => __('One-time payment')][$c->billing_cycle] ?? '' }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="{{ $iconTile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Payments received') }}</p>
                @if ($stats['overduePayments'])
                    <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $stats['overduePayments']) }}</span>
                @endif
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ Client::money($stats['received']) }}</p>
            <div class="mt-auto flex flex-col gap-2">
                <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $paidPct }}%"></div></div>
                <p class="text-xs text-zinc-500">{{ __(':outstanding still due of :billed', ['outstanding' => Client::money($stats['billed'] - $stats['received']), 'billed' => Client::money($stats['billed'])]) }}</p>
            </div>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span class="{{ $iconTile }} bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.check-circle variant="mini" class="size-4.5" /></span>
                <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Tasks completed') }}</p>
                @if ($stats['tasksOverdue'])
                    <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $stats['tasksOverdue']) }}</span>
                @endif
            </div>
            <p class="text-[1.625rem] leading-none font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $stats['tasksDone'] }}<span class="text-base font-normal text-zinc-400"> / {{ $stats['tasksTotal'] }}</span></p>
            <div class="mt-auto flex flex-col gap-2">
                <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-brand-500" style="width: {{ $taskPct }}%"></div></div>
                <p class="text-xs text-zinc-500">{{ $stats['tasksTotal'] ? __(':pct% of the work is done', ['pct' => $taskPct]) : __('No tasks yet') }}</p>
            </div>
        </div>

        <div class="{{ $panel }} flex flex-col gap-4 p-5">
            <div class="flex items-center gap-3">
                <span @class([$iconTile, 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' => $c->agreement_signed_at, 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300' => ! $c->agreement_signed_at])>
                    <flux:icon.document-check variant="mini" class="size-4.5" />
                </span>
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Agreement') }}</p>
            </div>
            <p @class(['text-[1.625rem] leading-none font-semibold tracking-tight', 'text-emerald-600 dark:text-emerald-400' => $c->agreement_signed_at, 'text-amber-600 dark:text-amber-400' => ! $c->agreement_signed_at])>
                {{ $c->agreement_signed_at ? __('Signed') : __('Not signed') }}
            </p>
            <p class="mt-auto text-xs text-zinc-500">
                @if ($c->contract_end)
                    @if ($c->contract_end->isBefore(today()))
                        {{ __('Ended') }} {{ $c->contract_end->toNepali() }}
                    @else
                        {{ __('Until') }} {{ $c->contract_end->toNepali() }} · {{ trans_choice(':count day left|:count days left', (int) today()->diffInDays($c->contract_end)) }}
                    @endif
                @else
                    {{ __('No contract dates yet') }}
                @endif
            </p>
        </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-3">
        {{-- Left column --}}
        <div class="flex flex-col gap-6 xl:col-span-2">
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
                    <div wire:key="task-{{ $task->id }}" class="group flex items-center gap-3 border-b border-zinc-100 px-5 py-3 transition last:border-0 hover:bg-zinc-50/70 dark:border-white/5 dark:hover:bg-white/[0.02]">
                        <button type="button" wire:click="toggleTask({{ $task->id }})" @class([
                            'flex size-5 shrink-0 items-center justify-center rounded-md ring-1 transition',
                            'bg-emerald-500 text-white ring-emerald-500' => $task->completed_at,
                            'text-transparent ring-zinc-300 hover:ring-brand-500 dark:ring-zinc-600' => ! $task->completed_at,
                        ]) aria-label="{{ $task->completed_at ? __('Mark as not done') : __('Mark as done') }}">
                            <flux:icon.check variant="micro" class="size-3.5" />
                        </button>
                        <p @class(['min-w-0 flex-1 truncate text-sm', 'text-zinc-400 line-through' => $task->completed_at, 'text-zinc-800 dark:text-zinc-100' => ! $task->completed_at])>{{ $task->title }}</p>
                        @if ($task->due_date)
                            <span @class([
                                'inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs',
                                'bg-rose-50 font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $task->isOverdue(),
                                'text-zinc-400' => ! $task->isOverdue(),
                            ]) title="{{ $task->due_date->format('j M Y') }}">
                                <flux:icon.calendar variant="micro" class="size-3" />
                                {{ $task->due_date->toNepali(withYear: false) }}
                            </span>
                        @endif
                        <button type="button" wire:click="deleteTask({{ $task->id }})" class="text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:text-rose-500 focus:opacity-100" aria-label="{{ __('Delete task') }}">
                            <flux:icon.trash variant="micro" class="size-4" />
                        </button>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-3 px-5 py-12 text-center">
                        <span class="{{ $iconTile }} bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.check-circle variant="mini" class="size-4.5" /></span>
                        <p class="text-sm text-zinc-500">{{ __('No tasks yet for this client.') }}</p>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="newTask">{{ __('Add the first task') }}</flux:button>
                    </div>
                @endforelse
            </section>

            {{-- Payments --}}
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <div class="flex items-center gap-2.5">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Payments') }}</h2>
                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-500 tabular-nums dark:bg-white/5">{{ $this->payments->count() }}</span>
                    </div>
                    <flux:button size="sm" icon="plus" wire:click="newPayment" data-test="record-payment-button">{{ __('Record payment') }}</flux:button>
                </div>

                @if ($this->payments->isEmpty())
                    <div class="flex flex-col items-center gap-3 px-5 py-12 text-center">
                        <span class="{{ $iconTile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><flux:icon.banknotes variant="mini" class="size-4.5" /></span>
                        <p class="text-sm text-zinc-500">{{ __('No payments recorded yet.') }}</p>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="newPayment">{{ __('Record the first payment') }}</flux:button>
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
        </div>

        {{-- Right column --}}
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

            {{-- Documents --}}
            <section class="{{ $panel }}">
                <div class="{{ $panelHead }}">
                    <div class="flex items-center gap-2.5">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Documents') }}</h2>
                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-500 tabular-nums dark:bg-white/5">{{ $otherDocs->count() }}</span>
                    </div>
                    <flux:button size="sm" icon="arrow-up-tray" wire:click="newDocument" data-test="upload-document-button">{{ __('Upload') }}</flux:button>
                </div>
                <div class="flex flex-col gap-2 p-5">
                    @forelse ($otherDocs as $doc)
                        @include('partials.document-row', ['doc' => $doc, 'fileSize' => $fileSize])
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500">{{ __('Invoices, proposals and other files will show here.') }}</p>
                    @endforelse
                </div>
            </section>

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

            {{-- Details --}}
            <section class="{{ $panel }}">
                <h2 class="border-b border-zinc-100 px-5 py-4 font-semibold text-zinc-900 dark:border-white/5 dark:text-white">{{ __('Details') }}</h2>
                <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                    @foreach ([
                        __('Phone') => $c->phone,
                        __('Email') => $c->email,
                        __('Company') => $c->company,
                        __('Billing') => ['monthly' => __('Monthly'), 'yearly' => __('Yearly'), 'one_time' => __('One-time')][$c->billing_cycle] ?? null,
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
