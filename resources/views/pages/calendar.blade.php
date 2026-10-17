<?php

use App\Models\Attendance;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use App\Support\NepaliDate;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Calendar')] class extends Component {
    /** What each kind of entry looks like. Custom events are 'event'. */
    public const SOURCES = [
        'event' => ['label' => 'Our events', 'icon' => 'calendar-days', 'dot' => 'bg-brand-500', 'pill' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200', 'tile' => 'bg-brand-500'],
        'due' => ['label' => 'Payments due', 'icon' => 'clock', 'dot' => 'bg-amber-500', 'pill' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200', 'tile' => 'bg-amber-500'],
        'paid' => ['label' => 'Payments received', 'icon' => 'banknotes', 'dot' => 'bg-emerald-500', 'pill' => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200', 'tile' => 'bg-emerald-500'],
        'renewal' => ['label' => 'Renewals', 'icon' => 'arrow-path', 'dot' => 'bg-violet-500', 'pill' => 'bg-violet-50 text-violet-800 dark:bg-violet-500/15 dark:text-violet-200', 'tile' => 'bg-violet-500'],
        'contract' => ['label' => 'Contracts', 'icon' => 'document-check', 'dot' => 'bg-sky-500', 'pill' => 'bg-sky-50 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200', 'tile' => 'bg-sky-500'],
        'task' => ['label' => 'Task deadlines', 'icon' => 'check-circle', 'dot' => 'bg-pink-500', 'pill' => 'bg-pink-50 text-pink-800 dark:bg-pink-500/15 dark:text-pink-200', 'tile' => 'bg-pink-500'],
        'invoice' => ['label' => 'Invoices due', 'icon' => 'receipt-percent', 'dot' => 'bg-rose-500', 'pill' => 'bg-rose-50 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200', 'tile' => 'bg-rose-500'],
    ];

    /** BS month shown, e.g. "2083-07". */
    #[Url(except: '')]
    public string $month = '';

    /** Selected day (AD, Y-m-d). */
    public string $day = '';

    /** @var list<string> Sources switched off. */
    #[Url(except: [])]
    public array $off = [];

    public ?int $editingId = null;

    /** @var array<string, string> Add / edit event. */
    public array $event = [];

    public ?int $deletingId = null;

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = NepaliDate::period(today());
        }
        $this->day = today()->toDateString();
        $this->event = $this->blankEvent();
    }

    private function blankEvent(?string $date = null): array
    {
        return ['title' => '', 'kind' => 'meeting', 'date' => $date ?? today()->toDateString(), 'time' => '', 'client_id' => '', 'note' => ''];
    }

    /** @return array{0: int, 1: int} */
    private function ym(): array
    {
        return array_map('intval', explode('-', $this->month));
    }

    public function shift(int $by): void
    {
        [$y, $m] = $this->ym();
        $i = $y * 12 + $m - 1 + $by;
        $this->month = sprintf('%d-%02d', intdiv($i, 12), $i % 12 + 1);
        // Keep the selection inside the month shown.
        $this->day = $this->month === NepaliDate::period(today()) ? today()->toDateString() : $this->range()[0]->toDateString();
    }

    public function goToday(): void
    {
        $this->month = NepaliDate::period(today());
        $this->day = today()->toDateString();
    }

    public function pick(string $date): void
    {
        $d = Carbon::parse($date);
        $this->day = $d->toDateString();
        $this->month = NepaliDate::period($d);
    }

    public function toggle(string $source): void
    {
        $this->off = in_array($source, $this->off, true) ? array_values(array_diff($this->off, [$source])) : [...$this->off, $source];
    }

    /** First and last AD day of the BS month shown. */
    private function range(): array
    {
        [$y, $m] = $this->ym();

        return [NepaliDate::toAd($y, $m, 1), NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))];
    }

    /** Whole weeks (Sunday first) covering the month. */
    #[Computed]
    public function weeks(): array
    {
        [$first, $last] = $this->range();
        $start = $first->copy()->subDays($first->dayOfWeek);
        $end = $last->copy()->addDays(6 - $last->dayOfWeek);

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $days[] = $d->copy();
        }

        return array_chunk($days, 7);
    }

    /**
     * Everything that happens with clients between two days, plus our own events.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function entries(CarbonInterface $from, CarbonInterface $to, bool $filtered = true)
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $money = fn ($v) => Client::money(round((float) $v));
        $clientUrl = fn ($c) => $c ? route('clients.show', $c) : null;
        $out = collect();

        foreach (CalendarEvent::with('client')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get() as $e) {
            $out->push(['source' => 'event', 'date' => $e->date->toDateString(), 'time' => $e->time, 'title' => $e->title, 'sub' => collect([__(CalendarEvent::KINDS[$e->kind]['label'] ?? 'Event'), $e->client?->displayName()])->filter()->implode(' · '), 'note' => $e->note, 'icon' => CalendarEvent::KINDS[$e->kind]['icon'] ?? 'calendar-days', 'url' => $clientUrl($e->client), 'id' => $e->id]);
        }

        foreach (Payment::with('client')->whereNull('paid_at')->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->get() as $p) {
            $late = $p->due_date->lt(today());
            $out->push(['source' => 'due', 'date' => $p->due_date->toDateString(), 'title' => $p->client->displayName(), 'sub' => ($late ? __('Overdue') : __('Payment due')).' · '.$money($p->amount), 'url' => $clientUrl($p->client), 'late' => $late]);
        }

        foreach (Payment::with('client')->whereBetween('paid_at', [$from, $to])->get() as $p) {
            $out->push(['source' => 'paid', 'date' => $p->paid_at->toDateString(), 'title' => $p->client->displayName(), 'sub' => __('Paid').' '.$money($p->amount).($p->method ? ' · '.$p->method : ''), 'url' => $clientUrl($p->client)]);
        }

        foreach (Task::with('client')->whereNull('completed_at')->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->get() as $t) {
            $out->push(['source' => 'task', 'date' => $t->due_date->toDateString(), 'title' => $t->title, 'sub' => $t->client ? __('Due for :client', ['client' => $t->client->displayName()]) : __('Task due'), 'url' => $clientUrl($t->client), 'late' => $t->isOverdue()]);
        }

        foreach (Invoice::with('client')->where('type', 'invoice')->whereNotIn('status', ['paid'])->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->get() as $i) {
            $out->push(['source' => 'invoice', 'date' => $i->due_date->toDateString(), 'title' => $i->bill_company ?: $i->bill_name, 'sub' => $i->number.' · '.$money($i->totals()['total']), 'url' => route('invoices.show', $i), 'late' => $i->displayStatus() === 'overdue']);
        }

        foreach (Client::query()->get() as $c) {
            foreach (['contract_start' => __('Contract starts'), 'contract_end' => __('Contract ends')] as $field => $label) {
                if ($c->{$field} && $c->{$field}->between($from, $to)) {
                    $out->push(['source' => 'contract', 'date' => $c->{$field}->toDateString(), 'title' => $c->displayName(), 'sub' => $label, 'url' => $clientUrl($c)]);
                }
            }

            // Renewals fall on the start day plus whole billing periods (same rule as Client::periodEnd).
            $anchor = $c->contract_start ?? $c->created_at;
            if ($c->status !== 'active' || ! $c->billing_months || ! $anchor || ! NepaliDate::fromAd($anchor) || ! NepaliDate::fromAd($to)) {
                continue;
            }
            [$ay, $am] = NepaliDate::fromAd($anchor);
            [$fy, $fm] = NepaliDate::fromAd($from) ?? [$ay, $am];
            for ($k = max(1, intdiv(($fy * 12 + $fm) - ($ay * 12 + $am), $c->billing_months)); ; $k++) {
                $renewal = NepaliDate::addMonths($anchor, $k * $c->billing_months);
                if ($renewal->gt($to) || ($c->contract_end && $renewal->gt($c->contract_end))) {
                    break;
                }
                if ($renewal->gte($from)) {
                    $out->push(['source' => 'renewal', 'date' => $renewal->toDateString(), 'title' => $c->displayName(), 'sub' => __('Renews').($c->formattedPrice() ? ' · '.$c->formattedPrice() : ''), 'url' => $clientUrl($c)]);
                }
            }
        }

        return $out->reject(fn ($e) => $filtered && in_array($e['source'], $this->off, true))
            ->sortBy(fn ($e) => [$e['date'], $e['time'] ?? '99:99', array_search($e['source'], array_keys(self::SOURCES))])
            ->values();
    }

    /** Entries on the visible grid, grouped by day. */
    #[Computed]
    public function byDay(): array
    {
        $weeks = $this->weeks;

        return $this->entries($weeks[0][0], end($weeks)[6])->groupBy('date')->all();
    }

    /** Counts per source for the visible month (ignores the filters). */
    #[Computed]
    public function counts(): array
    {
        [$first, $last] = $this->range();
        return $this->entries($first, $last, false)->countBy('source')->all();
    }

    #[Computed]
    public function upcoming()
    {
        return $this->entries(today()->addDay(), today()->addDays(7))->take(8);
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    public function newEvent(?string $date = null): void
    {
        $this->editingId = null;
        $this->event = $this->blankEvent($date ?: $this->day);
        $this->resetErrorBag();
        Flux::modal('event-form')->show();
    }

    public function editEvent(int $id): void
    {
        $e = CalendarEvent::findOrFail($id);
        $this->editingId = $e->id;
        $this->event = ['title' => $e->title, 'kind' => $e->kind, 'date' => $e->date->toDateString(), 'time' => (string) $e->time, 'client_id' => (string) $e->client_id, 'note' => (string) $e->note];
        $this->resetErrorBag();
        Flux::modal('event-form')->show();
    }

    public function saveEvent(): void
    {
        $data = $this->validate([
            'event.title' => ['required', 'string', 'max:150'],
            'event.kind' => ['required', Rule::in(array_keys(CalendarEvent::KINDS))],
            'event.date' => ['required', 'date'],
            'event.time' => ['nullable', 'date_format:H:i'],
            'event.client_id' => ['nullable', 'exists:clients,id'],
            'event.note' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['event.title' => __('title'), 'event.date' => __('date'), 'event.time' => __('time')])['event'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        CalendarEvent::updateOrCreate(['id' => $this->editingId], $data);
        $this->pick($data['date']);
        Flux::modal('event-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Event updated.') : __('Event added.'));
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('event-delete')->show();
    }

    public function deleteEvent(): void
    {
        CalendarEvent::whereKey($this->deletingId)->delete();
        $this->deletingId = null;
        Flux::modal('event-delete')->close();
        Flux::toast(text: __('Event deleted.'));
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $sources = $this::SOURCES;
    [$y, $m] = array_map('intval', explode('-', $month));
    $byDay = $this->byDay;
    $selected = \Illuminate\Support\Carbon::parse($day);
    $dayEntries = collect($byDay[$day] ?? []);
    $weeks = $this->weeks;
    $adSpan = $weeks[0][0]->format('M').' – '.end($weeks)[6]->format('M Y');
    $weekdays = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $bsDay = fn ($d) => NepaliDate::fromAd($d)[2] ?? $d->day;
    $time = fn ($t) => Attendance::clock($t);
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Calendar') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Every client date in one place: payments, renewals, contracts, deadlines and your own events.') }}</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newEvent" class="shadow-lg shadow-brand-500/25" data-test="new-event">{{ __('Add event') }}</flux:button>
    </header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-2">
        @foreach ($sources as $key => $s)
            @php $on = ! in_array($key, $off, true); @endphp
            <button type="button" wire:click="toggle('{{ $key }}')" @class([
                'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition',
                'bg-white text-zinc-700 ring-zinc-200 shadow-xs hover:ring-zinc-300 dark:bg-zinc-950 dark:text-zinc-200 dark:ring-white/10' => $on,
                'bg-transparent text-zinc-400 ring-zinc-200/60 line-through dark:ring-white/5' => ! $on,
            ])>
                <span @class(['size-2 rounded-full', $s['dot'] => $on, 'bg-zinc-300 dark:bg-zinc-600' => ! $on])></span>
                {{ __($s['label']) }}
                <span class="rounded-full bg-zinc-100 px-1.5 text-[0.625rem] font-semibold text-zinc-500 tabular-nums dark:bg-white/10">{{ $this->counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- Month --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-white" data-test="month-title">{{ NepaliDate::MONTHS[$m - 1] }} {{ $y }}</h2>
                    <p class="text-xs text-zinc-500">{{ $adSpan }}</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <flux:button size="sm" wire:click="goToday">{{ __('Today') }}</flux:button>
                    <flux:button size="sm" icon="chevron-left" wire:click="shift(-1)" :aria-label="__('Previous month')" />
                    <flux:button size="sm" icon="chevron-right" wire:click="shift(1)" :aria-label="__('Next month')" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-[44rem]">
                    <div class="grid grid-cols-7 border-y border-zinc-100 bg-zinc-50/80 dark:border-white/5 dark:bg-white/[0.02]">
                        @foreach ($weekdays as $i => $w)
                            <p @class(['px-3 py-2 text-xs font-semibold tracking-wide uppercase', 'text-rose-500' => $i === 6, 'text-zinc-500' => $i !== 6])>{{ $w }}</p>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7">
                        @foreach ($weeks as $week)
                            @foreach ($week as $d)
                                @php
                                    $key = $d->toDateString();
                                    $items = collect($byDay[$key] ?? []);
                                    $inMonth = NepaliDate::period($d) === $month;
                                    $isToday = $d->isToday();
                                    $isSel = $key === $day;
                                @endphp
                                <div wire:key="d-{{ $key }}" wire:click="pick('{{ $key }}')" @class([
                                    'group relative flex min-h-28 cursor-pointer flex-col gap-1 border-e border-b border-zinc-100 p-1.5 transition dark:border-white/5',
                                    'bg-zinc-50/60 dark:bg-white/[0.015]' => ! $inMonth,
                                    'hover:bg-brand-50/40 dark:hover:bg-brand-500/5' => ! $isSel,
                                    'bg-brand-50/70 ring-2 ring-brand-500 ring-inset dark:bg-brand-500/10' => $isSel,
                                ])>
                                    <div class="flex items-center justify-between px-1">
                                        <span @class([
                                            'flex size-7 items-center justify-center rounded-full text-sm font-semibold tabular-nums',
                                            'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $isToday,
                                            'text-zinc-900 dark:text-white' => ! $isToday && $inMonth,
                                            'text-zinc-400' => ! $isToday && ! $inMonth,
                                        ])>{{ $bsDay($d) }}</span>
                                        <span class="text-[0.625rem] text-zinc-400 tabular-nums">{{ $d->day === 1 ? $d->format('j M') : $d->day }}</span>
                                    </div>
                                    @foreach ($items->take(3) as $e)
                                        <span class="{{ $sources[$e['source']]['pill'] }} flex items-center gap-1 truncate rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium">
                                            <span class="{{ ($e['late'] ?? false) ? 'bg-rose-500' : $sources[$e['source']]['dot'] }} size-1.5 shrink-0 rounded-full"></span>
                                            <span class="truncate">@if ($e['time'] ?? null)<span class="opacity-70">{{ $time($e['time']) }}</span> @endif{{ $e['title'] }}</span>
                                        </span>
                                    @endforeach
                                    @if ($items->count() > 3)
                                        <span class="px-1.5 text-[0.6875rem] font-medium text-zinc-500">+{{ $items->count() - 3 }} {{ __('more') }}</span>
                                    @endif
                                    <button type="button" wire:click.stop="newEvent('{{ $key }}')" class="absolute right-1.5 bottom-1.5 hidden size-6 items-center justify-center rounded-md bg-white text-zinc-500 shadow-sm ring-1 ring-zinc-200 group-hover:flex hover:text-brand-600 dark:bg-zinc-900 dark:ring-white/10" aria-label="{{ __('Add event on this day') }}"><flux:icon.plus variant="micro" class="size-3.5" /></button>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <aside class="flex flex-col gap-6">
            {{-- Selected day --}}
            <section class="{{ $panel }} overflow-hidden">
                <div class="relative overflow-hidden bg-linear-to-br from-brand-500 to-violet-600 px-5 py-4 text-white">
                    <div class="pointer-events-none absolute -top-8 -right-8 size-28 rounded-full bg-white/10"></div>
                    <p class="text-xs font-medium text-white/75">{{ $selected->isToday() ? __('Today') : $selected->format('l') }}</p>
                    <p class="text-2xl font-bold tracking-tight" data-test="day-title">{{ NepaliDate::format($selected) }}</p>
                    <p class="text-xs text-white/75">{{ $selected->format('l, j F Y') }}</p>
                </div>
                <div class="flex flex-col gap-2 p-4">
                    @forelse ($dayEntries as $e)
                        @php $s = $sources[$e['source']]; @endphp
                        <div class="group flex items-start gap-3 rounded-lg p-2 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <span class="{{ $s['tile'] }} flex size-9 shrink-0 items-center justify-center rounded-lg text-white shadow-sm"><flux:icon :icon="$e['icon'] ?? $s['icon']" variant="mini" class="size-4.5" /></span>
                            <div class="min-w-0 flex-1">
                                @if ($e['url'])
                                    <a href="{{ $e['url'] }}" wire:navigate class="block truncate text-sm font-semibold text-zinc-900 hover:text-brand-600 dark:text-white">{{ $e['title'] }}</a>
                                @else
                                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $e['title'] }}</p>
                                @endif
                                <p @class(['truncate text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $e['late'] ?? false, 'text-zinc-500' => ! ($e['late'] ?? false)])>
                                    @if ($e['time'] ?? null){{ $time($e['time']) }} · @endif{{ $e['sub'] }}
                                </p>
                                @if ($e['note'] ?? null)<p class="mt-1 line-clamp-2 text-xs text-zinc-600 dark:text-zinc-400">{{ $e['note'] }}</p>@endif
                            </div>
                            @isset($e['id'])
                                <div class="flex shrink-0 gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editEvent({{ $e['id'] }})" :aria-label="__('Edit')" />
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $e['id'] }})" :aria-label="__('Delete')" class="text-rose-600!" />
                                </div>
                            @endisset
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 py-6 text-center">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-white/5"><flux:icon.calendar class="size-6" /></span>
                            <p class="text-sm text-zinc-500">{{ __('Nothing on this day.') }}</p>
                        </div>
                    @endforelse
                    <flux:button icon="plus" size="sm" wire:click="newEvent('{{ $day }}')" class="mt-1 w-full">{{ __('Add event on this day') }}</flux:button>
                </div>
            </section>

            {{-- Next 7 days --}}
            <section class="{{ $panel }} p-5">
                <p class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white"><flux:icon.sparkles variant="mini" class="size-5 text-brand-500" />{{ __('Coming up') }}<span class="text-xs font-normal text-zinc-500">{{ __('next 7 days') }}</span></p>
                <div class="mt-3 flex flex-col divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse ($this->upcoming as $e)
                        <button type="button" wire:click="pick('{{ $e['date'] }}')" class="flex items-center gap-3 py-2.5 text-start">
                            <span class="flex w-11 shrink-0 flex-col items-center rounded-lg bg-zinc-50 py-1 dark:bg-white/5">
                                <span class="text-[0.625rem] font-semibold text-zinc-500 uppercase">{{ mb_substr(NepaliDate::MONTHS[(NepaliDate::fromAd(Carbon::parse($e['date']))[1] ?? 1) - 1], 0, 3) }}</span>
                                <span class="text-base leading-none font-bold text-zinc-900 dark:text-white">{{ $bsDay(Carbon::parse($e['date'])) }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $e['title'] }}</span>
                                <span class="flex items-center gap-1.5 truncate text-xs text-zinc-500"><span class="{{ $sources[$e['source']]['dot'] }} size-1.5 shrink-0 rounded-full"></span>{{ $e['sub'] }}</span>
                            </span>
                        </button>
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500">{{ __('A quiet week ahead.') }}</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    {{-- Add / edit event --}}
    <flux:modal name="event-form" class="w-full max-w-xl overflow-visible!">
        <form wire:submit="saveEvent" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.calendar-days variant="mini" class="size-5" /></span>
                <flux:heading size="lg">{{ $editingId ? __('Edit event') : __('Add event') }}</flux:heading>
            </div>
            @php
                $kindOptions = collect(CalendarEvent::KINDS)->map(fn ($k, $key) => ['value' => $key, 'label' => __($k['label']), 'hint' => __($k['hint']), 'icon' => $k['icon'], 'tile' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300'])->values();
                $clientOptions = collect([['value' => '', 'label' => __('No client'), 'hint' => __('An internal event'), 'icon' => 'building-office']])
                    ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
            @endphp
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="event.title" :label="__('Title')" :placeholder="__('e.g. Content shoot at the cafe')" required data-test="event-title" /></div>
                <x-rich-select model="event.kind" :label="__('Type')" :options="$kindOptions" />
                <x-rich-select model="event.client_id" :label="__('Client')" :options="$clientOptions" searchable />
                <x-nepali-date wire:model="event.date" :label="__('Date')" />
                <flux:input wire:model="event.time" type="time" :label="__('Time (empty = all day)')" />
                <div class="sm:col-span-2"><flux:textarea wire:model="event.note" :label="__('Note')" rows="2" :placeholder="__('Place, what to bring, who is going...')" /></div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-event">{{ __('Save event') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="event-delete" class="w-full max-w-sm">
        <div class="flex flex-col items-center gap-4 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.trash class="size-7" /></span>
            <div>
                <flux:heading size="lg">{{ __('Delete this event?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('It will be removed from the calendar.') }}</flux:text>
            </div>
            <div class="flex w-full gap-2">
                <flux:modal.close><flux:button variant="ghost" class="flex-1">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteEvent" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
