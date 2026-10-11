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
                    <div class="absolute -bottom-56 left-1/2 -z-10 hidden size-[46rem] -translate-x-1/2 rounded-full border border-white/10 lg:block"></div>
                    <div class="absolute -bottom-40 left-1/2 -z-10 hidden size-[36rem] -translate-x-1/2 rounded-full border border-white/15 lg:block"></div>
                    {{-- Sun (from the logo) --}}
                    <div class="absolute top-[42%] left-1/2 -z-10 size-56 -translate-x-1/2 rounded-full bg-sun shadow-[0_0_120px_20px_rgb(255_194_14/0.35)] sm:size-72 lg:top-auto lg:-bottom-16 lg:size-[22rem]"></div>

                    <div class="flex items-start justify-between">
                        <img src="{{ asset('images/nepsus-logo.png') }}" alt="Nepsus Tech" class="h-12 w-auto rounded-xl ring-1 ring-white/25 lg:h-14">
                        <a href="{{ route('home') }}" class="flex size-10 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/30 backdrop-blur-md transition hover:bg-white/25 active:scale-95" aria-label="{{ __('Back to home') }}" wire:navigate>
                            <flux:icon.arrow-left variant="micro" />
                        </a>
                    </div>

                    <div class="mt-8 hidden max-w-sm lg:block">
                        <h2 class="text-4xl leading-[1.05] font-semibold tracking-[-0.03em] text-balance text-white">
                            {{ __('Every client.') }}<br>{{ __('Every task.') }}<br><span class="text-sun">{{ __('One place.') }}</span>
                        </h2>
                        <p class="mt-4 max-w-xs text-sm leading-relaxed text-brand-100">
                            {{ __('Nepsus Tech keeps your clients, projects and team in one shared workspace.') }}
                        </p>
                    </div>

                    <img
                        src="{{ asset('images/auth-crew.webp') }}"
                        alt="{{ __('Three cartoon characters in sunglasses: two bunnies and an owl') }}"
                        class="absolute inset-x-0 bottom-0 mx-auto h-[78%] w-auto max-w-none drop-shadow-[0_24px_30px_rgb(18_26_66/0.45)] lg:h-[56%]"
                    >

                    {{-- Product preview: task card --}}
                    <div class="absolute right-6 bottom-[30%] hidden w-56 rotate-3 rounded-2xl bg-white p-4 text-zinc-900 shadow-[0_24px_50px_-20px_rgb(18_26_66/0.7)] lg:block" aria-hidden="true">
                        <div class="flex items-center justify-between">
                            <span class="rounded-md bg-brand-50 px-2 py-0.5 text-[0.6875rem] font-semibold text-brand-600">{{ __('Task') }}</span>
                            <span class="size-2 rounded-full bg-sun"></span>
                        </div>
                        <p class="mt-2.5 text-sm font-semibold">{{ __('Send project proposal') }}</p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100">
                            <div class="h-full w-2/3 rounded-full bg-brand-500"></div>
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <div class="flex -space-x-1.5">
                                <span class="size-6 rounded-full bg-sun ring-2 ring-white"></span>
                                <span class="size-6 rounded-full bg-brand-300 ring-2 ring-white"></span>
                                <span class="size-6 rounded-full bg-zinc-800 ring-2 ring-white"></span>
                            </div>
                            <span class="inline-flex items-center gap-1 text-[0.6875rem] font-medium text-emerald-600">
                                <flux:icon.check-circle variant="micro" class="size-3.5" />
                                {{ __('On track') }}
                            </span>
                        </div>
                    </div>

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
