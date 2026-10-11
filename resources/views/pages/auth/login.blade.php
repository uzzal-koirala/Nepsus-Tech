<x-layouts::auth :title="__('Log in')">
    <div class="stagger flex flex-col gap-7">
        <div class="flex flex-col gap-2">
            <h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.03em] text-balance text-zinc-900 dark:text-white">{{ __('Welcome back') }}</h1>
            <p class="text-sm leading-relaxed text-pretty text-zinc-500 dark:text-zinc-400">{{ __('Sign in with your work email to pick up where your team left off.') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
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
            <div class="flex flex-col gap-2">
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
                    <flux:link class="self-end text-xs" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Keep me signed in')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="h-11 w-full shadow-lg shadow-brand-500/25 transition active:scale-[0.98]" data-test="login-button">
                {{ __('Sign in') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
