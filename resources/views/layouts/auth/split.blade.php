<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans antialiased dark:bg-brand-950">
        <div class="flex min-h-dvh items-center justify-center p-3 sm:p-6 lg:p-10">
            <div class="grid w-full max-w-6xl gap-3 rounded-[2rem] bg-white p-3 shadow-[0_40px_100px_-40px_rgb(49_81_254/0.35)] lg:min-h-[44rem] lg:grid-cols-[1.05fr_1fr] dark:bg-zinc-900 dark:shadow-none">
                {{-- Brand panel --}}
                <aside class="grain relative isolate flex h-80 flex-col overflow-hidden rounded-[1.5rem] bg-brand-500 p-5 sm:h-96 sm:p-6 lg:h-auto lg:p-8">
                    {{-- Dot grid --}}
                    <div class="absolute inset-0 -z-10 opacity-25 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:24px_24px] [mask-image:radial-gradient(ellipse_at_top,black,transparent_70%)]"></div>
                    {{-- Depth glow --}}
                    <div class="absolute -bottom-40 left-1/2 -z-10 size-[36rem] -translate-x-1/2 rounded-full bg-brand-800/60 blur-3xl"></div>
                    {{-- Orbit rings --}}
                    <div class="absolute -right-60 -bottom-64 -z-10 hidden size-[46rem] rounded-full border border-white/10 lg:block"></div>
                    <div class="absolute -right-40 -bottom-44 -z-10 hidden size-[36rem] rounded-full border border-white/15 lg:block"></div>
                    {{-- Sun (from the logo) --}}
                    <div class="absolute top-[42%] left-1/2 -z-10 size-56 -translate-x-1/2 rounded-full bg-sun shadow-[0_0_120px_20px_rgb(255_194_14/0.35)] sm:size-72 lg:top-auto lg:-right-12 lg:-bottom-16 lg:left-auto lg:size-[22rem] lg:translate-x-0"></div>

                    <div class="flex items-start justify-between">
                        <img src="{{ asset('images/nepsus-logo.png') }}" alt="Nepsus Tech" class="h-12 w-auto rounded-xl ring-1 ring-white/25 lg:h-14">
                        <a href="{{ route('home') }}" class="flex size-10 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/30 backdrop-blur-md transition hover:bg-white/25 active:scale-95" aria-label="{{ __('Back to home') }}" wire:navigate>
                            <flux:icon.arrow-left variant="micro" />
                        </a>
                    </div>

                    <div class="relative z-10 mt-10 hidden lg:block">
                        <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white ring-1 ring-white/20 backdrop-blur">
                            <span class="size-1.5 rounded-full bg-sun"></span>
                            {{ __('Client & task management') }}
                        </p>
                        <h2 class="mt-5 text-[3.25rem] leading-[0.98] font-bold tracking-[-0.03em] text-white">
                            {{ __('Every client.') }}<br>
                            <span class="text-white/55">{{ __('Every task.') }}</span><br>
                            <span class="relative inline-block text-sun">
                                {{ __('One place.') }}
                                <svg class="draw absolute -bottom-4 left-0 h-3 w-full" viewBox="0 0 300 16" preserveAspectRatio="none" aria-hidden="true">
                                    <path d="M4 11C70 4 190 1 296 8" stroke="currentColor" stroke-width="5" fill="none" stroke-linecap="round" pathLength="1" />
                                </svg>
                            </span>
                        </h2>
                        <p class="mt-7 max-w-[15rem] text-sm leading-relaxed text-brand-100">
                            {{ __('Nepsus Tech keeps your clients, projects and team in one shared workspace.') }}
                        </p>
                    </div>

                    <img
                        src="{{ asset('images/auth-crew.webp') }}"
                        alt="{{ __('Three cartoon characters in sunglasses: two bunnies and an owl') }}"
                        class="absolute inset-x-0 bottom-0 mx-auto h-[78%] w-auto max-w-none drop-shadow-[0_24px_30px_rgb(18_26_66/0.45)] lg:right-0 lg:left-auto lg:mx-0 lg:h-[62%]"
                    >

                    {{-- Product preview: notification --}}
                    <div class="absolute bottom-8 left-6 hidden -rotate-2 items-center gap-3 rounded-2xl bg-brand-950/40 py-2.5 ps-2.5 pe-5 text-white shadow-[0_16px_40px_-16px_rgb(18_26_66/0.8)] ring-1 ring-white/20 backdrop-blur-md lg:flex" aria-hidden="true">
                        <span class="flex size-9 items-center justify-center rounded-full bg-emerald-400 text-brand-950">
                            <flux:icon.check variant="mini" class="size-4.5" />
                        </span>
                        <span class="grid leading-tight">
                            <span class="text-sm font-semibold">{{ __('Task completed') }}</span>
                            <span class="text-xs text-brand-100">{{ __('Moved to Done') }}</span>
                        </span>
                    </div>
                </aside>

                {{-- Form --}}
                <main class="flex flex-col px-3 pt-8 pb-4 sm:px-10 lg:px-14 lg:pt-12">
                    <div class="mx-auto my-auto w-full max-w-sm">
                        {{ $slot }}
                    </div>

                    <footer class="mt-10 flex flex-wrap justify-between gap-2 text-xs text-zinc-400 dark:text-zinc-500">
                        <span>&copy; {{ date('Y') }} Nepsus Tech</span>
                        <span>{{ __('Client & Task Management') }}</span>
                    </footer>
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
