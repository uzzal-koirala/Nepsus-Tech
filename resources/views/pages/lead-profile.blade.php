<?php

use App\Models\Client;
use App\Models\Lead;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lead')] class extends Component {
    public Lead $lead;

    // Log an activity
    public string $activityType = 'call';
    public string $activityBody = '';
    public string $nextFollowUp = '';

    public string $lostReason = '';

    public function mount(): void
    {
        $this->nextFollowUp = (string) $this->lead->next_follow_up?->toDateString();
    }

    public function logActivity(): void
    {
        $data = $this->validate([
            'activityType' => ['required', Rule::in(array_keys(Lead::ACTIVITY_TYPES))],
            'activityBody' => ['required', 'string', 'max:2000'],
            'nextFollowUp' => ['nullable', 'date'],
        ], attributes: ['activityBody' => __('what happened'), 'nextFollowUp' => __('next follow-up')]);

        $this->lead->activities()->create(['user_id' => auth()->id(), 'type' => $data['activityType'], 'body' => trim($data['activityBody'])]);
        $this->lead->update(['next_follow_up' => $this->lead->isOpen() && $data['nextFollowUp'] ? $data['nextFollowUp'] : null]);

        // A first call or message moves a brand-new lead to Contacted.
        if ($this->lead->stage === 'new' && $data['activityType'] !== 'note') {
            $this->lead->moveTo('contacted');
        }

        $this->reset('activityBody');
        Flux::toast(variant: 'success', text: __('Activity logged.'));
    }

    public function moveTo(string $stage): void
    {
        abort_unless(array_key_exists($stage, Lead::STAGES), 422);

        if ($stage === 'lost') {
            $this->lostReason = '';
            Flux::modal('lead-lost')->show();

            return;
        }

        $this->lead->moveTo($stage);
        $this->nextFollowUp = (string) $this->lead->next_follow_up?->toDateString();
    }

    public function confirmLost(): void
    {
        $this->validate(['lostReason' => ['required', 'string', 'max:255']], attributes: ['lostReason' => __('reason')]);
        $this->lead->moveTo('lost', trim($this->lostReason));
        $this->nextFollowUp = '';
        Flux::modal('lead-lost')->close();
        Flux::toast(text: __('Marked as lost.'));
    }

    /** Creates the client from this lead (and marks it won). */
    public function convert(): void
    {
        abort_if($this->lead->client_id, 422);

        $client = Client::create([
            'name' => $this->lead->name,
            'company' => $this->lead->company,
            'phone' => $this->lead->phone,
            'email' => $this->lead->email,
            'status' => 'active',
            'service_type' => $this->lead->service_type ?: 'social_media',
            'price' => $this->lead->value,
            'notes' => $this->lead->notes,
            'position' => (int) Client::max('position') + 1,
        ]);

        $this->lead->moveTo('won');
        $this->lead->update(['client_id' => $client->id]);
        $this->lead->activities()->create(['user_id' => auth()->id(), 'type' => 'note', 'body' => __('Turned into a client')]);

        Flux::toast(variant: 'success', text: __(':name is now a client.', ['name' => $client->displayName()]));
        $this->redirectRoute('clients.show', $client, navigate: true);
    }

    public function delete(): void
    {
        $this->lead->delete();
        Flux::toast(text: __('Lead removed.'));
        $this->redirectRoute('sales.index', ['tab' => 'leads'], navigate: true);
    }
}; ?>

@php
    $l = $lead->fresh(['employee', 'client', 'activities.user']);
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($v) => Client::money(round((float) $v));
    $stageKeys = array_keys(Lead::STAGES);
    $current = array_search($l->stage, $stageKeys, true);
    $phone = preg_replace('/[^\d+]/', '', (string) $l->phone);
    $wa = $phone ? (str_starts_with($phone, '+') ? ltrim($phone, '+') : (strlen($phone) === 10 ? '977'.$phone : $phone)) : null;
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('sales.index', ['tab' => 'pipeline'])" wire:navigate>{{ __('Sales & Marketing') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $l->displayName() }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <section class="{{ $panel }} overflow-hidden">
        <div class="h-20 bg-linear-to-r from-brand-500 via-indigo-500 to-violet-500"></div>
        <div class="flex flex-col gap-5 px-6 pb-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex min-w-0 items-end gap-4">
                    <span class="-mt-10 flex size-20 shrink-0 items-center justify-center rounded-2xl bg-white text-2xl font-bold text-brand-600 shadow-lg ring-4 ring-white dark:bg-zinc-900 dark:ring-zinc-950">{{ $l->initials() }}</span>
                    <div class="min-w-0 pt-2">
                        <h1 class="truncate text-2xl font-semibold tracking-[-0.02em] text-zinc-900 dark:text-white">{{ $l->displayName() }}</h1>
                        <p class="text-sm text-zinc-500">{{ $l->company ? $l->name.' · ' : '' }}{{ __('Added :date', ['date' => $l->created_at->toNepali()]) }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($phone)
                        <flux:button icon="phone" :href="'tel:'.$phone">{{ __('Call') }}</flux:button>
                        <flux:button icon="chat-bubble-left-ellipsis" :href="'https://wa.me/'.$wa" target="_blank">WhatsApp</flux:button>
                    @endif
                    @if ($l->email)
                        <flux:button icon="envelope" :href="'mailto:'.$l->email">{{ __('Email') }}</flux:button>
                    @endif
                    @if ($l->client)
                        <flux:button variant="primary" icon="building-office-2" :href="route('clients.show', $l->client)" wire:navigate>{{ __('Open client') }}</flux:button>
                    @elseif ($l->stage !== 'lost')
                        <flux:button variant="primary" icon="sparkles" wire:click="convert" wire:confirm="{{ __('Create a client from this lead and mark the deal as won?') }}" class="shadow-lg shadow-brand-500/25" data-test="convert-lead">{{ __('Convert to client') }}</flux:button>
                    @endif
                    <flux:dropdown align="end">
                        <flux:button icon="ellipsis-horizontal" :aria-label="__('More')" />
                        <flux:menu>
                            <flux:menu.item icon="trash" variant="danger" x-on:click="$flux.modal('lead-delete').show()">{{ __('Remove lead') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            {{-- Stage stepper --}}
            <div class="grid grid-cols-3 gap-1.5 sm:grid-cols-6">
                @foreach (Lead::STAGES as $key => $st)
                    @php
                        $i = array_search($key, $stageKeys, true);
                        $isNow = $key === $l->stage;
                        $done = $l->stage !== 'lost' && $i < $current && $key !== 'lost';
                    @endphp
                    <button type="button" wire:click="moveTo('{{ $key }}')" @disabled($isNow) @class([
                        'flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold ring-1 transition',
                        $st['tone'].' ring-inset' => $isNow,
                        'bg-brand-50 text-brand-700 ring-brand-100 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20' => $done,
                        'text-zinc-500 ring-zinc-200 hover:bg-zinc-50 hover:text-zinc-800 dark:ring-white/10 dark:hover:bg-white/5' => ! $isNow && ! $done,
                    ]) data-test="stage-{{ $key }}">
                        <flux:icon :icon="$done ? 'check' : $st['icon']" variant="micro" class="size-3.5" />{{ __($st['label']) }}
                    </button>
                @endforeach
            </div>
            @if ($l->stage === 'lost' && $l->lost_reason)
                <p class="-mt-2 text-sm text-zinc-500">{{ __('Lost because: :reason', ['reason' => $l->lost_reason]) }}</p>
            @endif
        </div>
    </section>

    <div class="grid items-start gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        {{-- Details --}}
        <aside class="flex flex-col gap-6">
            <section class="{{ $panel }} overflow-hidden">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3.5 dark:border-white/5">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Deal') }}</h2>
                    <a href="{{ route('sales.index', ['tab' => 'pipeline', 'edit' => $l->id]) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-300"><flux:icon.pencil-square variant="micro" class="size-3.5" />{{ __('Edit') }}</a>
                </div>
                <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                    @foreach ([
                        ['banknotes', __('Value'), $l->value ? $money($l->value) : null],
                        ['funnel', __('Win chance'), $l->stageMeta()['chance'].'%'],
                        [$l->sourceMeta()['icon'], __('Came from'), __($l->sourceMeta()['label'])],
                        ['briefcase', __('Interested in'), $l->service_type ? __(Client::SERVICE_TYPES[$l->service_type]['label'] ?? '') : null],
                        ['phone', __('Phone'), $l->phone],
                        ['envelope', __('Email'), $l->email],
                    ] as [$icon, $label, $value])
                        <div class="flex items-center gap-3 px-5 py-3">
                            <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0 text-zinc-400" />
                            <dt class="w-28 shrink-0 text-zinc-500">{{ $label }}</dt>
                            <dd @class(['min-w-0 flex-1 truncate', 'font-medium text-zinc-800 dark:text-zinc-100' => $value, 'text-zinc-300 dark:text-zinc-600' => ! $value])>{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                    <div class="flex items-center gap-3 px-5 py-3">
                        <flux:icon.user-circle variant="micro" class="size-4 shrink-0 text-zinc-400" />
                        <dt class="w-28 shrink-0 text-zinc-500">{{ __('Handled by') }}</dt>
                        <dd class="min-w-0 flex-1">
                            @if ($l->employee)
                                <span class="flex items-center gap-2"><x-employee-avatar :employee="$l->employee" class="size-6 text-[0.6rem]" /><span class="truncate font-medium text-zinc-800 dark:text-zinc-100">{{ $l->employee->name }}</span></span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">—</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </section>

            @if ($l->notes)
                <section class="{{ $panel }} p-5">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Notes') }}</h2>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-zinc-600 dark:text-zinc-300">{{ $l->notes }}</p>
                </section>
            @endif
        </aside>

        {{-- Activity --}}
        <div class="flex min-w-0 flex-col gap-6">
            @if ($l->isOpen())
                <section @class([$panel, 'p-5', 'ring-2! ring-rose-300! dark:ring-rose-500/40!' => $l->followUpDue()])>
                    <form wire:submit="logActivity" class="flex flex-col gap-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Log what happened') }}</h2>
                            @if ($l->followUpDue())
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.bell-alert variant="micro" class="size-3.5" />{{ __('Follow-up due') }}</span>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (Lead::ACTIVITY_TYPES as $key => $type)
                                <button type="button" wire:click="$set('activityType', '{{ $key }}')" @class([
                                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition',
                                    'bg-brand-500 text-white ring-brand-500 shadow-sm shadow-brand-500/30' => $activityType === $key,
                                    'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:hover:bg-white/5' => $activityType !== $key,
                                ])><flux:icon :icon="$type['icon']" variant="micro" class="size-4" />{{ __($type['label']) }}</button>
                            @endforeach
                        </div>
                        <flux:textarea wire:model="activityBody" rows="2" :placeholder="__('e.g. Called, interested in the Growth package. Wants a quote by Friday.')" data-test="activity-body" />
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div class="w-full sm:w-64">
                                <x-nepali-date wire:model="nextFollowUp" :label="__('Next follow-up')" />
                            </div>
                            <flux:button type="submit" variant="primary" icon="check" data-test="log-activity">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                </section>
            @endif

            <section class="{{ $panel }} p-5">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Timeline') }}</h2>
                <ol class="relative mt-4 flex flex-col gap-5 before:absolute before:top-2 before:bottom-2 before:left-[1.0625rem] before:w-px before:bg-zinc-200 dark:before:bg-white/10">
                    @forelse ($l->activities as $a)
                        @php $meta = $a->typeMeta(); @endphp
                        <li wire:key="act-{{ $a->id }}" class="relative flex gap-4">
                            <span class="{{ $meta['tone'] }} relative flex size-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-zinc-950"><flux:icon :icon="$meta['icon']" variant="micro" class="size-4" /></span>
                            <div class="min-w-0 flex-1 pt-1">
                                <p class="text-xs text-zinc-500"><span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ __($meta['label']) }}</span> · {{ $a->created_at->toNepali() }} {{ $a->created_at->format('g:i A') }}@if ($a->user) · {{ $a->user->name }}@endif</p>
                                <p class="mt-1 text-sm leading-relaxed whitespace-pre-line text-zinc-800 dark:text-zinc-100">{{ $a->body }}</p>
                            </div>
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-zinc-500">{{ __('Nothing logged yet.') }}</li>
                    @endforelse
                </ol>
            </section>
        </div>
    </div>

    {{-- Lost: why? --}}
    <flux:modal name="lead-lost" class="w-full max-w-md">
        <form wire:submit="confirmLost" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ __('Why was it lost?') }}</flux:heading>
            <div class="flex flex-wrap gap-1.5">
                @foreach ([__('Price too high'), __('Chose another agency'), __('No budget now'), __('No reply'), __('Not a good fit')] as $reason)
                    <button type="button" wire:click="$set('lostReason', '{{ $reason }}')" @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 transition', 'bg-brand-50 text-brand-700 ring-brand-300 dark:bg-brand-500/10 dark:text-brand-200' => $lostReason === $reason, 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10' => $lostReason !== $reason])>{{ $reason }}</button>
                @endforeach
            </div>
            <flux:input wire:model="lostReason" :label="__('Reason')" :placeholder="__('Or type your own')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Mark as lost') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove --}}
    <flux:modal name="lead-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Remove :name?', ['name' => $l->displayName()]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('The lead and its timeline are deleted. A client made from it is kept.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
