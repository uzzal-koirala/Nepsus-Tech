<x-layouts::auth :title="__('Log in')">
    <div class="stagger flex flex-col gap-5">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-[2rem] leading-tight font-bold tracking-[-0.02em] text-balance text-zinc-900 uppercase dark:text-white">{{ __('Welcome back') }}</h1>
            <p class="text-sm leading-relaxed text-pretty text-zinc-500 dark:text-zinc-400">{{ __('Sign in with your work email to continue.') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="you@company.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 end-0 text-xs" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox class="mt-1" name="remember" :label="__('Keep me signed in')" :checked="old('remember')" />

            <button type="submit" class="group mt-1 flex h-12 w-full items-center justify-between rounded-full bg-brand-500 py-1.5 ps-6 pe-1.5 font-medium text-white shadow-[0_14px_30px_-12px_rgb(49_81_254/0.7)] transition duration-500 ease-[cubic-bezier(0.32,0.72,0,1)] hover:bg-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 active:scale-[0.98]" data-test="login-button">
                {{ __('Sign in') }}
                <span class="flex size-9 items-center justify-center rounded-full bg-white/15 transition duration-500 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-0.5 group-hover:scale-105">
                    <flux:icon.arrow-right variant="micro" />
                </span>
            </button>
        </form>
    </div>
</x-layouts::auth>
