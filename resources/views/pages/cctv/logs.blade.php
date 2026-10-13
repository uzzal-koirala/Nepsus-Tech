<?php

use App\Models\CctvAccessLog;
use App\Models\CctvCamera;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('CCTV access log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $camera = '';

    #[Url(except: '')]
    public string $user = '';

    public function mount(): void
    {
        Gate::authorize('manage-cctv');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return CctvAccessLog::with('user')
            ->when($this->camera !== '', fn ($q) => $q->where('camera_name', $this->camera))
            ->when($this->user !== '', fn ($q) => $q->where('user_id', $this->user))
            ->latest('id')
            ->paginate(25);
    }

    #[Computed]
    public function summary(): array
    {
        return [
            'today' => CctvAccessLog::where('created_at', '>=', today())->count(),
            'week' => CctvAccessLog::where('created_at', '>=', today()->subDays(6))->count(),
            'viewers' => CctvAccessLog::where('created_at', '>=', today()->subDays(6))->distinct('user_id')->count('user_id'),
        ];
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $hth = 'px-3 py-2.5 text-start text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-500 uppercase';
    $s = $this->summary;
@endphp

<div class="stagger mx-auto flex w-full max-w-7xl flex-col gap-6">
    @include('partials.cctv-tabs')

    <section class="grid gap-4 sm:grid-cols-3">
        @foreach ([[__('Views today'), $s['today'], 'eye'], [__('Views in 7 days'), $s['week'], 'calendar-days'], [__('People watching in 7 days'), $s['viewers'], 'user-group']] as [$label, $value, $icon])
            <div class="{{ $panel }} flex items-center gap-4 p-5">
                <span class="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                <div>
                    <p class="text-sm text-zinc-500">{{ $label }}</p>
                    <p class="text-2xl leading-tight font-bold text-zinc-900 tabular-nums dark:text-white">{{ $value }}</p>
                </div>
            </div>
        @endforeach
    </section>

    <section class="{{ $panel }} overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div>
                <h2 class="text-lg leading-tight font-semibold text-zinc-900 dark:text-white">{{ __('Access log') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Who opened which camera, and when') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:select wire:model.live="camera" size="sm" class="w-44">
                    <flux:select.option value="">{{ __('All cameras') }}</flux:select.option>
                    @foreach (CctvAccessLog::distinct()->orderBy('camera_name')->pluck('camera_name') as $name)
                        <flux:select.option :value="$name">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="user" size="sm" class="w-44">
                    <flux:select.option value="">{{ __('Everyone') }}</flux:select.option>
                    @foreach (User::whereIn('id', CctvAccessLog::distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']) as $u)
                        <flux:select.option :value="$u->id">{{ $u->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->logs->isEmpty())
            <div class="flex flex-col items-center gap-2 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                <span class="flex size-10 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-white/5"><flux:icon.clipboard-document-list variant="mini" class="size-5" /></span>
                <p class="text-sm text-zinc-500">{{ __('Nobody has opened a camera yet.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto border-t border-zinc-100 dark:border-white/5">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                        <tr>
                            <th class="{{ $hth }} ps-5">{{ __('When') }}</th>
                            <th class="{{ $hth }}">{{ __('Person') }}</th>
                            <th class="{{ $hth }}">{{ __('Camera') }}</th>
                            <th class="{{ $hth }}">{{ __('Device') }}</th>
                            <th class="{{ $hth }} pe-5">{{ __('IP address') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($this->logs as $log)
                            <tr wire:key="log-{{ $log->id }}" class="transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <td class="px-3 py-2.5 ps-5 whitespace-nowrap">
                                    <p class="font-semibold text-zinc-900 dark:text-white">{{ $log->created_at->toNepali() }}</p>
                                    <p class="text-xs text-zinc-500">{{ $log->created_at->format('g:i A') }} · {{ $log->created_at->diffForHumans() }}</p>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="flex items-center gap-2.5">
                                        <span class="flex size-8 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ $log->user?->initials() ?? '?' }}</span>
                                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $log->user?->name ?? __('Deleted user') }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5"><span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-white/10 dark:text-zinc-200"><flux:icon.video-camera variant="micro" class="size-3.5" />{{ $log->camera_name }}</span></td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-zinc-600 dark:text-zinc-300">{{ $log->device() }}</td>
                                <td class="px-3 py-2.5 pe-5 font-mono text-xs text-zinc-500">{{ $log->ip ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($this->logs->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-white/5">{{ $this->logs->links() }}</div>
            @endif
        @endif
    </section>
</div>
