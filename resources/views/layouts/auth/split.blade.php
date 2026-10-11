<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans antialiased dark:bg-brand-950">
        <div class="flex min-h-dvh items-center justify-center p-3 sm:p-6 lg:p-10">
            <div class="grid w-full max-w-6xl gap-3 rounded-[2rem] bg-white p-3 shadow-[0_40px_100px_-40px_rgb(49_81_254/0.35)] lg:min-h-[44rem] lg:grid-cols-[1.05fr_1fr] dark:bg-zinc-900 dark:shadow-none">
                {{-- Brand panel --}}
                <aside class="relative isolate flex h-80 flex-col overflow-hidden rounded-[1.5rem] bg-brand-500 p-5 text-white sm:h-96 sm:p-6 lg:h-auto lg:p-10">
                    <div class="absolute top-[38%] left-1/2 -z-10 size-60 -translate-x-1/2 rounded-full bg-sun sm:size-72 lg:top-[30%] lg:-right-28 lg:left-auto lg:size-[27rem] lg:translate-x-0"></div>

                    <div class="flex items-start justify-between">
                        <img src="{{ asset('images/nepsus-logo.png') }}" alt="Nepsus Tech" class="h-12 w-auto rounded-xl lg:h-14">
                        <a href="{{ route('home') }}" class="flex size-10 items-center justify-center rounded-full border border-white/30 text-white transition hover:bg-white hover:text-brand-600" aria-label="{{ __('Back to home') }}" wire:navigate>
                            <flux:icon.arrow-left variant="micro" />
                        </a>
                    </div>

                    <h2 class="relative z-10 mt-12 hidden max-w-[19rem] text-[2.5rem] leading-[1.08] font-semibold tracking-[-0.025em] lg:block">
                        {{ __('All your client work,') }}<br>{{ __('in one place.') }}
                    </h2>

                    <img
                        src="{{ asset('images/auth-crew.webp') }}"
                        alt="{{ __('Three cartoon characters in sunglasses: two bunnies and an owl') }}"
                        class="absolute inset-x-0 bottom-0 mx-auto h-[80%] w-auto max-w-none lg:right-2 lg:left-auto lg:mx-0 lg:h-[70%]"
                    >

                    <dl class="relative z-10 mt-auto hidden w-40 gap-1 border-t border-white/30 pt-4 text-xs lg:grid">
                        <dt class="font-semibold">Nepsus Tech</dt>
                        <dd class="text-brand-100">{{ __('Clients, projects and tasks for the whole team.') }}</dd>
                    </dl>
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
