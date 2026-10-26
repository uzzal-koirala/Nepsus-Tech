<?php

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Support\NepaliDate;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Employee logins: their own attendance, month by month, and leave requests. Admins mark attendance and approve leave. */
new #[Title('My attendance')] class extends Component {
    public int $calYear;

    public int $calMonth;

    // Leave request form
    public string $leaveFrom = '';

    public string $leaveTo = '';

    public string $leaveReason = '';

    public function mount(): void
    {
        $this->goToday();
    }

    public function shiftMonth(int $by): void
    {
        $index = $this->calYear * 12 + $this->calMonth - 1 + max(-1, min(1, $by));
        [$this->calYear, $this->calMonth] = [intdiv($index, 12), $index % 12 + 1];
    }

    public function goToday(): void
    {
        [$this->calYear, $this->calMonth] = NepaliDate::fromAd(today());
    }

    #[Computed]
    public function me()
    {
        return auth()->user()->employee;
    }

    #[Computed]
    public function leaveRequests()
    {
        return $this->me ? $this->me->leaveRequests()->latest('from_date')->latest('id')->limit(12)->get() : collect();
    }

    public function newLeave(?string $date = null): void
    {
        abort_unless($this->me, 403);
        $this->resetErrorBag();
        $this->leaveFrom = $date && ! Carbon::parse($date)->isHoliday() ? Carbon::parse($date)->toDateString() : today()->toDateString();
        $this->leaveTo = $this->leaveFrom;
        $this->leaveReason = '';
        Flux::modal('leave-request')->show();
    }

    public function requestLeave(): void
    {
        abort_unless($this->me, 403);
        $data = $this->validate([
            'leaveFrom' => ['required', 'date', 'after_or_equal:'.today()->subDays(30)->toDateString()],
            'leaveTo' => ['required', 'date', 'after_or_equal:leaveFrom', 'before_or_equal:'.Carbon::parse(strtotime($this->leaveFrom) ? $this->leaveFrom : today())->addDays(30)->toDateString()],
            'leaveReason' => ['required', 'string', 'min:3', 'max:500'],
        ], attributes: ['leaveFrom' => __('first day'), 'leaveTo' => __('last day'), 'leaveReason' => __('reason')]);

        $request = new LeaveRequest(['from_date' => $data['leaveFrom'], 'to_date' => $data['leaveTo']]);
        if (! $request->workingDays()) {
            $this->addError('leaveTo', __('Those days are all holidays. Pick a working day.'));

            return;
        }
        $overlap = $this->me->leaveRequests()->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $data['leaveTo'])->whereDate('to_date', '>=', $data['leaveFrom'])->exists();
        if ($overlap) {
            $this->addError('leaveFrom', __('You already asked for leave on some of these days.'));

            return;
        }

        $this->me->leaveRequests()->create(['from_date' => $data['leaveFrom'], 'to_date' => $data['leaveTo'], 'reason' => trim($data['leaveReason'])]);
        unset($this->leaveRequests);
        Flux::modal('leave-request')->close();
        Flux::toast(variant: 'success', text: __('Leave requested. An admin will look at it.'));
    }

    /** Takes back a request that has not been decided yet. */
    public function cancelLeave(int $id): void
    {
        abort_unless($this->me, 403);
        $this->me->leaveRequests()->where('status', 'pending')->findOrFail($id)->delete();
        unset($this->leaveRequests);
        Flux::toast(text: __('Leave request cancelled.'));
    }

    /** Weeks of the shown BS month (Sunday first), each day with its mark. */
    #[Computed]
    public function calendar(): array
    {
        $length = NepaliDate::monthLength($this->calYear, $this->calMonth);
        $first = NepaliDate::toAd($this->calYear, $this->calMonth, 1);
        $last = NepaliDate::toAd($this->calYear, $this->calMonth, $length);

        $marks = $this->me
            ? $this->me->attendances()->whereBetween('date', [$first->toDateString(), $last->toDateString()])->get()
                ->reject(fn ($a) => $a->date->isHoliday())->keyBy(fn ($a) => $a->date->toDateString())
                ->each(fn ($a) => $a->setRelation('employee', $this->me))
            : collect();

        $cells = array_fill(0, $first->dayOfWeek, null);
        for ($day = 1; $day <= $length; $day++) {
            $ad = $first->copy()->addDays($day - 1);
            $cells[] = ['day' => $day, 'ad' => $ad, 'mark' => $marks[$ad->toDateString()] ?? null];
        }
        $cells = array_pad($cells, (int) ceil(count($cells) / 7) * 7, null);

        $counts = collect(Attendance::STATUSES)->map(fn ($v, $k) => $marks->where('status', $k)->count())->all();
        $marked = array_sum($counts);

        return [
            'label' => NepaliDate::MONTHS[$this->calMonth - 1].' '.$this->calYear,
            'range' => $first->format('j M').' – '.$last->format('j M Y'),
            'weeks' => array_chunk($cells, 7),
            'counts' => $counts,
            'rate' => $marked ? (int) round(($counts['present'] + $counts['half_day'] * 0.5) / $marked * 100) : null,
            'marks' => $marks->sortByDesc(fn ($a) => $a->date)->values(),
            'late' => $marks->filter(fn ($a) => $a->minutesLate())->count(),
        ];
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $cal = $this->calendar;
    $isThisMonth = [$this->calYear, $this->calMonth] === array_slice(NepaliDate::fromAd(today()), 0, 2);
    $markTone = [
        'present' => ['cell' => 'bg-emerald-50/80 dark:bg-emerald-500/10', 'pill' => 'bg-emerald-500 text-white', 'tile' => 'bg-emerald-500 shadow-emerald-500/30', 'glow' => 'bg-emerald-100/70 dark:bg-emerald-500/10'],
        'half_day' => ['cell' => 'bg-amber-50/80 dark:bg-amber-500/10', 'pill' => 'bg-amber-400 text-amber-950', 'tile' => 'bg-amber-500 shadow-amber-500/30', 'glow' => 'bg-amber-100/70 dark:bg-amber-500/10'],
        'leave' => ['cell' => 'bg-sky-50/80 dark:bg-sky-500/10', 'pill' => 'bg-sky-500 text-white', 'tile' => 'bg-sky-500 shadow-sky-500/30', 'glow' => 'bg-sky-100/70 dark:bg-sky-500/10'],
        'absent' => ['cell' => 'bg-rose-50/80 dark:bg-rose-500/10', 'pill' => 'bg-rose-500 text-white', 'tile' => 'bg-rose-500 shadow-rose-500/30', 'glow' => 'bg-rose-100/70 dark:bg-rose-500/10'],
    ];
    $th = 'px-3 py-2.5 text-start text-[0.6875rem] font-semibold tracking-wide text-zinc-500 uppercase whitespace-nowrap';
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('My attendance') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Your days at work, month by month. Something wrong? Ask an admin to fix it.') }}</p>
            @if ($this->me?->workHours())
                <p class="mt-2 inline-flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-800 ring-1 ring-orange-200/70 dark:bg-orange-500/10 dark:text-orange-200 dark:ring-orange-500/20" data-test="my-hours">
                    <flux:icon.clock variant="micro" class="size-3.5" />{{ __('Your hours: :hours', ['hours' => $this->me->workHours()]) }}
                    <span class="text-orange-600/80 dark:text-orange-300/80">· {{ __('late from :time', ['time' => $this->me->lateFrom()]) }}</span>
                </p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
        @if ($this->me)
            <flux:button variant="primary" icon="paper-airplane" wire:click="newLeave" class="shadow-md shadow-brand-500/20" data-test="request-leave">{{ __('Request leave') }}</flux:button>
        @endif
        <div class="flex items-center rounded-lg bg-white ring-1 ring-zinc-200 dark:bg-zinc-950 dark:ring-white/10">
            <button type="button" wire:click="shiftMonth(-1)" class="flex size-9 items-center justify-center rounded-s-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Previous month') }}"><flux:icon.chevron-left variant="mini" class="size-4" /></button>
            <button type="button" wire:click="goToday" @class(['h-9 border-x border-zinc-200 px-3 text-sm font-medium dark:border-white/10', 'text-brand-600 dark:text-brand-300' => $isThisMonth, 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-white/5' => ! $isThisMonth])>{{ __('This month') }}</button>
            <button type="button" wire:click="shiftMonth(1)" class="flex size-9 items-center justify-center rounded-e-lg text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:hover:bg-white/5 dark:hover:text-white" aria-label="{{ __('Next month') }}"><flux:icon.chevron-right variant="mini" class="size-4" /></button>
        </div>
        </div>
    </header>

    @unless ($this->me)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">{{ __('Your login is not linked to an employee profile yet, so no attendance shows here. Ask an admin to link it.') }}</p>
    @endunless

    {{-- Month totals --}}
    <section class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach (Attendance::STATUSES as $key => $st)
            <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5">
                <div class="{{ $markTone[$key]['glow'] }} absolute -top-12 -right-12 size-32 rounded-full"></div>
                <div class="relative flex items-center gap-3">
                    <span class="{{ $markTone[$key]['tile'] }} flex size-9 items-center justify-center rounded-lg text-white shadow-md"><flux:icon :icon="$st['icon']" variant="mini" class="size-4.5" /></span>
                    <p class="flex-1 text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __($st['label']) }}</p>
                </div>
                <p class="relative text-[1.75rem] leading-none font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $cal['counts'][$key] }}</p>
                <p class="relative text-xs text-zinc-500">
                    @if ($loop->first)
                        {{ $cal['rate'] !== null ? __(':rate% attendance in :month', ['rate' => $cal['rate'], 'month' => $cal['label']]) : __('Nothing marked in :month', ['month' => $cal['label']]) }}
                        @if ($cal['late'])<span class="font-semibold text-orange-600 dark:text-orange-400">· {{ trans_choice(':count late|:count late', $cal['late']) }}</span>@endif
                    @else
                        {{ trans_choice(':count day in :month|:count days in :month', $cal['counts'][$key], ['month' => $cal['label']]) }}
                    @endif
                </p>
            </div>
        @endforeach
    </section>

    {{-- Leave requests --}}
    @php $requests = $this->leaveRequests; @endphp
    @if ($requests->isNotEmpty())
        <section class="{{ $panel }} overflow-hidden" data-test="leave-requests">
            <div class="flex items-center gap-3 px-5 py-4">
                <span class="flex size-9 items-center justify-center rounded-lg bg-sky-500 text-white shadow-md shadow-sky-500/25"><flux:icon.paper-airplane variant="mini" class="size-4.5" /></span>
                <div class="flex-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('My leave requests') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Approved leave shows on your calendar by itself.') }}</p>
                </div>
            </div>
            <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                @foreach ($requests as $r)
                    @php $st = LeaveRequest::STATUSES[$r->status] ?? LeaveRequest::STATUSES['pending']; $days = count($r->workingDays()); @endphp
                    <li wire:key="lr-{{ $r->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                        <span class="flex w-12 shrink-0 flex-col items-center overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-sky-100 dark:bg-zinc-900 dark:ring-sky-500/20">
                            <span class="w-full bg-sky-500 py-0.5 text-center text-[0.5625rem] font-bold tracking-wider text-white uppercase">{{ mb_substr(NepaliDate::MONTHS[NepaliDate::fromAd($r->from_date)[1] - 1], 0, 3) }}</span>
                            <span class="py-0.5 text-base font-bold text-zinc-900 tabular-nums dark:text-white">{{ NepaliDate::fromAd($r->from_date)[2] }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ NepaliDate::format($r->from_date) }}@if (! $r->to_date->isSameDay($r->from_date)) – {{ NepaliDate::format($r->to_date) }}@endif
                                <span class="ms-1 text-xs font-normal text-zinc-500">· {{ trans_choice(':count working day|:count working days', $days) }}</span>
                            </p>
                            <p class="truncate text-xs text-zinc-500">{{ $r->reason }}</p>
                            @if ($r->reply)
                                <p @class(['mt-1.5 flex items-start gap-1.5 rounded-lg px-2.5 py-1.5 text-xs', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200' => $r->status === 'approved', 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200' => $r->status !== 'approved'])>
                                    <flux:icon.chat-bubble-left-ellipsis variant="micro" class="mt-px size-3.5 shrink-0 opacity-70" /><span><span class="font-semibold">{{ __("Admin's reason:") }}</span> {{ $r->reply }}</span>
                                </p>
                            @endif
                        </div>
                        <span class="{{ $st['tone'] }} inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"><flux:icon :icon="$st['icon']" variant="micro" class="size-3.5" />{{ __($st['label']) }}</span>
                        @if ($r->status === 'pending')
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancelLeave({{ $r->id }})" wire:confirm="{{ __('Cancel this leave request?') }}" data-test="cancel-leave-{{ $r->id }}">{{ __('Cancel') }}</flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Calendar --}}
    <section class="{{ $panel }} min-w-0 overflow-hidden" data-test="attendance-calendar">
        <div class="flex items-center gap-3 px-5 py-4">
            <span class="flex size-9 items-center justify-center rounded-lg bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.calendar-days variant="mini" class="size-4.5" /></span>
            <div>
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ $cal['label'] }}</h2>
                <p class="text-xs text-zinc-500">{{ $cal['range'] }}</p>
            </div>
        </div>
        <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
            <div class="min-w-[42rem]">
                <div class="grid grid-cols-7 border-b border-zinc-100 dark:border-white/5">
                    @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $wd)
                        <div @class(['px-2 py-2 text-center text-[0.6875rem] font-semibold tracking-wider uppercase', 'text-rose-500' => $i === 6, 'text-zinc-400' => $i !== 6])>{{ __($wd) }}</div>
                    @endforeach
                </div>
                @foreach ($cal['weeks'] as $week)
                    <div class="grid grid-cols-7 border-b border-zinc-100 last:border-0 dark:border-white/5">
                        @foreach ($week as $cell)
                            @if ($cell === null)
                                <div class="min-h-24 border-e border-zinc-100 bg-zinc-50/50 last:border-e-0 dark:border-white/5 dark:bg-white/[0.01]"></div>
                            @else
                                @php
                                    $mark = $cell['mark'];
                                    $holiday = $cell['ad']->isHoliday();
                                    $isToday = $cell['ad']->isToday();
                                @endphp
                                <div wire:key="att-{{ $cell['ad']->toDateString() }}" @class([
                                    'flex min-h-24 flex-col gap-1.5 border-e border-zinc-100 p-2 last:border-e-0 dark:border-white/5',
                                    'bg-rose-50/40 dark:bg-rose-500/[0.04]' => $holiday,
                                    $markTone[$mark?->status]['cell'] ?? '' => $mark,
                                ])>
                                    <span class="flex items-center justify-between">
                                        <span @class([
                                            'flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                            'bg-brand-500 text-white shadow-sm shadow-brand-500/40' => $isToday,
                                            'text-rose-500' => ! $isToday && $holiday,
                                            'text-zinc-400' => ! $isToday && ! $holiday && $cell['ad']->isFuture(),
                                            'text-zinc-800 dark:text-zinc-100' => ! $isToday && ! $holiday && ! $cell['ad']->isFuture(),
                                        ])>{{ $cell['day'] }}</span>
                                        <span class="text-[0.625rem] text-zinc-300 tabular-nums dark:text-zinc-600">{{ $cell['ad']->format('j') }}</span>
                                    </span>
                                    @if ($holiday)
                                        <span class="inline-flex w-fit items-center gap-1 rounded-md bg-rose-100 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300"><flux:icon.sparkles variant="micro" class="size-3" />{{ __('Holiday') }}</span>
                                    @elseif ($mark)
                                        <span class="{{ $markTone[$mark->status]['pill'] }} inline-flex w-fit items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-semibold" @if ($mark->note) title="{{ $mark->note }}" @endif>
                                            <flux:icon :icon="Attendance::STATUSES[$mark->status]['icon']" variant="micro" class="size-3" />{{ __(Attendance::STATUSES[$mark->status]['label']) }}
                                        </span>
                                        @if ($mark->check_in)
                                            <span class="text-[0.6875rem] leading-tight font-medium text-zinc-600 tabular-nums dark:text-zinc-300">{{ Attendance::clock($mark->check_in) }}@if ($mark->check_out) – {{ Attendance::clock($mark->check_out) }}@endif</span>
                                            @if ($late = $mark->minutesLate())
                                                <span class="inline-flex w-fit items-center gap-1 rounded-md bg-orange-100 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300"><flux:icon.clock variant="micro" class="size-3" />{{ __('Late :time', ['time' => Attendance::duration($late)]) }}</span>
                                            @endif
                                        @endif
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Day by day --}}
    <section class="{{ $panel }} overflow-hidden" data-test="attendance-days">
        <div class="flex items-center gap-3 px-5 py-4">
            <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-500 text-white shadow-md shadow-emerald-500/25"><flux:icon.finger-print variant="mini" class="size-4.5" /></span>
            <div class="flex-1">
                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Day by day') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Every marked day in :month, latest first', ['month' => $cal['label']]) }}</p>
            </div>
        </div>
        @if ($cal['marks']->isEmpty())
            <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.calendar-days class="size-6" /></span>
                <p class="text-sm text-zinc-500">{{ __('No attendance marked this month yet.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                        <tr>
                            <th class="{{ $th }} ps-5">{{ __('Date') }}</th>
                            <th class="{{ $th }}">{{ __('Status') }}</th>
                            <th class="{{ $th }}">{{ __('Check in') }}</th>
                            <th class="{{ $th }}">{{ __('Check out') }}</th>
                            <th class="{{ $th }}">{{ __('Worked') }}</th>
                            <th class="{{ $th }} pe-5">{{ __('Note') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($cal['marks'] as $a)
                            <tr wire:key="row-{{ $a->id }}" class="transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <td class="px-3 py-2.5 ps-5 whitespace-nowrap">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ NepaliDate::format($a->date) }}</span>
                                    <span class="ms-1 text-xs text-zinc-400">{{ $a->date->translatedFormat('D') }}</span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="{{ $markTone[$a->status]['pill'] }} inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold"><flux:icon :icon="Attendance::STATUSES[$a->status]['icon']" variant="micro" class="size-3" />{{ __(Attendance::STATUSES[$a->status]['label']) }}</span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-zinc-700 tabular-nums dark:text-zinc-300">{{ Attendance::clock($a->check_in) ?? '—' }}@if ($late = $a->minutesLate())<span class="ms-1.5 inline-flex items-center rounded-md bg-orange-100 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">{{ __('Late :time', ['time' => Attendance::duration($late)]) }}</span>@endif</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-zinc-700 tabular-nums dark:text-zinc-300">{{ Attendance::clock($a->check_out) ?? '—' }}</td>
                                <td class="px-3 py-2.5 font-semibold whitespace-nowrap text-zinc-900 tabular-nums dark:text-white">{{ $a->worked() ?? '—' }}</td>
                                <td class="max-w-72 truncate px-3 py-2.5 pe-5 text-zinc-500">{{ $a->note ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Request leave --}}
    <flux:modal name="leave-request" class="w-full max-w-lg overflow-visible">
        <form wire:submit="requestLeave" class="flex flex-col gap-5">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sky-500 text-white shadow-md shadow-sky-500/30"><flux:icon.paper-airplane variant="mini" class="size-5" /></span>
                <div>
                    <flux:heading size="lg">{{ __('Request leave') }}</flux:heading>
                    <flux:text>{{ __('An admin approves it; then the days show as leave on your calendar. Holidays are skipped.') }}</flux:text>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-nepali-date wire:model.live="leaveFrom" :label="__('From')" data-test="leave-from" />
                <x-nepali-date wire:model.live="leaveTo" :label="__('To')" align="end" data-test="leave-to" />
            </div>
            @php
                $asked = strtotime($leaveFrom) && strtotime($leaveTo) && $leaveTo >= $leaveFrom && $leaveTo <= date('Y-m-d', strtotime($leaveFrom.' +30 days')) ? count((new LeaveRequest(['from_date' => $leaveFrom, 'to_date' => $leaveTo]))->workingDays()) : 0;
            @endphp
            @if ($asked)
                <p class="-mt-2 inline-flex w-fit items-center gap-1.5 rounded-lg bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-300"><flux:icon.calendar-days variant="micro" class="size-3.5" />{{ trans_choice(':count working day|:count working days', $asked) }}</p>
            @endif
            <flux:textarea wire:model="leaveReason" :label="__('Reason')" rows="3" :placeholder="__('e.g. Family function in Pokhara')" required data-test="leave-reason" />
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="paper-airplane" data-test="send-leave">{{ __('Send request') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
