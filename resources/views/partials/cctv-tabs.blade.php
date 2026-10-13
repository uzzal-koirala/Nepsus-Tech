{{-- Header and tabs shared by the CCTV pages. Cameras and Access log are for admins only. --}}
<header class="flex flex-col gap-4">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold tracking-wider text-zinc-400 uppercase">{{ __('Office Management') }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('CCTV Monitoring') }}</h1>
        </div>
        {{ $actions ?? '' }}
    </div>

    @can('manage-cctv')
        <nav class="flex w-fit gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10" aria-label="{{ __('CCTV sections') }}">
            @foreach ([['cctv.index', 'video-camera', __('Live')], ['cctv.cameras', 'cog-6-tooth', __('Cameras')], ['cctv.logs', 'clipboard-document-list', __('Access log')]] as [$route, $icon, $label])
                <a href="{{ route($route) }}" wire:navigate @class([
                    'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white shadow-md shadow-brand-500/30' => request()->routeIs($route),
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => ! request()->routeIs($route),
                ]) @if (request()->routeIs($route)) aria-current="page" @endif>
                    <flux:icon :icon="$icon" variant="micro" class="size-4" />{{ $label }}
                </a>
            @endforeach
        </nav>
    @endcan
</header>
