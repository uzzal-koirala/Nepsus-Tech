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

    public $upload;
    public string $uploadType = 'agreement';

    public string $notes = '';

    public function mount(Client $client): void
    {
        $this->client = $client;
        $this->notes = (string) $client->notes;
        $this->contractStart = (string) $client->contract_start?->toDateString();
        $this->contractEnd = (string) $client->contract_end?->toDateString();
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

    public function addTask(): void
    {
        $data = $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDue' => ['nullable', 'date'],
        ]);

        $this->client->tasks()->create(['title' => $data['taskTitle'], 'due_date' => $data['taskDue'] ?: null]);
        $this->reset('taskTitle', 'taskDue');
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

    // Agreement & documents

    public function saveAgreement(): void
    {
        $data = $this->validate([
            'contractStart' => ['nullable', 'date'],
            'contractEnd' => ['nullable', 'date', 'after_or_equal:contractStart'],
        ]);

        $this->client->update([
            'contract_start' => $data['contractStart'] ?: null,
            'contract_end' => $data['contractEnd'] ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Contract dates saved.'));
    }

    public function toggleSigned(): void
    {
        $this->client->update(['agreement_signed_at' => $this->client->agreement_signed_at ? null : now()]);
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,png,jpg,jpeg,webp'],
            'uploadType' => ['required', Rule::in(array_keys(Document::TYPES))],
        ]);

        $this->client->documents()->create([
            'type' => $this->uploadType,
            'name' => $this->upload->getClientOriginalName(),
            'path' => $this->upload->store("clients/{$this->client->id}", 'local'),
            'size' => $this->upload->getSize(),
        ]);

        $this->reset('upload');
        Flux::toast(variant: 'success', text: __('Document uploaded.'));
    }

    public function deleteDocument(int $id): void
    {
        $this->client->documents()->findOrFail($id)->delete();
        Flux::toast(text: __('Document deleted.'));
    }

    // Notes

    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $this->client->update(['notes' => $this->notes ?: null]);
        Flux::toast(variant: 'success', text: __('Notes saved.'));
    }
}; ?>

@php
    $c = $client;
    $tier = Client::PACKAGES[$c->package] ?? null;
    $stats = $this->stats;
    $cycles = ['monthly' => __('/mo'), 'yearly' => __('/yr'), 'one_time' => ' · '.__('one-time')];
    $badges = [
        'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300',
        'lead' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300',
        'inactive' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/15 dark:bg-white/5 dark:text-zinc-400',
    ];
    $dots = ['active' => 'bg-emerald-500', 'lead' => 'bg-amber-500', 'inactive' => 'bg-zinc-400'];
    $tierChips = [
        'basic' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
        'standard' => 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-600/20 dark:bg-brand-500/15 dark:text-brand-200',
        'premium' => 'bg-linear-to-r from-amber-300 via-yellow-200 to-amber-300 text-amber-950 shadow-sm shadow-amber-500/30 ring-1 ring-inset ring-amber-500/40',
        'enterprise' => 'bg-linear-to-r from-zinc-900 to-slate-700 text-cyan-100 shadow-sm shadow-zinc-900/30 ring-1 ring-inset ring-white/10 dark:from-white dark:to-slate-200 dark:text-zinc-900',
    ];
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
    $panel = 'rounded-lg bg-white ring-1 ring-zinc-200/80 dark:bg-zinc-950 dark:ring-white/10';
    $paidPct = $stats['billed'] > 0 ? (int) round($stats['received'] / $stats['billed'] * 100) : 0;
    $taskPct = $stats['tasksTotal'] > 0 ? (int) round($stats['tasksDone'] / $stats['tasksTotal'] * 100) : 0;
    $fileSize = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
@endphp

<div class="flex w-full flex-col gap-6">
    <a href="{{ route('clients.index') }}" wire:navigate class="inline-flex w-fit items-center gap-1.5 text-sm font-medium text-zinc-500 transition hover:text-brand-600 dark:text-zinc-400">
        <flux:icon.arrow-left variant="micro" class="size-4" />
        {{ __('Clients') }}
    </a>

    {{-- Header --}}
    <header class="{{ $panel }} flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div class="flex min-w-0 items-center gap-4">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-xl font-semibold text-brand-700 ring-1 ring-black/5 dark:bg-brand-500/20 dark:text-brand-200">
                {{ $c->initials() }}
            </span>
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-semibold tracking-[-0.02em] text-zinc-900 dark:text-white">{{ $c->name }}</h1>
                <p class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $c->company ?? __('Independent') }}</p>
                <div class="mt-2.5 flex flex-wrap items-center gap-2">
                    <span class="{{ $badges[$c->status] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium capitalize ring-1 ring-inset">
                        <span class="{{ $dots[$c->status] }} size-1.5 rounded-full"></span>
                        {{ __($c->status) }}
                    </span>
                    @if ($tier)
                        <span class="{{ $tierChips[$c->package] }} inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold">
                            <flux:icon :icon="$tier['icon']" variant="micro" class="size-3.5" />
                            {{ __($tier['label']) }}
                        </span>
                    @endif
                    <span class="text-xs text-zinc-400">{{ __('Client since :date', ['date' => $c->created_at->format('M Y')]) }}</span>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center">
                @foreach ($c->socialLinks() as $network => $link)
                    <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="{{ $socials[$network]['class'] }} flex size-9 items-center justify-center rounded-md text-zinc-400 transition" title="{{ $socials[$network]['label'] }}">
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
    </header>

    {{-- Summary --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="{{ $panel }} flex flex-col gap-3 p-5">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Package') }}</p>
            @if ($c->price !== null)
                <p class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $c->formattedPrice() }}<span class="text-sm font-normal text-zinc-400">{{ $cycles[$c->billing_cycle] ?? '' }}</span></p>
            @else
                <p class="text-2xl font-semibold text-zinc-300 dark:text-zinc-600">-</p>
            @endif
            <p class="mt-auto text-sm text-zinc-500">{{ $tier ? __(':tier package', ['tier' => __($tier['label'])]) : __('No package selected') }}</p>
        </div>

        <div class="{{ $panel }} flex flex-col gap-3 p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Payments received') }}</p>
                @if ($stats['overduePayments'])
                    <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $stats['overduePayments']) }}</span>
                @endif
            </div>
            <p class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ Client::money($stats['received']) }}</p>
            <div class="mt-auto">
                <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $paidPct }}%"></div></div>
                <p class="mt-2 text-sm text-zinc-500">{{ __(':outstanding outstanding of :billed', ['outstanding' => Client::money($stats['billed'] - $stats['received']), 'billed' => Client::money($stats['billed'])]) }}</p>
            </div>
        </div>

        <div class="{{ $panel }} flex flex-col gap-3 p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Tasks completed') }}</p>
                @if ($stats['tasksOverdue'])
                    <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-[0.6875rem] font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ trans_choice(':count overdue|:count overdue', $stats['tasksOverdue']) }}</span>
                @endif
            </div>
            <p class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $stats['tasksDone'] }}<span class="text-base font-normal text-zinc-400"> / {{ $stats['tasksTotal'] }}</span></p>
            <div class="mt-auto">
                <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-brand-500" style="width: {{ $taskPct }}%"></div></div>
                <p class="mt-2 text-sm text-zinc-500">{{ $stats['tasksTotal'] ? __(':pct% of the work is done', ['pct' => $taskPct]) : __('No tasks yet') }}</p>
            </div>
        </div>

        <div class="{{ $panel }} flex flex-col gap-3 p-5">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Agreement') }}</p>
            @if ($c->agreement_signed_at)
                <p class="flex items-center gap-2 text-2xl font-semibold tracking-tight text-emerald-600 dark:text-emerald-400">
                    <flux:icon.check-badge class="size-6" /> {{ __('Signed') }}
                </p>
            @else
                <p class="flex items-center gap-2 text-2xl font-semibold tracking-tight text-amber-600 dark:text-amber-400">
                    <flux:icon.clock class="size-6" /> {{ __('Not signed') }}
                </p>
            @endif
            <p class="mt-auto text-sm text-zinc-500">
                @if ($c->contract_end)
                    @if ($c->contract_end->isBefore(today()))
                        {{ __('Ended :date', ['date' => $c->contract_end->format('j M Y')]) }}
                    @else
                        {{ __('Runs until :date (:days days left)', ['date' => $c->contract_end->format('j M Y'), 'days' => (int) today()->diffInDays($c->contract_end)]) }}
                    @endif
                @else
                    {{ __('No contract dates set') }}
                @endif
            </p>
        </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-3">
        {{-- Left column --}}
        <div class="flex flex-col gap-6 xl:col-span-2">
            {{-- Tasks --}}
            <section class="{{ $panel }}">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Tasks') }}</h2>
                    <span class="text-sm text-zinc-500 tabular-nums">{{ __(':done of :total done', ['done' => $stats['tasksDone'], 'total' => $stats['tasksTotal']]) }}</span>
                </div>

                <form wire:submit="addTask" class="flex flex-col gap-2 border-b border-zinc-100 px-5 py-3 sm:flex-row dark:border-white/5">
                    <flux:input wire:model="taskTitle" :placeholder="__('Add a task, e.g. Design Facebook cover')" class="flex-1" :aria-label="__('Task title')" />
                    <flux:input wire:model="taskDue" type="date" class="sm:w-44" :aria-label="__('Due date')" />
                    <flux:button type="submit" variant="primary" icon="plus" data-test="add-task-button">{{ __('Add') }}</flux:button>
                </form>

                @forelse ($this->tasks as $task)
                    <div wire:key="task-{{ $task->id }}" class="group flex items-center gap-3 border-b border-zinc-100 px-5 py-3 last:border-0 dark:border-white/5">
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
                                'shrink-0 text-xs tabular-nums',
                                'font-medium text-rose-600 dark:text-rose-400' => $task->isOverdue(),
                                'text-zinc-400' => ! $task->isOverdue(),
                            ])>{{ $task->isOverdue() ? __('Overdue · :date', ['date' => $task->due_date->format('j M')]) : $task->due_date->format('j M') }}</span>
                        @endif
                        <button type="button" wire:click="deleteTask({{ $task->id }})" class="text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:text-rose-500 focus:opacity-100" aria-label="{{ __('Delete task') }}">
                            <flux:icon.trash variant="micro" class="size-4" />
                        </button>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('No tasks yet. Add the first piece of work for this client above.') }}</p>
                @endforelse
            </section>

            {{-- Payments --}}
            <section class="{{ $panel }}">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Payments') }}</h2>
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="newPayment" data-test="record-payment-button">{{ __('Record payment') }}</flux:button>
                </div>

                @if ($this->payments->isEmpty())
                    <p class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('No payments recorded yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-sm">
                            <thead>
                                <tr class="border-b border-zinc-100 text-xs text-zinc-500 dark:border-white/5">
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Due') }}</th>
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Reference') }}</th>
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Method') }}</th>
                                    <th class="px-5 py-2.5 text-end font-medium">{{ __('Amount') }}</th>
                                    <th class="px-5 py-2.5 text-start font-medium">{{ __('Status') }}</th>
                                    <th class="px-5 py-2.5"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach ($this->payments as $payment)
                                    @php($ps = $payment->status())
                                    <tr wire:key="payment-{{ $payment->id }}">
                                        <td class="px-5 py-3 text-zinc-600 tabular-nums dark:text-zinc-300">{{ $payment->due_date?->format('j M Y') ?? '-' }}</td>
                                        <td class="px-5 py-3 text-zinc-600 dark:text-zinc-300">{{ $payment->reference ?? '-' }}</td>
                                        <td class="px-5 py-3 text-zinc-600 dark:text-zinc-300">{{ $payment->method ?? '-' }}</td>
                                        <td class="px-5 py-3 text-end font-semibold text-zinc-900 tabular-nums dark:text-white">{{ Client::money($payment->amount) }}</td>
                                        <td class="px-5 py-3">
                                            <span class="{{ $payBadges[$ps] }} inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset">
                                                {{ ['paid' => __('Paid'), 'pending' => __('Pending'), 'overdue' => __('Overdue')][$ps] }}
                                            </span>
                                            @if ($payment->paid_at)
                                                <span class="ms-1 text-xs text-zinc-400">{{ $payment->paid_at->format('j M') }}</span>
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
            {{-- Agreement & documents --}}
            <section class="{{ $panel }}">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Agreement') }}</h2>
                    <flux:switch wire:click="toggleSigned" :checked="(bool) $c->agreement_signed_at" :label="__('Signed')" align="left" />
                </div>

                <form wire:submit="saveAgreement" class="grid gap-3 border-b border-zinc-100 px-5 py-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2 dark:border-white/5">
                    <flux:input wire:model="contractStart" type="date" :label="__('Contract start')" />
                    <flux:input wire:model="contractEnd" type="date" :label="__('Contract end')" />
                    <flux:button type="submit" size="sm" class="sm:col-span-2 xl:col-span-1 2xl:col-span-2">{{ __('Save dates') }}</flux:button>
                </form>

                <div class="flex flex-col gap-3 px-5 py-4">
                    <p class="text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Documents') }}</p>

                    @foreach ($this->documents as $doc)
                        <div wire:key="doc-{{ $doc->id }}" class="group flex items-center gap-3 rounded-md bg-zinc-50 px-3 py-2.5 ring-1 ring-zinc-200/70 dark:bg-white/[0.03] dark:ring-white/5">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-white text-brand-500 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10">
                                <flux:icon.document-text variant="mini" class="size-4.5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('documents.download', $doc) }}" class="block truncate text-sm font-medium text-zinc-800 hover:text-brand-600 dark:text-zinc-100">{{ $doc->name }}</a>
                                <p class="text-xs text-zinc-500">{{ __(Document::TYPES[$doc->type] ?? 'Other') }} · {{ $fileSize($doc->size) }} · {{ $doc->created_at->format('j M Y') }}</p>
                            </div>
                            <a href="{{ route('documents.download', $doc) }}" class="text-zinc-400 hover:text-brand-600" aria-label="{{ __('Download :name', ['name' => $doc->name]) }}">
                                <flux:icon.arrow-down-tray variant="micro" class="size-4" />
                            </a>
                            <button type="button" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="{{ __('Delete this document?') }}" class="text-zinc-300 hover:text-rose-500" aria-label="{{ __('Delete :name', ['name' => $doc->name]) }}">
                                <flux:icon.trash variant="micro" class="size-4" />
                            </button>
                        </div>
                    @endforeach

                    <form wire:submit="uploadDocument" class="flex flex-col gap-2">
                        <label class="flex cursor-pointer flex-col items-center gap-1 rounded-md border border-dashed border-zinc-300 px-4 py-5 text-center transition hover:border-brand-400 hover:bg-brand-50/40 dark:border-zinc-700 dark:hover:bg-brand-500/5">
                            <flux:icon.arrow-up-tray class="size-5 text-zinc-400" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                @if ($upload)
                                    {{ $upload->getClientOriginalName() }}
                                @else
                                    {{ __('Choose a file to upload') }}
                                @endif
                            </span>
                            <span class="text-xs text-zinc-400">{{ __('PDF, Word or image, up to 10 MB') }}</span>
                            <input type="file" wire:model="upload" class="sr-only" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp">
                        </label>
                        <div wire:loading wire:target="upload" class="text-xs text-zinc-500">{{ __('Uploading…') }}</div>
                        <flux:error name="upload" />
                        <div class="flex gap-2">
                            <flux:select wire:model="uploadType" class="flex-1" :aria-label="__('Document type')">
                                @foreach (Document::TYPES as $key => $label)
                                    <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button type="submit" variant="primary" :disabled="! $upload">{{ __('Upload') }}</flux:button>
                        </div>
                    </form>
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
                        __('Added') => $c->created_at->format('j M Y'),
                    ] as $label => $value)
                        <div class="flex items-center justify-between gap-4 px-5 py-3">
                            <dt class="text-zinc-500">{{ $label }}</dt>
                            <dd class="truncate text-end font-medium text-zinc-800 dark:text-zinc-100">{{ $value ?: '-' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            {{-- Notes --}}
            <section class="{{ $panel }}">
                <h2 class="border-b border-zinc-100 px-5 py-4 font-semibold text-zinc-900 dark:border-white/5 dark:text-white">{{ __('Notes') }}</h2>
                <form wire:submit="saveNotes" class="flex flex-col gap-3 p-5">
                    <flux:textarea wire:model="notes" rows="4" :placeholder="__('Internal notes about this client')" :aria-label="__('Notes')" />
                    <flux:button type="submit" size="sm" class="self-end">{{ __('Save notes') }}</flux:button>
                </form>
            </section>
        </div>
    </div>

    {{-- Record payment --}}
    <flux:modal name="payment-form" class="w-full max-w-md">
        <form wire:submit="savePayment" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Record payment') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add an invoice or a payment for :name.', ['name' => $c->name]) }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="payAmount" type="number" min="0" step="0.01" :label="__('Amount (:currency)', ['currency' => config('app.currency')])" required class="sm:col-span-2" />
                <flux:input wire:model="payDue" type="date" :label="__('Due date')" />
                <flux:select wire:model="payMethod" :label="__('Method')">
                    <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                    @foreach (Payment::METHODS as $method)
                        <flux:select.option :value="$method">{{ $method }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="payReference" :label="__('Reference')" :placeholder="__('e.g. INV-1042')" class="sm:col-span-2" />
                <flux:checkbox wire:model="payPaid" :label="__('Already received')" class="sm:col-span-2" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-payment-button">{{ __('Save payment') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
