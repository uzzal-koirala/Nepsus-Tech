<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans antialiased dark:bg-zinc-900">
        <flux:sidebar sticky collapsible class="app-sidebar border-e border-zinc-200/80 bg-white dark:border-white/5 dark:bg-zinc-950">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @php
                    $sections = [
                        __('Dashboard') => [
                            ['icon' => 'home', 'label' => __('Overview'), 'route' => 'dashboard'],
                        ],
                        __('Workspace') => [
                            ['icon' => 'building-office-2', 'label' => __('Clients'), 'route' => 'clients.index'],
                            ['icon' => 'folder', 'label' => __('Projects')],
                            ['icon' => 'check-circle', 'label' => __('Tasks')],
                        ],
                        __('Team') => [
                            ['icon' => 'user-group', 'label' => __('Employees'), 'route' => 'employees.index'],
                            ['icon' => 'clock', 'label' => __('Activity log')],
                        ],
                    ];
                @endphp

                @foreach ($sections as $heading => $items)
                    <div class="flex flex-col">
                        <p class="px-3 pt-5 pb-2 text-[0.6875rem] font-semibold tracking-[0.08em] text-zinc-400 uppercase in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-500">{{ $heading }}</p>
                        <hr class="mx-auto my-3 hidden w-6 border-zinc-200 in-data-flux-sidebar-collapsed-desktop:block dark:border-white/10">

                        @foreach ($items as $item)
                            @isset($item['route'])
                                <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs(str($item['route'])->before('.').'*')" wire:navigate>
                                    {{ $item['label'] }}
                                </flux:sidebar.item>
                            @else
                                {{-- ponytail: placeholder until the module ships; swap in :href when its route exists --}}
                                <flux:sidebar.item :icon="$item['icon']" badge="{{ __('Soon') }}" aria-disabled="true" class="pointer-events-none opacity-55">
                                    {{ $item['label'] }}
                                </flux:sidebar.item>
                            @endisset
                        @endforeach
                    </div>
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            {{-- Workspace card --}}
            <div class="grain relative isolate mt-6 overflow-hidden rounded-2xl bg-brand-500 p-4 text-white in-data-flux-sidebar-collapsed-desktop:hidden">
                <div class="absolute -top-10 -right-10 -z-10 size-20 rounded-full bg-sun"></div>
                <div class="absolute inset-0 -z-10 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:16px_16px]"></div>
                <p class="text-xs font-medium text-brand-100">{{ __('Up next') }}</p>
                <p class="mt-1 pe-6 text-sm leading-snug font-semibold text-balance">{{ __('Client profiles, projects and task boards') }}</p>
                <p class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-[0.6875rem] font-medium ring-1 ring-white/20">
                    <span class="size-1.5 rounded-full bg-sun"></span>
                    {{ __('In progress') }}
                </p>
            </div>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.edit', 'security.edit', 'appearance.edit')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <div class="rounded-2xl bg-zinc-50 p-1.5 ring-1 ring-zinc-200/70 in-data-flux-sidebar-collapsed-desktop:bg-transparent in-data-flux-sidebar-collapsed-desktop:p-0 in-data-flux-sidebar-collapsed-desktop:ring-0 dark:bg-white/5 dark:ring-white/5">
                <flux:dropdown position="top" align="start">
                    <button type="button" class="flex w-full items-center gap-3 rounded-xl p-1.5 text-start transition hover:bg-zinc-100 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:p-0 dark:hover:bg-white/5" data-test="sidebar-menu-button">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-sun text-sm font-semibold text-brand-950">
                            {{ auth()->user()->initials() }}
                        </span>
                        <span class="grid min-w-0 flex-1 leading-tight in-data-flux-sidebar-collapsed-desktop:hidden">
                            <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ auth()->user()->name }}</span>
                            <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()->email }}</span>
                        </span>
                        <flux:icon.ellipsis-vertical variant="micro" class="text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden" />
                    </button>

                    <flux:menu>
                        <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                                {{ __('Log out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </flux:sidebar>

        <!-- Mobile header -->
        <flux:header class="border-b border-zinc-200/80 bg-white lg:hidden dark:border-white/5 dark:bg-zinc-950">
            <flux:sidebar.toggle icon="bars-2" inset="left" />
            <x-app-logo href="{{ route('dashboard') }}" class="ms-2" wire:navigate />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
