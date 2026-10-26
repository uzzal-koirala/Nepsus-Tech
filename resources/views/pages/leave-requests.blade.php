<?php

use App\Models\LeaveRequest;
use App\Support\NepaliDate;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** Admins: leave employees asked for, on the Employees page. Every decision needs a reason; approving marks the days as leave. */
new class extends Component {
    public ?int $decidingId = null;

    public string $decision = 'approved';

    public string $reply = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
    }

    #[Computed]
    public function pending()
    {
        return LeaveRequest::with('employee')->where('status', 'pending')->orderBy('from_date')->get();
    }

    /** Decided in the last two weeks, so a mistake is easy to spot. */
    #[Computed]
    public function recent()
    {
        return LeaveRequest::with('employee')->where('status', '!=', 'pending')->where('decided_at', '>=', now()->subDays(14))->latest('decided_at')->limit(5)->get();
    }

    public function decide(int $id, string $decision): void
    {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        $this->decidingId = LeaveRequest::where('status', 'pending')->findOrFail($id)->id;
        $this->decision = $decision;
        $this->reply = '';
        $this->resetErrorBag();
        Flux::modal('leave-decision')->show();
    }

    public function saveDecision(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate(['reply' => ['required', 'string', 'min:3', 'max:255']], attributes: ['reply' => __('reason')]);
        $request = LeaveRequest::with('employee')->where('status', 'pending')->findOrFail($this->decidingId);

        $this->decision === 'approved'
            ? $request->approve(auth()->user(), trim($this->reply))
            : $request->reject(auth()->user(), trim($this->reply));

        unset($this->pending, $this->recent);
        $this->reset('decidingId', 'reply');
        Flux::modal('leave-decision')->close();
        Flux::toast(variant: 'success', text: $request->status === 'approved'
            ? __('Leave approved for :name.', ['name' => $request->employee->name])
            : __('Leave not approved for :name.', ['name' => $request->employee->name]));
    }
}; ?>

@php
    $pending = $this->pending;
    $recent = $this->recent;
    $range = fn ($r) => NepaliDate::format($r->from_date).($r->to_date->isSameDay($r->from_date) ? '' : ' – '.NepaliDate::format($r->to_date));
@endphp

<div>
    @if ($pending->isNotEmpty() || $recent->isNotEmpty())
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10" data-test="leave-requests-admin">
            <div class="flex items-center gap-3 px-5 py-4">
                <span class="relative flex size-9 items-center justify-center rounded-lg bg-sky-500 text-white shadow-md shadow-sky-500/25">
                    <flux:icon.paper-airplane variant="mini" class="size-4.5" />
                    @if ($pending->count())<span class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-rose-500 text-[0.625rem] font-bold ring-2 ring-white dark:ring-zinc-950">{{ $pending->count() }}</span>@endif
                </span>
                <div class="flex-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Leave requests') }}</h2>
                    <p class="text-xs text-zinc-500">{{ $pending->count() ? trans_choice(':count request waiting for you|:count requests waiting for you', $pending->count()) : __('Nothing waiting') }}</p>
                </div>
            </div>

            <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                @foreach ($pending as $r)
                    <li wire:key="pending-{{ $r->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-3.5">
                        <a href="{{ route('employees.show', $r->employee_id) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                            <x-employee-avatar :employee="$r->employee" class="size-10 text-xs" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $r->employee->name }}</span>
                                <span class="block text-xs text-zinc-500">{{ $range($r) }} · {{ trans_choice(':count working day|:count working days', count($r->workingDays())) }}</span>
                                <span class="mt-0.5 block truncate text-xs text-zinc-600 dark:text-zinc-300">“{{ $r->reason }}”</span>
                            </span>
                        </a>
                        <div class="flex shrink-0 gap-2">
                            <flux:button size="sm" variant="ghost" icon="x-mark" class="text-rose-600!" wire:click="decide({{ $r->id }}, 'rejected')" data-test="reject-{{ $r->id }}">{{ __('Reject') }}</flux:button>
                            <flux:button size="sm" variant="primary" icon="check" wire:click="decide({{ $r->id }}, 'approved')" data-test="approve-{{ $r->id }}">{{ __('Approve') }}</flux:button>
                        </div>
                    </li>
                @endforeach
                @foreach ($recent as $r)
                    @php $st = LeaveRequest::STATUSES[$r->status]; @endphp
                    <li wire:key="recent-{{ $r->id }}" class="flex items-center gap-3 bg-zinc-50/50 px-5 py-2.5 dark:bg-white/[0.01]">
                        <x-employee-avatar :employee="$r->employee" class="size-7 text-[0.625rem]" />
                        <p class="min-w-0 flex-1 truncate text-xs text-zinc-500"><span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $r->employee->name }}</span> · {{ $range($r) }}@if ($r->reply) · <span class="italic">{{ $r->reply }}</span>@endif</p>
                        <span class="{{ $st['tone'] }} inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-inset"><flux:icon :icon="$st['icon']" variant="micro" class="size-3" />{{ __($st['label']) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <flux:modal name="leave-decision" class="w-full max-w-md">
        @if ($decidingId && ($r = $pending->firstWhere('id', $decidingId)))
            <form wire:submit="saveDecision" class="flex flex-col gap-5">
                <div class="flex items-start gap-3">
                    <span @class(['flex size-10 shrink-0 items-center justify-center rounded-xl text-white shadow-md', 'bg-emerald-500 shadow-emerald-500/30' => $decision === 'approved', 'bg-rose-500 shadow-rose-500/30' => $decision !== 'approved'])>
                        <flux:icon :icon="$decision === 'approved' ? 'check' : 'x-mark'" variant="mini" class="size-5" />
                    </span>
                    <div>
                        <flux:heading size="lg">{{ $decision === 'approved' ? __('Approve leave') : __('Reject leave') }}</flux:heading>
                        <flux:text>{{ $r->employee->name }} · {{ $range($r) }}</flux:text>
                    </div>
                </div>
                <p class="rounded-xl bg-zinc-50 px-4 py-3 text-sm text-zinc-700 dark:bg-white/5 dark:text-zinc-200">“{{ $r->reason }}”</p>
                @if ($decision === 'approved')
                    <p class="text-sm text-zinc-500">{{ trans_choice('Their :count working day will be marked as leave.|Their :count working days will be marked as leave.', count($r->workingDays())) }}</p>
                @endif
                <flux:textarea wire:model="reply" rows="3" :label="$decision === 'approved' ? __('Reason for approving') : __('Reason for not approving')" :description="__('The employee sees this with their request.')" :placeholder="$decision === 'approved' ? __('e.g. Approved, no shoots planned that week. Enjoy!') : __('e.g. We have the Momo House shoot that day. Can you pick another date?')" required data-test="decision-reason" />
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" :variant="$decision === 'approved' ? 'primary' : 'danger'" data-test="save-decision">{{ $decision === 'approved' ? __('Approve') : __('Reject') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
