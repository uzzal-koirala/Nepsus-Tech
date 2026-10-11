<x-layouts::auth :title="__('Log in')">
    <div class="stagger flex flex-col gap-5">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.03em] text-balance text-zinc-900 dark:text-white">{{ __('Welcome back') }}</h1>
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

            <flux:button variant="primary" type="submit" class="h-11 w-full shadow-lg shadow-brand-500/25 transition active:scale-[0.98]" data-test="login-button">
                {{ __('Sign in') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
