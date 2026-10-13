@php
    $groups = [
        __('Workspace') => [
            ['route' => 'settings.business', 'icon' => 'building-office-2', 'label' => __('Business'), 'hint' => __('Name, logo and contact details')],
            ['route' => 'settings.preferences', 'icon' => 'adjustments-horizontal', 'label' => __('Preferences'), 'hint' => __('Currency, reminders and holidays')],
            ['route' => 'settings.work-types', 'icon' => 'swatch', 'label' => __('Work types'), 'hint' => __('Post, reel, edit and more')],
            ['route' => 'settings.attendance-device', 'icon' => 'finger-print', 'label' => __('Attendance device'), 'hint' => __('Hikvision fingerprint machine')],
            ['route' => 'settings.cameras', 'icon' => 'video-camera', 'label' => __('Cameras'), 'hint' => __('EZVIZ CCTV account')],
        ],
        __('Your account') => [
            ['route' => 'profile.edit', 'icon' => 'user-circle', 'label' => __('Profile'), 'hint' => __('Your name and email')],
            ['route' => 'security.edit', 'icon' => 'shield-check', 'label' => __('Security'), 'hint' => __('Password and two-factor')],
            ['route' => 'appearance.edit', 'icon' => 'paint-brush', 'label' => __('Appearance'), 'hint' => __('Light or dark theme')],
        ],
    ];
@endphp

<div class="flex items-start gap-8 max-lg:flex-col">
    {{-- Menu --}}
    <nav class="w-full shrink-0 lg:sticky lg:top-6 lg:w-72" aria-label="{{ __('Settings') }}">
        <div class="flex flex-col gap-5 rounded-2xl bg-white p-3 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
            @foreach ($groups as $groupLabel => $items)
                <div>
                    <p class="px-3 pb-1.5 text-[0.6875rem] font-semibold tracking-wider text-zinc-400 uppercase">{{ $groupLabel }}</p>
                    <ul class="flex flex-col gap-0.5">
                        @foreach ($items as $item)
                            @php $current = request()->routeIs($item['route']); @endphp
                            <li>
                                <a href="{{ route($item['route']) }}" wire:navigate @class([
                                    'group flex items-center gap-3 rounded-xl px-3 py-2.5 transition',
                                    'bg-brand-50 ring-1 ring-brand-200/70 dark:bg-brand-500/10 dark:ring-brand-500/20' => $current,
                                    'hover:bg-zinc-50 dark:hover:bg-white/5' => ! $current,
                                ]) @if ($current) aria-current="page" @endif>
                                    <span @class([
                                        'flex size-9 shrink-0 items-center justify-center rounded-lg transition',
                                        'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $current,
                                        'bg-zinc-100 text-zinc-500 group-hover:text-zinc-800 dark:bg-white/5 dark:text-zinc-400 dark:group-hover:text-white' => ! $current,
                                    ])>
                                        <flux:icon :icon="$item['icon']" variant="mini" class="size-4.5" />
                                    </span>
                                    <span class="min-w-0">
                                        <span @class(['block text-sm font-semibold', 'text-brand-700 dark:text-brand-200' => $current, 'text-zinc-800 dark:text-zinc-100' => ! $current])>{{ $item['label'] }}</span>
                                        <span class="block truncate text-xs text-zinc-500">{{ $item['hint'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </nav>

    {{-- Content --}}
    <div class="w-full min-w-0 flex-1">
        <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
            <div class="border-b border-zinc-100 px-6 py-5 sm:px-8 dark:border-white/5">
                <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $heading ?? '' }}</h2>
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $subheading ?? '' }}</p>
            </div>
            <div class="px-6 py-6 sm:px-8">
                <div class="w-full {{ $wide ?? false ? '' : 'max-w-2xl' }}">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</div>
