<?php

use App\Models\CctvAccessLog;
use App\Models\CctvCamera;
use App\Support\CctvToken;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('CCTV Monitoring')] class extends Component {
    public function mount(): void
    {
        Gate::authorize('view-cctv');
    }

    #[Computed]
    public function cameras()
    {
        return CctvCamera::where('enabled', true)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Gateway address and a short-lived token for one camera. Checked on the server for every
     * request, and logged. The camera password is never part of this.
     */
    public function access(int $id): array
    {
        Gate::authorize('view-cctv');
        $camera = CctvCamera::where('enabled', true)->findOrFail($id);

        if (! $url = $camera->hlsUrl()) {
            return ['error' => __('The CCTV gateway address is not set yet.')];
        }

        CctvAccessLog::create([
            'user_id' => auth()->id(),
            'cctv_camera_id' => $camera->id,
            'camera_name' => $camera->name,
            'action' => 'view',
            'ip' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);

        return ['url' => $url, 'token' => CctvToken::issue($camera, auth()->user())];
    }

    /** The browser reports the first frame it played, so admins see when a camera last worked. */
    public function markOnline(int $id): void
    {
        Gate::authorize('view-cctv');
        CctvCamera::whereKey($id)->update(['last_online_at' => now()]);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $cameras = $this->cameras;
    $gateway = CctvCamera::gateway();
    $states = [
        'idle' => ['', ''],
        'connecting' => [__('Connecting'), 'bg-amber-500/90 text-white'],
        'live' => [__('Live'), 'bg-rose-600 text-white'],
        'paused' => [__('Paused'), 'bg-zinc-700/90 text-white'],
        'offline' => [__('Offline'), 'bg-zinc-700/90 text-zinc-100'],
        'unavailable' => [__('Unavailable'), 'bg-zinc-700/90 text-zinc-100'],
        'denied' => [__('No access'), 'bg-rose-700/90 text-white'],
        'failed' => [__('Connection failed'), 'bg-rose-700/90 text-white'],
    ];
@endphp

<div class="stagger mx-auto flex w-full max-w-7xl flex-col gap-6" x-data="cctvGateway(@js($gateway))">
    @include('partials.cctv-tabs')

    {{-- Gateway status --}}
    @if ($gateway)
        <div class="flex flex-wrap items-center gap-3 rounded-xl px-4 py-3 text-sm ring-1"
            :class="{
                'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20': status === 'online',
                'bg-zinc-50 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10': status === 'checking',
                'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20': status === 'offline' || status === 'insecure',
            }" data-test="gateway-status">
            <span class="relative flex size-2.5">
                <span x-show="status === 'online'" class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex size-2.5 rounded-full" :class="{ 'bg-emerald-500': status === 'online', 'bg-zinc-400': status === 'checking', 'bg-amber-500': status === 'offline' || status === 'insecure' }"></span>
            </span>
            <span class="flex-1">
                <span x-show="status === 'checking'">{{ __('Checking the CCTV gateway...') }}</span>
                <span x-show="status === 'online'" x-cloak>{{ __('CCTV gateway is online. Press Play on a camera to watch.') }}</span>
                <span x-show="status === 'offline'" x-cloak>{{ __('Cameras unavailable: the office PC with the CCTV gateway is off, or this device is not on the office network / Tailscale.') }}</span>
                <span x-show="status === 'insecure'" x-cloak>{{ __('This page is on HTTPS but the gateway address is HTTP, so the browser blocks the video. Use the HTTPS Tailscale address.') }}</span>
            </span>
            <button type="button" @click="check()" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-current/20 transition hover:bg-white/60 dark:hover:bg-white/10"><flux:icon.arrow-path variant="micro" class="size-3.5" />{{ __('Check again') }}</button>
        </div>
    @endif

    @if (! $gateway || $cameras->isEmpty())
        {{-- Not set up yet --}}
        <section class="{{ $panel }} relative overflow-hidden">
            <div class="absolute inset-0 bg-linear-to-br from-brand-50 via-white to-sky-50 dark:from-brand-500/10 dark:via-zinc-950 dark:to-sky-500/5"></div>
            <div class="relative flex flex-col items-center gap-5 px-6 py-16 text-center">
                <span class="flex size-20 items-center justify-center rounded-3xl bg-linear-to-br from-brand-500 to-brand-600 text-white shadow-xl shadow-brand-500/30"><flux:icon.video-camera class="size-10" /></span>
                <div>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $gateway ? __('No cameras yet') : __('CCTV is not set up yet') }}</p>
                    <p class="mt-1 max-w-md text-sm text-zinc-500">
                        @can('manage-cctv')
                            {{ __('Add your cameras and the gateway address, then download the gateway setup for the office PC.') }}
                        @else
                            {{ __('Ask an admin to add the cameras.') }}
                        @endcan
                    </p>
                </div>
                @can('manage-cctv')
                    <flux:button variant="primary" icon="cog-6-tooth" :href="route('cctv.cameras')" wire:navigate>{{ __('Set up cameras') }}</flux:button>
                @endcan
            </div>
        </section>
    @else
        {{-- Camera grid: up to 2 x 2 --}}
        <section class="grid gap-5 md:grid-cols-2">
            @foreach ($cameras as $cam)
                <article wire:key="cctv-{{ $cam->id }}" x-data="cctvPlayer({{ $cam->id }})" class="{{ $panel }} flex flex-col overflow-hidden" data-test="cctv-tile">
                    <div x-ref="frame" class="group relative aspect-video overflow-hidden bg-zinc-950">
                        <video x-ref="video" class="size-full bg-black object-contain" muted playsinline></video>

                        {{-- Idle / message overlay --}}
                        <div x-show="state !== 'live' && state !== 'paused'" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-[radial-gradient(ellipse_at_center,rgb(39_39_42)_0%,rgb(9_9_11)_70%)] p-6 text-center">
                            <template x-if="state === 'idle'">
                                <button type="button" @click="open()" class="flex flex-col items-center gap-3 text-zinc-300 transition hover:text-white" :disabled="status !== 'online'" :class="status !== 'online' && 'opacity-50 cursor-not-allowed'">
                                    <span class="flex size-16 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/20 backdrop-blur transition group-hover:scale-105 group-hover:bg-white/20"><flux:icon.play variant="solid" class="size-7 translate-x-0.5" /></span>
                                    <span class="text-sm font-medium" x-text="status === 'online' ? '{{ __('Click to watch') }}' : (status === 'checking' ? '{{ __('Checking gateway...') }}' : '{{ __('Camera unavailable') }}')"></span>
                                </button>
                            </template>
                            <template x-if="state === 'connecting'">
                                <div class="flex flex-col items-center gap-3 text-zinc-300"><flux:icon.loading class="size-8" /><span class="text-sm">{{ __('Connecting to the camera...') }}</span></div>
                            </template>
                            <template x-if="!['idle', 'connecting'].includes(state)">
                                <div class="flex max-w-sm flex-col items-center gap-3">
                                    <span class="flex size-12 items-center justify-center rounded-full bg-white/10 text-amber-300"><flux:icon.signal-slash class="size-6" /></span>
                                    <p class="text-sm text-zinc-300" x-text="message"></p>
                                    <button type="button" @click="refresh()" class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white ring-1 ring-white/20 hover:bg-white/20"><flux:icon.arrow-path variant="micro" class="size-3.5" />{{ __('Try again') }}</button>
                                </div>
                            </template>
                        </div>

                        {{-- Top: state badge and name --}}
                        <div class="pointer-events-none absolute inset-x-0 top-0 flex items-center gap-2 bg-linear-to-b from-black/70 to-transparent p-3">
                            @foreach ($states as $key => [$label, $tone])
                                @if ($label)
                                    <span x-show="state === '{{ $key }}'" x-cloak class="{{ $tone }} inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[0.6875rem] font-bold tracking-wider uppercase shadow">
                                        @if ($key === 'live')<span class="size-1.5 animate-pulse rounded-full bg-white"></span>@endif{{ $label }}
                                    </span>
                                @endif
                            @endforeach
                            <span x-show="state === 'idle'" class="inline-flex items-center gap-1.5 rounded-md bg-black/50 px-2 py-0.5 text-[0.6875rem] font-bold tracking-wider text-white uppercase">
                                <span class="size-1.5 rounded-full" :class="status === 'online' ? 'bg-emerald-400' : 'bg-zinc-400'"></span>
                                <span x-text="status === 'online' ? '{{ __('Online') }}' : '{{ __('Offline') }}'"></span>
                            </span>
                            <p class="ms-auto truncate text-sm font-semibold text-white drop-shadow">{{ $cam->name }}</p>
                        </div>

                        {{-- Bottom: controls --}}
                        <div class="absolute inset-x-0 bottom-0 flex items-center gap-1 bg-linear-to-t from-black/75 to-transparent p-2.5 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100" :class="(state === 'live' || state === 'paused') && 'sm:opacity-100'">
                            @php $ctl = 'flex size-9 items-center justify-center rounded-lg text-white transition hover:bg-white/15 disabled:opacity-40'; @endphp
                            <button type="button" x-show="state !== 'live'" @click="play()" :disabled="busy || status !== 'online'" class="{{ $ctl }}" aria-label="{{ __('Play') }}" title="{{ __('Play') }}"><flux:icon.play variant="mini" class="size-5" /></button>
                            <button type="button" x-show="state === 'live'" x-cloak @click="pause()" class="{{ $ctl }}" aria-label="{{ __('Pause') }}" title="{{ __('Pause') }}"><flux:icon.pause variant="mini" class="size-5" /></button>
                            <button type="button" @click="refresh()" :disabled="busy || status !== 'online'" class="{{ $ctl }}" aria-label="{{ __('Refresh') }}" title="{{ __('Refresh') }}"><flux:icon.arrow-path variant="mini" class="size-5" /></button>
                            <button type="button" x-show="state === 'live' || state === 'paused'" x-cloak @click="stop()" class="{{ $ctl }}" aria-label="{{ __('Stop') }}" title="{{ __('Stop') }}"><flux:icon.stop variant="mini" class="size-5" /></button>
                            <span class="flex-1"></span>
                            <button type="button" @click="fullscreen()" class="{{ $ctl }}" aria-label="{{ __('Fullscreen') }}" title="{{ __('Fullscreen') }}"><flux:icon.arrows-pointing-out variant="mini" class="size-5" /></button>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-4 py-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-white/5"><flux:icon.video-camera variant="mini" class="size-4.5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $cam->name }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ $cam->location ?: __('No location set') }}</p>
                        </div>
                        <p class="text-end text-xs text-zinc-400">{{ $cam->last_online_at ? __('Last live :time', ['time' => $cam->last_online_at->diffForHumans()]) : __('Not watched yet') }}</p>
                    </div>
                </article>
            @endforeach
        </section>

        <p class="flex items-center gap-2 text-xs text-zinc-500">
            <flux:icon.lock-closed variant="micro" class="size-4 text-zinc-400" />
            {{ __('Video goes straight from the office gateway to your browser and is never stored. Each view is logged.') }}
        </p>
    @endif
</div>
