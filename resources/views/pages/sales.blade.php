<?php

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Lead;
use App\Support\NepaliDate;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales & Marketing')] class extends Component {
    public const TABS = ['overview', 'pipeline', 'leads', 'campaigns'];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    // Leads table filters
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $stageFilter = '';

    #[Url(except: '')]
    public string $sourceFilter = '';

    /** @var array<string, mixed> Add / edit lead. */
    public array $lead = [];
    public ?int $editingLeadId = null;

    /** @var array<string, mixed> Add / edit campaign. */
    public array $campaign = [];
    public ?int $editingCampaignId = null;
    public ?int $deletingCampaignId = null;

    // Marking a lead as lost asks why.
    public ?int $losingLeadId = null;
    public string $lostReason = '';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }

        // Opened from a lead page's Edit link.
        if ($id = (int) request()->query('edit')) {
            $this->editLead($id);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
    }

    // ---- Data -------------------------------------------------------------

    #[Computed]
    public function leads()
    {
        return Lead::with(['employee', 'campaign'])->orderBy('position')->latest('id')->get();
    }

    #[Computed]
    public function stats(): array
    {
        $leads = $this->leads;
        [$monthStart] = NepaliDate::monthRange(today());
        [$lastStart, $lastEnd] = NepaliDate::monthRange($monthStart->copy()->subDay());
        $open = $leads->filter->isOpen();
        $closed90 = $leads->whereIn('stage', ['won', 'lost'])->filter(fn ($l) => $l->closed_at?->gte(today()->subDays(90)));
        $won90 = $closed90->where('stage', 'won')->count();

        return [
            'pipeline' => (float) $open->sum('value'),
            'forecast' => (float) $open->sum(fn ($l) => (float) $l->value * $l->stageMeta()['chance'] / 100),
            'open' => $open->count(),
            'newThisMonth' => $leads->filter(fn ($l) => $l->created_at->gte($monthStart))->count(),
            'newLastMonth' => $leads->filter(fn ($l) => $l->created_at->between($lastStart, $lastEnd))->count(),
            'conversion' => $closed90->count() ? (int) round($won90 / $closed90->count() * 100) : null,
            'wonThisMonth' => (float) $leads->where('stage', 'won')->filter(fn ($l) => $l->closed_at?->gte($monthStart))->sum('value'),
            'wonCountThisMonth' => $leads->where('stage', 'won')->filter(fn ($l) => $l->closed_at?->gte($monthStart))->count(),
            'followUps' => $leads->filter->followUpDue()->count(),
        ];
    }

    #[Computed]
    public function campaigns()
    {
        return Campaign::withCount(['leads', 'leads as won_count' => fn ($q) => $q->where('stage', 'won')])
            ->withSum(['leads as won_value' => fn ($q) => $q->where('stage', 'won')], 'value')
            ->latest('starts_on')->get();
    }

    #[Computed]
    public function filteredLeads()
    {
        $q = mb_strtolower(trim($this->search));

        return $this->leads
            ->when($this->stageFilter !== '', fn ($c) => $c->where('stage', $this->stageFilter))
            ->when($this->sourceFilter !== '', fn ($c) => $c->where('source', $this->sourceFilter))
            ->when($q !== '', fn ($c) => $c->filter(fn ($l) => str_contains(mb_strtolower($l->name.' '.$l->company.' '.$l->phone.' '.$l->email), $q)))
            ->sortByDesc('id')->values();
    }

    // ---- Leads ------------------------------------------------------------

    public function newLead(string $stage = 'new'): void
    {
        $this->editingLeadId = null;
        $this->lead = ['name' => '', 'company' => '', 'phone' => '', 'email' => '', 'source' => 'facebook', 'campaign_id' => '', 'service_type' => 'social_media', 'value' => '', 'stage' => array_key_exists($stage, Lead::STAGES) ? $stage : 'new', 'employee_id' => '', 'next_follow_up' => today()->addDay()->toDateString(), 'notes' => ''];
        $this->resetErrorBag();
        Flux::modal('lead-form')->show();
    }

    public function editLead(int $id): void
    {
        $l = Lead::findOrFail($id);
        $this->editingLeadId = $l->id;
        $this->lead = collect(Lead::rules())->keys()->mapWithKeys(fn ($k) => [$k => $l->{$k} instanceof \Carbon\CarbonInterface ? $l->{$k}->toDateString() : (string) $l->{$k}])->all();
        $this->resetErrorBag();
        Flux::modal('lead-form')->show();
    }

    public function saveLead(): void
    {
        $rules = collect(Lead::rules())->mapWithKeys(fn ($r, $k) => ["lead.$k" => $r])->all();
        $data = $this->validate($rules, attributes: ['lead.name' => __('name'), 'lead.email' => __('email'), 'lead.value' => __('deal value')])['lead'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->editingLeadId) {
            $l = Lead::findOrFail($this->editingLeadId);
            $stage = $data['stage'];
            unset($data['stage']);
            $l->update($data);
            $l->moveTo($stage);
        } else {
            $l = Lead::create($data + ['closed_at' => in_array($data['stage'], ['won', 'lost'], true) ? now() : null]);
            $l->activities()->create(['user_id' => auth()->id(), 'type' => 'note', 'body' => __('Lead added from :source', ['source' => __($l->sourceMeta()['label'])])]);
        }

        unset($this->leads, $this->stats, $this->filteredLeads, $this->campaigns);
        Flux::modal('lead-form')->close();
        Flux::toast(variant: 'success', text: $this->editingLeadId ? __('Lead updated.') : __('Lead added.'));
    }

    /** Dropped on a pipeline column (or picked from the card menu). Lost asks for a reason first. */
    public function moveLead(int $id, string $stage): void
    {
        abort_unless(array_key_exists($stage, Lead::STAGES), 422);

        if ($stage === 'lost') {
            $this->losingLeadId = $id;
            $this->lostReason = '';
            Flux::modal('lead-lost')->show();

            return;
        }

        Lead::findOrFail($id)->moveTo($stage);
        unset($this->leads, $this->stats, $this->filteredLeads, $this->campaigns);

        if ($stage === 'won') {
            Flux::toast(variant: 'success', text: __('Deal won! Open the lead to turn it into a client.'));
        }
    }

    public function confirmLost(): void
    {
        $this->validate(['lostReason' => ['required', 'string', 'max:255']], attributes: ['lostReason' => __('reason')]);
        Lead::findOrFail($this->losingLeadId)->moveTo('lost', trim($this->lostReason));

        $this->reset('losingLeadId', 'lostReason');
        unset($this->leads, $this->stats, $this->filteredLeads, $this->campaigns);
        Flux::modal('lead-lost')->close();
        Flux::toast(text: __('Marked as lost.'));
    }

    // ---- Campaigns --------------------------------------------------------

    public function newCampaign(): void
    {
        $this->editingCampaignId = null;
        $this->campaign = ['name' => '', 'channel' => 'facebook_ads', 'budget' => '', 'spent' => '', 'starts_on' => today()->toDateString(), 'ends_on' => '', 'notes' => ''];
        $this->resetErrorBag();
        Flux::modal('campaign-form')->show();
    }

    public function editCampaign(int $id): void
    {
        $c = Campaign::findOrFail($id);
        $this->editingCampaignId = $c->id;
        $this->campaign = collect(Campaign::rules())->keys()->mapWithKeys(fn ($k) => [$k => $c->{$k} instanceof \Carbon\CarbonInterface ? $c->{$k}->toDateString() : (string) $c->{$k}])->all();
        $this->resetErrorBag();
        Flux::modal('campaign-form')->show();
    }

    public function saveCampaign(): void
    {
        $rules = collect(Campaign::rules())->mapWithKeys(fn ($r, $k) => ["campaign.$k" => $r])->all();
        $rules['campaign.ends_on'] = ['nullable', 'date', 'after_or_equal:campaign.starts_on'];
        $data = $this->validate($rules, attributes: ['campaign.name' => __('name'), 'campaign.starts_on' => __('start date'), 'campaign.ends_on' => __('end date')])['campaign'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['spent'] ??= 0;

        $this->editingCampaignId ? Campaign::findOrFail($this->editingCampaignId)->update($data) : Campaign::create($data);

        unset($this->campaigns);
        Flux::modal('campaign-form')->close();
        Flux::toast(variant: 'success', text: $this->editingCampaignId ? __('Campaign updated.') : __('Campaign added.'));
    }

    public function confirmDeleteCampaign(int $id): void
    {
        $this->deletingCampaignId = Campaign::findOrFail($id)->id;
        Flux::modal('campaign-delete')->show();
    }

    public function deleteCampaign(int $id): void
    {
        Campaign::findOrFail($id)->delete();
        $this->deletingCampaignId = null;
        unset($this->campaigns, $this->leads);
        Flux::modal('campaign-delete')->close();
        Flux::toast(text: __('Campaign removed. Its leads are kept.'));
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $money = fn ($v) => Client::money(round((float) $v));
    $short = fn ($v) => $v >= 100000 ? config('app.currency').' '.rtrim(rtrim(number_format($v / 100000, 1), '0'), '.').' L' : $money($v);
    $s = $this->stats;
    $leads = $this->leads;
    $stages = Lead::STAGES;
    $openStages = array_slice($stages, 0, 4, true);
    $hth = 'px-3 py-2.5 text-start text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-500 uppercase';
@endphp

<div class="stagger mx-auto flex w-full max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Sales & Marketing') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Find leads, follow up, win clients and see which marketing pays off.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button icon="megaphone" wire:click="newCampaign">{{ __('New campaign') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="newLead" class="shadow-lg shadow-brand-500/25" data-test="add-lead">{{ __('Add lead') }}</flux:button>
        </div>
    </header>

    {{-- Tabs --}}
    <nav class="flex w-fit flex-wrap gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10" aria-label="{{ __('Sales sections') }}">
        @foreach (['overview' => ['squares-2x2', __('Overview')], 'pipeline' => ['view-columns', __('Pipeline')], 'leads' => ['users', __('Leads')], 'campaigns' => ['megaphone', __('Campaigns')]] as $key => [$icon, $label])
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition',
                'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $tab === $key,
                'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => $tab !== $key,
            ]) aria-current="{{ $tab === $key ? 'page' : 'false' }}">
                <flux:icon :icon="$icon" variant="micro" class="size-4" />{{ $label }}
                @if ($key === 'leads' && $s['followUps'])
                    <span @class(['rounded-full px-1.5 text-[0.6875rem] font-bold tabular-nums', 'bg-white/25' => $tab === $key, 'bg-rose-500 text-white' => $tab !== $key])>{{ $s['followUps'] }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        {{-- KPIs --}}
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @php
                $growth = $s['newLastMonth'] ? (int) round(($s['newThisMonth'] - $s['newLastMonth']) / $s['newLastMonth'] * 100) : null;
                $kpis = [
                    ['label' => __('Open pipeline'), 'value' => $short($s['pipeline']), 'icon' => 'funnel', 'tile' => 'bg-brand-500 shadow-brand-500/30', 'glow' => 'bg-brand-100/70 dark:bg-brand-500/10', 'sub' => trans_choice(':count open deal|:count open deals', $s['open']).' · '.__('forecast :amount', ['amount' => $short($s['forecast'])])],
                    ['label' => __('New leads this month'), 'value' => $s['newThisMonth'], 'icon' => 'user-plus', 'tile' => 'bg-sky-500 shadow-sky-500/30', 'glow' => 'bg-sky-100/70 dark:bg-sky-500/10', 'sub' => $growth === null ? __('Nothing last month to compare') : __(':change% vs last month', ['change' => ($growth >= 0 ? '+' : '').$growth])],
                    ['label' => __('Won this month'), 'value' => $short($s['wonThisMonth']), 'icon' => 'trophy', 'tile' => 'bg-emerald-500 shadow-emerald-500/30', 'glow' => 'bg-emerald-100/70 dark:bg-emerald-500/10', 'sub' => trans_choice(':count deal closed|:count deals closed', $s['wonCountThisMonth'])],
                    ['label' => __('Win rate'), 'value' => $s['conversion'] === null ? '—' : $s['conversion'].'%', 'icon' => 'chart-pie', 'tile' => 'bg-violet-500 shadow-violet-500/30', 'glow' => 'bg-violet-100/70 dark:bg-violet-500/10', 'sub' => __('Won out of closed, last 90 days')],
                ];
            @endphp
            @foreach ($kpis as $k)
                <div class="{{ $panel }} relative flex flex-col gap-4 overflow-hidden p-5">
                    <div class="{{ $k['glow'] }} absolute -top-12 -right-12 size-32 rounded-full"></div>
                    <div class="relative flex items-center gap-3">
                        <span class="{{ $k['tile'] }} flex size-9 items-center justify-center rounded-lg text-white shadow-md"><flux:icon :icon="$k['icon']" variant="mini" class="size-4.5" /></span>
                        <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $k['label'] }}</p>
                    </div>
                    <p class="relative text-[1.75rem] leading-none font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $k['value'] }}</p>
                    <p class="relative text-xs text-zinc-500">{{ $k['sub'] }}</p>
                </div>
            @endforeach
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                {{-- Funnel --}}
                <section class="{{ $panel }} p-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Sales funnel') }}</h2>
                            <p class="text-sm text-zinc-500">{{ __('Every lead by stage, with its deal value') }}</p>
                        </div>
                        <button type="button" wire:click="setTab('pipeline')" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('Open pipeline') }} →</button>
                    </div>
                    @php $maxCount = max(1, ...array_map(fn ($k) => $leads->where('stage', $k)->count(), array_keys($stages))); @endphp
                    <div class="mt-5 flex flex-col gap-3">
                        @foreach ($stages as $key => $st)
                            @php $inStage = $leads->where('stage', $key); @endphp
                            <div class="flex items-center gap-3">
                                <span class="flex w-32 shrink-0 items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-200"><span class="{{ $st['dot'] }} size-2.5 rounded-full"></span>{{ __($st['label']) }}</span>
                                <div class="relative h-8 flex-1 overflow-hidden rounded-lg bg-zinc-50 dark:bg-white/[0.03]">
                                    <div class="{{ $st['dot'] }} h-full rounded-lg opacity-85 transition-[width] duration-700" style="width: {{ max(2, $inStage->count() / $maxCount * 100) }}%"></div>
                                    <span class="absolute inset-y-0 left-3 flex items-center text-xs font-bold text-white mix-blend-normal drop-shadow">{{ $inStage->count() }}</span>
                                </div>
                                <span class="w-28 shrink-0 text-end text-sm font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $short($inStage->sum('value')) }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Sources --}}
                <section class="{{ $panel }} p-5 sm:p-6">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Where leads come from') }}</h2>
                    <p class="text-sm text-zinc-500">{{ __('All leads by source, and how many became clients') }}</p>
                    @php
                        $bySource = collect(Lead::SOURCES)->map(fn ($meta, $key) => $meta + ['count' => $leads->where('source', $key)->count(), 'won' => $leads->where('source', $key)->where('stage', 'won')->count()])->filter(fn ($x) => $x['count'] > 0)->sortByDesc('count');
                        $topSource = max(1, (int) $bySource->max('count'));
                    @endphp
                    @if ($bySource->isEmpty())
                        <p class="py-8 text-center text-sm text-zinc-500">{{ __('Add leads to see which channels bring the most.') }}</p>
                    @else
                        <div class="mt-5 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                            @foreach ($bySource as $src)
                                <div class="flex items-center gap-3">
                                    <span class="{{ $src['color'] }} flex size-9 shrink-0 items-center justify-center rounded-lg text-white"><flux:icon :icon="$src['icon']" variant="micro" class="size-4" /></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex justify-between text-sm"><span class="font-medium text-zinc-800 dark:text-zinc-100">{{ __($src['label']) }}</span><span class="text-zinc-500 tabular-nums">{{ $src['count'] }} · <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ __(':won won', ['won' => $src['won']]) }}</span></span></div>
                                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="{{ $src['color'] }} h-full rounded-full" style="width: {{ $src['count'] / $topSource * 100 }}%"></div></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <aside class="flex min-w-0 flex-col gap-6">
                {{-- Follow-ups --}}
                <section class="{{ $panel }} overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                        <flux:icon.bell-alert variant="mini" class="size-4.5 text-amber-500" />
                        <h2 class="flex-1 font-semibold text-zinc-900 dark:text-white">{{ __('Follow up today') }}</h2>
                        @if ($s['followUps'])<span class="rounded-full bg-rose-500 px-2 py-0.5 text-xs font-bold text-white">{{ $s['followUps'] }}</span>@endif
                    </div>
                    @forelse ($leads->filter->followUpDue()->sortBy('next_follow_up')->take(6) as $l)
                        @php $late = (int) $l->next_follow_up->diffInDays(today()); @endphp
                        <a href="{{ route('sales.leads.show', $l) }}" wire:navigate class="flex items-center gap-3 border-b border-zinc-100 px-5 py-3 transition last:border-0 hover:bg-zinc-50 dark:border-white/5 dark:hover:bg-white/[0.03]">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ $l->initials() }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $l->displayName() }}</p>
                                <p @class(['text-xs', 'font-medium text-rose-600 dark:text-rose-400' => $late > 0, 'text-amber-600 dark:text-amber-400' => $late === 0])>{{ $late > 0 ? trans_choice(':count day late|:count days late', $late) : __('Due today') }} · {{ __($l->stageMeta()['label']) }}</p>
                            </div>
                            @if ($l->phone)
                                <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.phone variant="micro" class="size-4" /></span>
                            @endif
                        </a>
                    @empty
                        <div class="flex flex-col items-center gap-2 px-5 py-8 text-center">
                            <span class="flex size-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><flux:icon.check variant="mini" class="size-5" /></span>
                            <p class="text-sm text-zinc-500">{{ __('No follow-ups due. Nice work!') }}</p>
                        </div>
                    @endforelse
                </section>

                {{-- Recent wins --}}
                <section class="{{ $panel }} p-5">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Recent wins') }}</h2>
                    <div class="mt-3 flex flex-col gap-2">
                        @forelse ($leads->where('stage', 'won')->sortByDesc('closed_at')->take(4) as $l)
                            <a href="{{ route('sales.leads.show', $l) }}" wire:navigate class="flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <span class="flex size-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.trophy variant="mini" class="size-4.5" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $l->displayName() }}</p>
                                    <p class="text-xs text-zinc-500">{{ $l->closed_at?->toNepali() }}</p>
                                </div>
                                <span class="text-sm font-semibold text-emerald-600 tabular-nums dark:text-emerald-400">{{ $l->value ? $short($l->value) : '' }}</span>
                            </a>
                        @empty
                            <p class="py-4 text-center text-sm text-zinc-500">{{ __('No wins yet. They will show up here.') }}</p>
                        @endforelse
                    </div>
                </section>

                {{-- Campaigns snapshot --}}
                <section class="{{ $panel }} p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Campaigns running') }}</h2>
                        <button type="button" wire:click="setTab('campaigns')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('All') }} →</button>
                    </div>
                    <div class="mt-3 flex flex-col gap-2">
                        @forelse ($this->campaigns->filter(fn ($c) => $c->status() === 'running')->take(3) as $c)
                            <div class="flex items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                <span class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br {{ $c->channelMeta()['color'] }} text-white"><flux:icon :icon="$c->channelMeta()['icon']" variant="micro" class="size-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $c->name }}</p>
                                    <p class="text-xs text-zinc-500">{{ trans_choice(':count lead|:count leads', $c->leads_count) }} · {{ __(':amount spent', ['amount' => $short($c->spent)]) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="py-3 text-center text-sm text-zinc-500">{{ __('No campaigns running.') }}</p>
                        @endforelse
                    </div>
                </section>
            </aside>
        </div>

    @elseif ($tab === 'pipeline')
        {{-- Kanban: drag a card to another column --}}
        <section class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0" x-data="{ dragging: null, over: null }">
            <div class="grid min-w-[72rem] grid-cols-6 items-start gap-4">
                @foreach ($stages as $key => $st)
                    @php $col = $leads->where('stage', $key); @endphp
                    <div class="flex min-h-96 flex-col rounded-xl bg-zinc-100/70 p-2 ring-1 ring-transparent transition dark:bg-white/[0.03]"
                        :class="over === '{{ $key }}' && 'ring-brand-400 bg-brand-50/60 dark:bg-brand-500/10'"
                        @dragover.prevent="over = '{{ $key }}'" @dragleave="over = null"
                        @drop.prevent="over = null; if (dragging) $wire.moveLead(dragging, '{{ $key }}')"
                        data-test="column-{{ $key }}">
                        <div class="flex items-center gap-2 px-2 py-2">
                            <span class="{{ $st['dot'] }} size-2.5 rounded-full"></span>
                            <p class="flex-1 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __($st['label']) }}</p>
                            <span class="rounded-md bg-white px-1.5 py-0.5 text-xs font-semibold text-zinc-600 tabular-nums shadow-xs dark:bg-white/10 dark:text-zinc-300">{{ $col->count() }}</span>
                        </div>
                        <p class="px-2 pb-2 text-xs text-zinc-500 tabular-nums">{{ $short($col->sum('value')) }}</p>

                        <div class="flex flex-col gap-2">
                            @foreach ($col as $l)
                                <article wire:key="card-{{ $l->id }}" draggable="true" @dragstart="dragging = {{ $l->id }}" @dragend="dragging = null"
                                    class="group cursor-grab rounded-lg bg-white p-3 shadow-xs ring-1 ring-zinc-200/70 transition hover:shadow-md hover:ring-brand-200 active:cursor-grabbing dark:bg-zinc-900 dark:ring-white/10">
                                    <div class="flex items-start gap-2">
                                        <a href="{{ route('sales.leads.show', $l) }}" wire:navigate class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $l->displayName() }}</p>
                                            @if ($l->company)<p class="truncate text-xs text-zinc-500">{{ $l->name }}</p>@endif
                                        </a>
                                        <flux:dropdown align="end">
                                            <button type="button" class="rounded p-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Move') }}"><flux:icon.ellipsis-horizontal variant="micro" class="size-4" /></button>
                                            <flux:menu>
                                                <flux:menu.heading>{{ __('Move to') }}</flux:menu.heading>
                                                @foreach ($stages as $to => $tst)
                                                    @if ($to !== $key)
                                                        <flux:menu.item :icon="$tst['icon']" wire:click="moveLead({{ $l->id }}, '{{ $to }}')">{{ __($tst['label']) }}</flux:menu.item>
                                                    @endif
                                                @endforeach
                                                <flux:menu.separator />
                                                <flux:menu.item icon="pencil-square" wire:click="editLead({{ $l->id }})">{{ __('Edit') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                    <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                                        <span class="{{ $l->sourceMeta()['color'] }} inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[0.625rem] font-semibold text-white"><flux:icon :icon="$l->sourceMeta()['icon']" variant="micro" class="size-3" />{{ __($l->sourceMeta()['label']) }}</span>
                                        @if ($l->value)<span class="text-xs font-semibold text-zinc-800 tabular-nums dark:text-zinc-100">{{ $short($l->value) }}</span>@endif
                                    </div>
                                    <div class="mt-2.5 flex items-center justify-between gap-2 text-[0.6875rem]">
                                        @if ($l->next_follow_up && $l->isOpen())
                                            <span @class(['inline-flex items-center gap-1 font-medium', 'text-rose-600 dark:text-rose-400' => $l->followUpDue(), 'text-zinc-500' => ! $l->followUpDue()])><flux:icon.bell variant="micro" class="size-3" />{{ $l->next_follow_up->toNepali(false) }}</span>
                                        @elseif ($key === 'lost' && $l->lost_reason)
                                            <span class="truncate text-zinc-500" title="{{ $l->lost_reason }}">{{ $l->lost_reason }}</span>
                                        @else
                                            <span></span>
                                        @endif
                                        @if ($l->employee)
                                            <x-employee-avatar :employee="$l->employee" class="size-6 text-[0.6rem]" />
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                            @if ($key === 'new')
                                <button type="button" wire:click="newLead('new')" class="flex items-center justify-center gap-1.5 rounded-lg border-2 border-dashed border-zinc-200 py-3 text-xs font-medium text-zinc-500 transition hover:border-brand-300 hover:text-brand-600 dark:border-white/10"><flux:icon.plus variant="micro" class="size-4" />{{ __('Add lead') }}</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        <p class="flex items-center gap-2 text-xs text-zinc-500"><flux:icon.hand-raised variant="micro" class="size-4 text-zinc-400" />{{ __('Drag a card to another column, or use its ••• menu. Moving to Lost asks for a reason.') }}</p>

    @elseif ($tab === 'leads')
        <section class="{{ $panel }} overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 px-5 py-4">
                <div class="min-w-48 flex-1">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search name, company, phone or email')" size="sm" />
                </div>
                <flux:select wire:model.live="stageFilter" size="sm" class="w-40">
                    <flux:select.option value="">{{ __('All stages') }}</flux:select.option>
                    @foreach ($stages as $key => $st)<flux:select.option :value="$key">{{ __($st['label']) }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model.live="sourceFilter" size="sm" class="w-40">
                    <flux:select.option value="">{{ __('All sources') }}</flux:select.option>
                    @foreach (Lead::SOURCES as $key => $src)<flux:select.option :value="$key">{{ __($src['label']) }}</flux:select.option>@endforeach
                </flux:select>
            </div>

            @if ($this->filteredLeads->isEmpty())
                <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/15"><flux:icon.user-plus class="size-6" /></span>
                    <p class="text-sm text-zinc-500">{{ $leads->isEmpty() ? __('No leads yet. Add the first person who asked about your services.') : __('No leads match these filters.') }}</p>
                    @if ($leads->isEmpty())<flux:button size="sm" variant="primary" icon="plus" wire:click="newLead">{{ __('Add lead') }}</flux:button>@endif
                </div>
            @else
                <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                    <table class="w-full min-w-[60rem] text-sm">
                        <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                            <tr>
                                <th class="{{ $hth }} ps-5">{{ __('Lead') }}</th>
                                <th class="{{ $hth }}">{{ __('Stage') }}</th>
                                <th class="{{ $hth }}">{{ __('Source') }}</th>
                                <th class="{{ $hth }}">{{ __('Service') }}</th>
                                <th class="{{ $hth }}">{{ __('Follow up') }}</th>
                                <th class="{{ $hth }}">{{ __('Owner') }}</th>
                                <th class="{{ str_replace('text-start', 'text-end', $hth) }} pe-5">{{ __('Value') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach ($this->filteredLeads as $l)
                                <tr wire:key="lead-row-{{ $l->id }}" class="transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="px-3 py-2.5 ps-5">
                                        <a href="{{ route('sales.leads.show', $l) }}" wire:navigate class="flex items-center gap-3">
                                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ $l->initials() }}</span>
                                            <span class="min-w-0">
                                                <span class="block truncate font-semibold text-zinc-900 hover:text-brand-600 dark:text-white">{{ $l->displayName() }}</span>
                                                <span class="block truncate text-xs text-zinc-500">{{ collect([$l->company ? $l->name : null, $l->phone])->filter()->join(' · ') ?: '—' }}</span>
                                            </span>
                                        </a>
                                    </td>
                                    <td class="px-3 py-2.5"><span class="{{ $l->stageMeta()['tone'] }} inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold whitespace-nowrap ring-1 ring-inset"><flux:icon :icon="$l->stageMeta()['icon']" variant="micro" class="size-3.5" />{{ __($l->stageMeta()['label']) }}</span></td>
                                    <td class="px-3 py-2.5 whitespace-nowrap"><span class="inline-flex items-center gap-1.5 text-zinc-700 dark:text-zinc-300"><span class="{{ $l->sourceMeta()['color'] }} size-2 rounded-full"></span>{{ __($l->sourceMeta()['label']) }}</span></td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-zinc-600 dark:text-zinc-300">{{ $l->service_type ? __(Client::SERVICE_TYPES[$l->service_type]['label'] ?? '') : '—' }}</td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        @if ($l->next_follow_up && $l->isOpen())
                                            <span @class(['font-medium', 'text-rose-600 dark:text-rose-400' => $l->followUpDue(), 'text-zinc-700 dark:text-zinc-300' => ! $l->followUpDue()])>{{ $l->next_follow_up->toNepali() }}</span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        @if ($l->employee)
                                            <span class="flex items-center gap-2"><x-employee-avatar :employee="$l->employee" class="size-6 text-[0.6rem]" /><span class="truncate text-zinc-700 dark:text-zinc-300">{{ str($l->employee->name)->before(' ') }}</span></span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 pe-5 text-end font-semibold whitespace-nowrap text-zinc-900 tabular-nums dark:text-white">{{ $l->value ? $money($l->value) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

    @elseif ($tab === 'campaigns')
        @php
            $camps = $this->campaigns;
            $spent = (float) $camps->sum('spent');
            $campLeads = (int) $camps->sum('leads_count');
            $campWon = (float) $camps->sum('won_value');
        @endphp
        <section class="grid gap-4 sm:grid-cols-4">
            @foreach ([[__('Spent on marketing'), $short($spent), 'banknotes'], [__('Leads from campaigns'), $campLeads, 'user-plus'], [__('Cost per lead'), $campLeads ? $money($spent / $campLeads) : '—', 'calculator'], [__('Won from campaigns'), $short($campWon), 'trophy']] as [$label, $value, $icon])
                <div class="{{ $panel }} flex items-center gap-4 p-5">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                    <div class="min-w-0">
                        <p class="truncate text-sm text-zinc-500">{{ $label }}</p>
                        <p class="text-xl leading-tight font-bold text-zinc-900 tabular-nums dark:text-white">{{ $value }}</p>
                    </div>
                </div>
            @endforeach
        </section>

        @if ($camps->isEmpty())
            <section class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-14 text-center">
                <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.megaphone class="size-8" /></span>
                <div>
                    <p class="font-semibold text-zinc-900 dark:text-white">{{ __('No campaigns yet') }}</p>
                    <p class="mt-1 max-w-sm text-sm text-zinc-500">{{ __('Add your Facebook boosts, Google ads or events, then link leads to them to see what each one brings.') }}</p>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="newCampaign">{{ __('New campaign') }}</flux:button>
            </section>
        @else
            <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($camps as $c)
                    @php
                        $meta = $c->channelMeta();
                        $status = $c->status();
                        $used = $c->budget > 0 ? min(100, (int) round($c->spent / $c->budget * 100)) : null;
                        $roi = $c->spent > 0 ? round(((float) $c->won_value - (float) $c->spent) / (float) $c->spent * 100) : null;
                    @endphp
                    <article wire:key="camp-{{ $c->id }}" class="{{ $panel }} flex flex-col overflow-hidden">
                        <div class="relative bg-linear-to-br {{ $meta['color'] }} p-5 text-white">
                            <div class="absolute -top-8 -right-8 size-28 rounded-full bg-white/10"></div>
                            <div class="relative flex items-start justify-between gap-3">
                                <span class="flex size-10 items-center justify-center rounded-xl bg-white/20 ring-1 ring-white/30"><flux:icon :icon="$meta['icon']" variant="mini" class="size-5" /></span>
                                <span @class(['rounded-full px-2.5 py-1 text-[0.6875rem] font-bold tracking-wide uppercase', 'bg-white text-emerald-700' => $status === 'running', 'bg-white/25 text-white' => $status !== 'running'])>{{ ['running' => __('Running'), 'scheduled' => __('Scheduled'), 'ended' => __('Ended')][$status] }}</span>
                            </div>
                            <p class="relative mt-4 truncate text-lg font-semibold">{{ $c->name }}</p>
                            <p class="relative text-sm text-white/80">{{ __($meta['label']) }} · {{ $c->starts_on->toNepali(false) }}{{ $c->ends_on ? ' – '.$c->ends_on->toNepali(false) : '' }}</p>
                        </div>
                        <div class="flex flex-1 flex-col gap-4 p-5">
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg bg-zinc-50 py-2 dark:bg-white/[0.03]"><p class="text-lg font-bold text-zinc-900 tabular-nums dark:text-white">{{ $c->leads_count }}</p><p class="text-[0.6875rem] text-zinc-500">{{ __('Leads') }}</p></div>
                                <div class="rounded-lg bg-zinc-50 py-2 dark:bg-white/[0.03]"><p class="text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400">{{ $c->won_count }}</p><p class="text-[0.6875rem] text-zinc-500">{{ __('Won') }}</p></div>
                                <div class="rounded-lg bg-zinc-50 py-2 dark:bg-white/[0.03]"><p class="text-lg font-bold text-zinc-900 tabular-nums dark:text-white">{{ $c->leads_count ? $short($c->spent / $c->leads_count) : '—' }}</p><p class="text-[0.6875rem] text-zinc-500">{{ __('Per lead') }}</p></div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs"><span class="text-zinc-500">{{ __('Spent :spent', ['spent' => $money($c->spent)]) }}</span><span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $c->budget ? __('of :budget', ['budget' => $money($c->budget)]) : __('No budget set') }}</span></div>
                                @if ($used !== null)
                                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div @class(['h-full rounded-full', 'bg-rose-500' => $used >= 100, 'bg-amber-400' => $used >= 80 && $used < 100, 'bg-brand-500' => $used < 80]) style="width: {{ $used }}%"></div></div>
                                @endif
                            </div>
                            <div class="mt-auto flex items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-white/5">
                                <span @class(['text-xs font-semibold', 'text-emerald-600 dark:text-emerald-400' => $roi !== null && $roi >= 0, 'text-rose-600 dark:text-rose-400' => $roi !== null && $roi < 0, 'text-zinc-400' => $roi === null])>{{ $roi === null ? __('Return: —') : __('Return :roi%', ['roi' => ($roi >= 0 ? '+' : '').$roi]) }}</span>
                                <div class="flex gap-1">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editCampaign({{ $c->id }})" :aria-label="__('Edit')" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteCampaign({{ $c->id }})" :aria-label="__('Remove')" />
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>
        @endif
    @endif

    {{-- Add / edit lead --}}
    <flux:modal name="lead-form" class="w-full max-w-2xl">
        <form wire:submit="saveLead" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.user-plus variant="mini" class="size-5" /></span>
                <flux:heading size="lg">{{ $editingLeadId ? __('Edit lead') : __('Add lead') }}</flux:heading>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="lead.name" :label="__('Contact name')" :placeholder="__('e.g. Ram Sharma')" required data-test="lead-name" />
                <flux:input wire:model="lead.company" :label="__('Business')" :placeholder="__('e.g. Himalayan Cafe')" />
                <flux:input wire:model="lead.phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" />
                <flux:input wire:model="lead.email" type="email" :label="__('Email')" />
                <flux:select wire:model="lead.source" :label="__('Came from')">
                    @foreach (Lead::SOURCES as $key => $src)<flux:select.option :value="$key">{{ __($src['label']) }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="lead.campaign_id" :label="__('Campaign')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->campaigns as $c)<flux:select.option :value="$c->id">{{ $c->name }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="lead.service_type" :label="__('Interested in')">
                    <flux:select.option value="">{{ __('Not sure yet') }}</flux:select.option>
                    @foreach (Client::SERVICE_TYPES as $key => $svc)<flux:select.option :value="$key">{{ __($svc['label']) }}</flux:select.option>@endforeach
                </flux:select>
                <flux:input wire:model="lead.value" type="number" min="0" step="any" :label="__('Deal value (:currency)', ['currency' => config('app.currency')])" placeholder="25000" />
                <flux:select wire:model="lead.stage" :label="__('Stage')">
                    @foreach ($stages as $key => $st)<flux:select.option :value="$key">{{ __($st['label']) }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="lead.employee_id" :label="__('Handled by')">
                    <flux:select.option value="">{{ __('Nobody yet') }}</flux:select.option>
                    @foreach (Employee::where('status', '!=', 'inactive')->orderBy('name')->get(['id', 'name']) as $e)<flux:select.option :value="$e->id">{{ $e->name }}</flux:select.option>@endforeach
                </flux:select>
                <x-nepali-date wire:model="lead.next_follow_up" :label="__('Next follow-up')" />
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="lead.notes" :label="__('Notes')" rows="2" :placeholder="__('What they need, budget, timing...')" />
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-lead">{{ __('Save lead') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Lost: why? --}}
    <flux:modal name="lead-lost" class="w-full max-w-md">
        <form wire:submit="confirmLost" class="flex flex-col gap-5">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300"><flux:icon.x-circle variant="mini" class="size-5" /></span>
                <div>
                    <flux:heading size="lg">{{ __('Why was it lost?') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Knowing why helps you win the next one.') }}</flux:text>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ([__('Price too high'), __('Chose another agency'), __('No budget now'), __('No reply'), __('Not a good fit')] as $reason)
                    <button type="button" wire:click="$set('lostReason', '{{ $reason }}')" @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 transition', 'bg-brand-50 text-brand-700 ring-brand-300 dark:bg-brand-500/10 dark:text-brand-200' => $lostReason === $reason, 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10' => $lostReason !== $reason])>{{ $reason }}</button>
                @endforeach
            </div>
            <flux:input wire:model="lostReason" :label="__('Reason')" :placeholder="__('Or type your own')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" data-test="confirm-lost">{{ __('Mark as lost') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add / edit campaign --}}
    <flux:modal name="campaign-form" class="w-full max-w-xl">
        <form wire:submit="saveCampaign" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-violet-600 text-white"><flux:icon.megaphone variant="mini" class="size-5" /></span>
                <flux:heading size="lg">{{ $editingCampaignId ? __('Edit campaign') : __('New campaign') }}</flux:heading>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:input wire:model="campaign.name" :label="__('Name')" :placeholder="__('e.g. Dashain offer boost')" required data-test="campaign-name" />
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Channel') }}</p>
                    <div class="grid grid-cols-3 gap-2" role="radiogroup">
                        @foreach (Campaign::CHANNELS as $key => $ch)
                            <button type="button" role="radio" wire:click="$set('campaign.channel', '{{ $key }}')" aria-checked="{{ ($campaign['channel'] ?? '') === $key ? 'true' : 'false' }}" @class([
                                'flex items-center gap-2 rounded-xl px-2.5 py-2 text-start text-xs font-medium ring-1 transition',
                                'bg-brand-50 text-brand-700 ring-2 ring-brand-500 dark:bg-brand-500/10 dark:text-brand-200' => ($campaign['channel'] ?? '') === $key,
                                'text-zinc-700 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/10' => ($campaign['channel'] ?? '') !== $key,
                            ])>
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-linear-to-br {{ $ch['color'] }} text-white"><flux:icon :icon="$ch['icon']" variant="micro" class="size-3.5" /></span>
                                <span class="truncate">{{ __($ch['label']) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
                <flux:input wire:model="campaign.budget" type="number" min="0" step="any" :label="__('Budget (:currency)', ['currency' => config('app.currency')])" :placeholder="__('Optional')" />
                <flux:input wire:model="campaign.spent" type="number" min="0" step="any" :label="__('Spent so far')" placeholder="0" />
                <x-nepali-date wire:model="campaign.starts_on" :label="__('Starts')" />
                <x-nepali-date wire:model="campaign.ends_on" :label="__('Ends')" />
                <flux:error name="campaign.ends_on" class="sm:col-span-2" />
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="campaign.notes" :label="__('Notes')" rows="2" :placeholder="__('Audience, offer, creative...')" />
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-campaign">{{ __('Save campaign') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove campaign --}}
    <flux:modal name="campaign-delete" class="w-full max-w-sm">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Remove this campaign?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Its leads stay, they just will not be linked to a campaign anymore.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteCampaign({{ (int) $deletingCampaignId }})">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
