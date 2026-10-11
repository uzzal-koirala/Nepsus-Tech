<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-200/70 font-sans antialiased dark:bg-brand-950">
        <div class="flex min-h-dvh items-center justify-center p-4 sm:p-8">
            <div class="relative grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white p-6 shadow-sm sm:p-10 lg:min-h-[44rem] lg:grid-cols-2 lg:grid-rows-[1fr_auto] lg:gap-x-16 lg:gap-y-6 lg:p-12 dark:bg-zinc-900">
                {{-- Watermark --}}
                <x-app-logo-icon class="pointer-events-none absolute -top-16 -left-20 hidden size-[22rem] opacity-[0.07] grayscale lg:block dark:opacity-[0.05]" aria-hidden="true" />

                {{-- Brand side --}}
                <div class="relative hidden flex-col lg:flex">
                    <a href="{{ route('home') }}" class="flex size-10 items-center justify-center rounded-xl border border-zinc-200 bg-white text-zinc-700 transition hover:border-brand-300 hover:text-brand-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" aria-label="{{ __('Back to home') }}" wire:navigate>
                        <flux:icon.arrow-left variant="micro" />
                    </a>

                    <div class="relative -mx-4 my-4 min-h-[24rem] flex-1">
                        <img src="{{ asset('images/auth-crew.webp') }}" alt="" class="absolute inset-0 size-full object-contain object-bottom drop-shadow-xl">
                    </div>

                    <div class="max-w-sm">
                        <img src="{{ asset('images/nepsus-logo.png') }}" alt="Nepsus Tech" class="h-16 w-auto">
                        <p class="mt-5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                            {{ __('Nepsus Tech is your client and task workspace. Keep every client, project and task in one place, from first contact to final delivery.') }}
                        </p>
                    </div>
                </div>

                {{-- Form side --}}
                <div class="relative flex flex-col justify-center">
                    <a href="{{ route('home') }}" class="mb-8 self-start lg:hidden" wire:navigate>
                        <img src="{{ asset('images/nepsus-logo.png') }}" alt="Nepsus Tech" class="h-16 w-auto">
                    </a>

                    <div class="w-full lg:max-w-sm">
                        {{ $slot }}
                    </div>
                </div>

                {{-- Footer --}}
                <p class="relative mt-10 text-xs text-zinc-400 lg:col-span-2 lg:mt-0 lg:self-end lg:text-end dark:text-zinc-500">
                    &copy; {{ date('Y') }} Nepsus Tech &middot; {{ __('All rights reserved.') }}
                </p>
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
