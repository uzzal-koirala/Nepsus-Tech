<?php

use App\Support\Ezviz;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cameras')] class extends Component {
    /** Camera being watched: ['serial', 'channel', 'name']. */
    public ?array $watching = null;
    public int $quality = 2; // 1 = HD, 2 = smooth
    public ?string $liveUrl = null;
    public ?string $liveError = null;
    public ?string $snapshotUrl = null;

    public ?string $error = null;

    #[Computed]
    public function connected(): bool
    {
        return Ezviz::fromSettings() !== null;
    }

    #[Computed]
    public function cameras(): array
    {
        if (! $ezviz = Ezviz::fromSettings()) {
            return [];
        }

        try {
            return Cache::remember('ezviz.cameras', now()->addMinute(), fn () => $ezviz->cameras());
        } catch (\Throwable $e) {
            $this->error = $e instanceof \RuntimeException && $e->getCode() ? $e->getMessage() : __('Could not reach EZVIZ right now.');

            return [];
        }
    }

    public function refresh(): void
    {
        Cache::forget('ezviz.cameras');
        $this->error = null;
        unset($this->cameras);
    }

    public function watch(string $serial, int $channel): void
    {
        $camera = collect($this->cameras)->first(fn ($c) => $c['serial'] === $serial && $c['channel'] === $channel);
        abort_unless($camera, 404);

        $this->watching = ['serial' => $serial, 'channel' => $channel, 'name' => $camera['name'], 'online' => $camera['online']];
        $this->snapshotUrl = null;
        $this->loadStream();
        Flux::modal('camera-live')->show();
    }

    public function setQuality(int $quality): void
    {
        $this->quality = $quality === 1 ? 1 : 2;
        $this->loadStream();
    }

    public function snapshot(): void
    {
        if (! $this->watching) {
            return;
        }

        try {
            $this->snapshotUrl = Ezviz::fromSettings()?->snapshot($this->watching['serial'], $this->watching['channel']);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e instanceof \RuntimeException && $e->getCode() ? $e->getMessage() : __('Could not take a snapshot.'));
        }
    }

    public function stopWatching(): void
    {
        $this->reset('watching', 'liveUrl', 'liveError', 'snapshotUrl');
    }

    private function loadStream(): void
    {
        [$this->liveUrl, $this->liveError] = [null, null];

        try {
            $this->liveUrl = Ezviz::fromSettings()?->liveUrl($this->watching['serial'], $this->watching['channel'], $this->quality);
        } catch (\Throwable $e) {
            $this->liveError = $e instanceof \RuntimeException && $e->getCode() ? $e->getMessage() : __('Could not start the live view.');
        }
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $cameras = $this->cameras;
    $online = collect($cameras)->where('online', true)->count();
@endphp

<div class="stagger mx-auto flex w-full max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Cameras') }}</h1>
            <p class="mt-1 flex items-center gap-2 text-sm text-zinc-500">
                @if ($this->connected && ! $this->error)
                    <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span></span>
                    {{ trans_choice(':count camera|:count cameras', count($cameras)) }} · {{ __(':online online', ['online' => $online]) }}
                @else
                    {{ __('Your CCTV cameras, live.') }}
                @endif
            </p>
        </div>
        @if ($this->connected)
            <div class="flex gap-2">
                <flux:button icon="arrow-path" wire:click="refresh" wire:target="refresh">{{ __('Refresh') }}</flux:button>
                <flux:button icon="cog-6-tooth" :href="route('settings.cameras')" wire:navigate>{{ __('Settings') }}</flux:button>
            </div>
        @endif
    </header>

    @if (! $this->connected)
        {{-- Not connected yet --}}
        <section class="{{ $panel }} relative overflow-hidden">
            <div class="absolute inset-0 bg-linear-to-br from-brand-50 via-white to-sky-50 dark:from-brand-500/10 dark:via-zinc-950 dark:to-sky-500/5"></div>
            <div class="relative flex flex-col items-center gap-5 px-6 py-16 text-center">
                <div class="relative">
                    <span class="flex size-20 items-center justify-center rounded-3xl bg-linear-to-br from-brand-500 to-brand-600 text-white shadow-xl shadow-brand-500/30"><flux:icon.video-camera class="size-10" /></span>
                    <span class="absolute -top-1 -right-1 flex size-6 items-center justify-center rounded-full bg-white text-rose-500 shadow ring-2 ring-white dark:bg-zinc-900 dark:ring-zinc-900"><span class="size-2.5 rounded-full bg-rose-500"></span></span>
                </div>
                <div>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Connect your EZVIZ cameras') }}</p>
                    <p class="mt-1 max-w-md text-sm text-zinc-500">{{ __('Add your EZVIZ AppKey and Secret once, and every camera on your EZVIZ account shows up here with live view and snapshots.') }}</p>
                </div>
                <flux:button variant="primary" icon="key" :href="route('settings.cameras')" wire:navigate data-test="connect-ezviz">{{ __('Connect EZVIZ') }}</flux:button>
            </div>
        </section>
    @elseif ($this->error)
        <section class="{{ $panel }} flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="flex size-12 items-center justify-center rounded-full bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.exclamation-triangle class="size-6" /></span>
            <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Cameras could not be loaded') }}</p>
            <p class="max-w-md text-sm text-zinc-500" data-test="camera-error">{{ $this->error }}</p>
            <div class="flex gap-2">
                <flux:button icon="arrow-path" wire:click="refresh">{{ __('Try again') }}</flux:button>
                <flux:button icon="cog-6-tooth" :href="route('settings.cameras')" wire:navigate>{{ __('Check settings') }}</flux:button>
            </div>
        </section>
    @elseif ($cameras === [])
        <section class="{{ $panel }} px-6 py-12 text-center text-sm text-zinc-500">{{ __('No cameras on this EZVIZ account yet. Add them in the EZVIZ app, then press Refresh.') }}</section>
    @else
        {{-- Camera grid --}}
        <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cameras as $cam)
                <button type="button" wire:key="cam-{{ $cam['serial'] }}-{{ $cam['channel'] }}" wire:click="watch('{{ $cam['serial'] }}', {{ $cam['channel'] }})" @disabled(! $cam['online'])
                    class="{{ $panel }} group flex flex-col overflow-hidden text-start transition duration-300 enabled:hover:-translate-y-1 enabled:hover:shadow-[0_24px_50px_-28px_rgb(15_23_42/0.5)] disabled:cursor-not-allowed" data-test="camera-card">
                    <div class="relative aspect-video overflow-hidden bg-zinc-900">
                        @if ($cam['picture'])
                            <img src="{{ $cam['picture'] }}" alt="" loading="lazy" @class(['size-full object-cover transition duration-500 group-hover:scale-105', 'opacity-40 grayscale' => ! $cam['online']])>
                        @else
                            <div class="flex size-full items-center justify-center bg-linear-to-br from-zinc-800 to-zinc-900"><flux:icon.video-camera class="size-10 text-zinc-600" /></div>
                        @endif
                        <div class="absolute inset-0 bg-linear-to-t from-black/60 via-transparent to-black/20"></div>
                        @if ($cam['online'])
                            <span class="absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-md bg-rose-600 px-2 py-0.5 text-[0.6875rem] font-bold tracking-wider text-white uppercase shadow"><span class="size-1.5 animate-pulse rounded-full bg-white"></span>{{ __('Live') }}</span>
                            <span class="absolute inset-0 flex items-center justify-center opacity-0 transition group-hover:opacity-100">
                                <span class="flex size-14 items-center justify-center rounded-full bg-white/90 text-zinc-900 shadow-xl backdrop-blur"><flux:icon.play variant="solid" class="size-6 translate-x-0.5" /></span>
                            </span>
                        @else
                            <span class="absolute top-3 left-3 rounded-md bg-zinc-700/90 px-2 py-0.5 text-[0.6875rem] font-bold tracking-wider text-zinc-200 uppercase">{{ __('Offline') }}</span>
                        @endif
                        @if ($cam['encrypted'])
                            <span class="absolute top-3 right-3 inline-flex items-center gap-1 rounded-md bg-amber-500/90 px-2 py-0.5 text-[0.6875rem] font-semibold text-white" title="{{ __('Video encryption is on. Turn it off in the EZVIZ app to watch here.') }}"><flux:icon.lock-closed variant="micro" class="size-3" />{{ __('Encrypted') }}</span>
                        @endif
                        <p class="absolute right-3 bottom-2.5 left-3 truncate text-sm font-semibold text-white drop-shadow">{{ $cam['name'] }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-3 text-xs text-zinc-500">
                        <span class="truncate font-mono">{{ $cam['serial'] }}@if ($cam['channel'] > 1) · {{ __('Ch :n', ['n' => $cam['channel']]) }}@endif</span>
                        <span @class(['inline-flex items-center gap-1.5 font-medium', 'text-emerald-600 dark:text-emerald-400' => $cam['online'], 'text-zinc-400' => ! $cam['online']])><span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $cam['online'], 'bg-zinc-300' => ! $cam['online']])></span>{{ $cam['online'] ? __('Online') : __('Offline') }}</span>
                    </div>
                </button>
            @endforeach
        </section>
    @endif

    {{-- Live view --}}
    <flux:modal name="camera-live" class="w-full max-w-5xl p-0!" @close="$wire.stopWatching()">
        @if ($watching)
            <div class="flex items-center gap-3 border-b border-zinc-100 px-5 py-4 pe-14 dark:border-white/5">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-rose-600 px-2 py-0.5 text-[0.6875rem] font-bold tracking-wider text-white uppercase"><span class="size-1.5 animate-pulse rounded-full bg-white"></span>{{ __('Live') }}</span>
                <flux:heading size="lg" class="min-w-0 flex-1 truncate">{{ $watching['name'] }}</flux:heading>
                <div class="flex rounded-lg bg-zinc-100 p-0.5 text-xs font-semibold dark:bg-white/5">
                    @foreach ([2 => __('Smooth'), 1 => 'HD'] as $q => $qLabel)
                        <button type="button" wire:click="setQuality({{ $q }})" @class(['rounded-md px-2.5 py-1 transition', 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' => $quality === $q, 'text-zinc-500 hover:text-zinc-800' => $quality !== $q])>{{ $qLabel }}</button>
                    @endforeach
                </div>
                <flux:button size="sm" icon="camera" wire:click="snapshot" wire:target="snapshot">{{ __('Snapshot') }}</flux:button>
            </div>

            <div class="relative aspect-video bg-black">
                @if ($liveUrl)
                    <div wire:key="player-{{ md5($liveUrl) }}" x-data="hlsPlayer(@js($liveUrl))" class="absolute inset-0">
                        <video x-ref="video" class="size-full bg-black object-contain" autoplay muted playsinline controls></video>
                        <div x-show="loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm text-zinc-300"><flux:icon.loading class="size-8" />{{ __('Connecting to the camera...') }}</div>
                        <div x-show="error" x-cloak class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-rose-300" x-text="error"></div>
                    </div>
                @else
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center">
                        <flux:icon.exclamation-triangle class="size-8 text-amber-400" />
                        <p class="max-w-md text-sm text-zinc-300" data-test="live-error">{{ $liveError ?? __('Connecting...') }}</p>
                    </div>
                @endif
            </div>

            @if ($snapshotUrl)
                <div class="flex items-center gap-4 border-t border-zinc-100 px-5 py-3 dark:border-white/5">
                    <img src="{{ $snapshotUrl }}" alt="{{ __('Snapshot') }}" class="h-16 rounded-lg ring-1 ring-zinc-200 dark:ring-white/10">
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="font-medium text-zinc-900 dark:text-white">{{ __('Snapshot taken') }}</p>
                        <p class="text-xs text-zinc-500">{{ now()->toNepali() }} · {{ now()->format('g:i A') }}</p>
                    </div>
                    <flux:button size="sm" icon="arrow-top-right-on-square" :href="$snapshotUrl" target="_blank">{{ __('Open') }}</flux:button>
                </div>
            @endif
        @endif
    </flux:modal>
</div>
