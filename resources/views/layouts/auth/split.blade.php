<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-brand-50 font-sans antialiased dark:bg-brand-950">
        <div class="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
            {{-- Brand panel --}}
            <aside class="relative hidden overflow-hidden bg-brand-500 p-12 text-white lg:m-4 lg:flex lg:flex-col lg:rounded-[2rem]">
                {{-- Dot grid --}}
                <div class="absolute inset-0 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_bottom,black,transparent_75%)]"></div>
                {{-- Glow --}}
                <div class="absolute -right-32 -bottom-32 size-[28rem] rounded-full bg-brand-300/40 blur-3xl"></div>

                {{-- Brand shapes --}}
                <div class="absolute top-[22%] -right-16 size-64 rounded-full bg-sun motion-safe:animate-float"></div>
                <svg class="absolute right-40 bottom-[24%] w-36 text-sun motion-safe:animate-float-slow" viewBox="0 0 100 100" aria-hidden="true">
                    <path d="M14 8 92 42a6 6 0 0 1 .6 10.8L18 94a6 6 0 0 1-8.8-6.2L5.4 14.4A6 6 0 0 1 14 8Z" fill="currentColor" />
                </svg>

                <a href="{{ route('home') }}" class="relative z-10 flex items-center gap-3" wire:navigate>
                    <x-app-logo-icon class="size-10" />
                    <span class="text-2xl leading-[0.85] font-bold tracking-tight">Nepsus<br>tech</span>
                </a>

                <div class="relative z-10 mt-auto max-w-md">
                    <p class="mb-5 inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-sm font-medium backdrop-blur">
                        <span class="size-2 rounded-full bg-sun"></span>
                        {{ __('Client & Task Management') }}
                    </p>
                    <h2 class="text-5xl leading-[1.05] font-bold tracking-tight">
                        {{ __('Every client.') }}<br>
                        {{ __('Every task.') }}<br>
                        <span class="text-sun">{{ __('One place.') }}</span>
                    </h2>
                    <p class="mt-5 text-lg text-brand-100">
                        {{ __('Track projects, assign work and keep your whole team moving in the same direction.') }}
                    </p>
                    <p class="mt-12 text-sm text-brand-200">&copy; {{ date('Y') }} Nepsus Tech</p>
                </div>
            </aside>

            {{-- Form side --}}
            <main class="flex items-center justify-center px-6 py-12 sm:px-10">
                <div class="w-full max-w-sm">
                    <a href="{{ route('home') }}" class="mb-10 flex items-center justify-center gap-3 lg:hidden" wire:navigate>
                        <span class="flex size-12 items-center justify-center rounded-xl bg-brand-500">
                            <x-app-logo-icon class="size-8" />
                        </span>
                        <span class="text-2xl leading-[0.85] font-bold tracking-tight text-brand-950 dark:text-white">Nepsus<br>tech</span>
                    </a>

                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
