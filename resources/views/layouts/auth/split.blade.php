<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans antialiased dark:bg-brand-950">
        <div class="flex min-h-dvh items-center justify-center p-3 sm:p-6 lg:p-10">
            <div class="grid w-full max-w-6xl gap-3 rounded-[2rem] bg-white p-3 shadow-[0_40px_100px_-40px_rgb(49_81_254/0.35)] lg:min-h-[44rem] lg:grid-cols-[1.05fr_1fr] dark:bg-zinc-900 dark:shadow-none">
                {{-- Brand panel --}}
                <aside class="relative isolate flex h-80 flex-col overflow-hidden rounded-[1.5rem] bg-[radial-gradient(130%_90%_at_0%_0%,#5a78ff_0%,#3151fe_45%,#2440d6_100%)] p-5 text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.25)] sm:h-96 sm:p-6 lg:h-auto lg:p-10">
                    {{-- Stage: soft lit disc --}}
                    <div class="reveal absolute -bottom-24 left-1/2 -z-10 size-72 -translate-x-1/2 rounded-full bg-[radial-gradient(circle_at_35%_30%,#ffd84d_0%,#ffc20e_55%,#f0ab00_100%)] shadow-[0_40px_120px_-20px_rgb(255_194_14/0.45),inset_0_2px_10px_rgb(255_255_255/0.5)] sm:size-80 lg:-bottom-40 lg:size-[30rem]" style="animation-delay: 80ms"></div>

                    <div class="flex items-center justify-between">
                        <img src="{{ asset('images/nepsus-logo.png') }}" alt="Nepsus Tech" class="h-12 w-auto rounded-xl lg:h-14">
                        <a href="{{ route('home') }}" class="group inline-flex items-center gap-2 rounded-full bg-white/10 py-1 ps-1 pe-4 text-sm font-medium ring-1 ring-white/20 transition duration-500 ease-[cubic-bezier(0.32,0.72,0,1)] hover:bg-white/20 active:scale-[0.98]" wire:navigate>
                            <span class="flex size-8 items-center justify-center rounded-full bg-white text-brand-600 transition duration-500 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:-translate-x-0.5 group-hover:scale-105">
                                <flux:icon.arrow-left variant="micro" />
                            </span>
                            {{ __('Back to site') }}
                        </a>
                    </div>

                    <div class="reveal relative z-10 mt-14 hidden lg:block">
                        <h2 class="text-[2.75rem] leading-[1.05] font-semibold tracking-[-0.035em]">
                            {{ __('All your client work,') }}<br>
                            <span class="text-brand-200">{{ __('in one place.') }}</span>
                        </h2>
                        <p class="mt-5 max-w-[19rem] text-[0.9375rem] leading-relaxed text-brand-100/90">
                            {{ __('Clients, projects and tasks for the whole Nepsus Tech team.') }}
                        </p>
                    </div>

                    <img
                        src="{{ asset('images/auth-crew.webp') }}"
                        alt="{{ __('Three cartoon characters in sunglasses: two bunnies and an owl') }}"
                        class="reveal absolute inset-x-0 bottom-0 mx-auto h-[80%] w-auto max-w-none drop-shadow-[0_30px_40px_rgb(20_30_110/0.45)] lg:h-[56%]"
                        style="animation-delay: 160ms"
                    >
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
