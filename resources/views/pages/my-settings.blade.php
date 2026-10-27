<?php

use App\Concerns\ProfileValidationRules;
use App\Models\Attendance;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Employee logins: their own photo, login, personal details and theme. Work details are read only; admins set them. */
new #[Title('My settings')] class extends Component {
    use ProfileValidationRules, WithFileUploads;

    public $photo = null;

    // Login
    public string $name = '';

    public string $email = '';

    // Personal details
    public string $phone = '';

    public string $birthday = '';

    public string $bloodGroup = '';

    public string $address = '';

    public string $emergencyName = '';

    public string $emergencyRelation = '';

    public string $emergencyPhone = '';

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public function mount(): void
    {
        $user = auth()->user();
        [$this->name, $this->email] = [$user->name, $user->email];

        if ($e = $this->me) {
            $this->phone = (string) $e->phone;
            $this->birthday = (string) $e->date_of_birth?->toDateString();
            $this->bloodGroup = (string) $e->blood_group;
            $this->address = (string) $e->current_address;
            $this->emergencyName = (string) $e->emergency_name;
            $this->emergencyRelation = (string) $e->emergency_relation;
            $this->emergencyPhone = (string) $e->emergency_phone;
        }
    }

    #[Computed]
    public function me()
    {
        return auth()->user()->employee;
    }

    public function updatedPhoto(): void
    {
        abort_unless($this->me, 403);
        $this->validateOnly('photo', ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']], attributes: ['photo' => __('photo')]);

        $this->me->avatar_path && Storage::disk('public')->delete($this->me->avatar_path);
        $this->me->update(['avatar_path' => $this->photo->store('employees/avatars', 'public')]);
        $this->reset('photo');
        Flux::toast(variant: 'success', text: __('Photo updated.'));
    }

    public function removePhoto(): void
    {
        abort_unless($this->me, 403);
        $this->me->avatar_path && Storage::disk('public')->delete($this->me->avatar_path);
        $this->me->update(['avatar_path' => null]);
        Flux::toast(text: __('Photo removed.'));
    }

    public function saveAccount(): void
    {
        $user = auth()->user();
        $user->fill($this->validate($this->profileRules($user->id)));
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();
        Flux::toast(variant: 'success', text: __('Login details saved.'));
    }

    public function savePersonal(): void
    {
        abort_unless($this->me, 403);
        $data = $this->validate([
            'phone' => ['nullable', 'string', 'max:50'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'bloodGroup' => ['nullable', 'in:'.implode(',', self::BLOOD_GROUPS)],
            'address' => ['nullable', 'string', 'max:255'],
            'emergencyName' => ['nullable', 'string', 'max:255'],
            'emergencyRelation' => ['nullable', 'string', 'max:100'],
            'emergencyPhone' => ['nullable', 'string', 'max:50'],
        ], attributes: ['birthday' => __('birthday'), 'bloodGroup' => __('blood group'), 'emergencyPhone' => __('emergency phone')]);

        $this->me->update([
            'phone' => trim($data['phone']) ?: null,
            'date_of_birth' => $data['birthday'] ?: null,
            'blood_group' => $data['bloodGroup'] ?: null,
            'current_address' => trim($data['address']) ?: null,
            'emergency_name' => trim($data['emergencyName']) ?: null,
            'emergency_relation' => trim($data['emergencyRelation']) ?: null,
            'emergency_phone' => trim($data['emergencyPhone']) ?: null,
        ]);
        Flux::toast(variant: 'success', text: __('Personal details saved.'));
    }
}; ?>

@php
    $panel = 'rounded-2xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $me = $this->me;
    $user = auth()->user();
    $head = 'flex items-center gap-3 border-b border-zinc-100 px-5 py-4 sm:px-6 dark:border-white/5';
    $tile = 'flex size-9 shrink-0 items-center justify-center rounded-lg text-white shadow-md';
    $next = $me?->nextSalaryDate();
@endphp

<div class="stagger flex w-full flex-col gap-4 sm:gap-6">
    {{-- Who I am --}}
    <section class="{{ $panel }} overflow-hidden">
        <div class="grain relative h-28 bg-brand-500 sm:h-36">
            <div class="absolute inset-0 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_right,black,transparent_75%)]"></div>
            <div class="absolute -top-20 right-10 size-56 rounded-full bg-white/10"></div>
            <div class="absolute -right-10 -bottom-24 size-56 rounded-full bg-sun/50 blur-2xl"></div>
        </div>
        <div class="relative flex flex-col gap-4 px-5 pb-5 sm:flex-row sm:items-end sm:gap-6 sm:px-8 sm:pb-6">
            <div class="relative -mt-12 w-fit sm:-mt-14">
                <span class="flex size-24 items-center justify-center overflow-hidden rounded-2xl bg-brand-100 text-3xl font-semibold text-brand-700 shadow-lg ring-4 ring-white sm:size-28 dark:bg-brand-500/30 dark:text-white dark:ring-zinc-950">
                    @if ($url = $me?->avatarUrl())<img src="{{ $url }}" alt="" class="size-full object-cover">@else{{ $user->initials() }}@endif
                </span>
                @if ($me)
                    <label class="absolute -right-2 -bottom-2 flex size-9 cursor-pointer items-center justify-center rounded-full bg-zinc-900 text-white shadow-lg ring-4 ring-white transition hover:bg-brand-600 dark:bg-white dark:text-zinc-900 dark:ring-zinc-950" title="{{ __('Change photo') }}" data-test="change-photo">
                        <input type="file" wire:model="photo" accept="image/png,image/jpeg,image/webp" class="sr-only">
                        <span wire:loading.remove wire:target="photo"><flux:icon.camera variant="mini" class="size-4" /></span>
                        <span wire:loading wire:target="photo"><flux:icon.arrow-path variant="mini" class="size-4 animate-spin" /></span>
                    </label>
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-2xl dark:text-white">{{ $user->name }}</h1>
                <p class="mt-0.5 truncate text-sm text-zinc-500">{{ $user->email }}</p>
                @if ($me)
                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><flux:icon :icon="$me->departmentMeta()['icon']" variant="micro" class="size-3.5" />{{ __($me->departmentMeta()['label']) }}</span>
                        @if ($me->designation)<span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 dark:bg-white/10 dark:text-zinc-200"><flux:icon.briefcase variant="micro" class="size-3.5" />{{ $me->designation }}</span>@endif
                        @if ($me->tenure())<span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-200"><flux:icon.sparkles variant="micro" class="size-3.5" />{{ preg_match('/\d/', $me->tenure()) ? __('With us :time', ['time' => $me->tenure()]) : ($me->tenure() === __('New') ? __('New here') : $me->tenure()) }}</span>@endif
                    </div>
                @endif
            </div>
            @if ($me?->avatar_path)
                <flux:button size="sm" variant="ghost" icon="trash" class="self-start text-zinc-500! sm:self-end" wire:click="removePhoto" wire:confirm="{{ __('Remove your photo?') }}">{{ __('Remove photo') }}</flux:button>
            @endif
        </div>
        <flux:error name="photo" class="px-5 pb-4 sm:px-8" />
    </section>

    @unless ($me)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">{{ __('Your login is not linked to an employee profile yet, so only your login details show here. Ask an admin to link it.') }}</p>
    @endunless

    <div class="grid items-start gap-4 sm:gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex min-w-0 flex-col gap-4 sm:gap-6">
            {{-- Login --}}
            <form wire:submit="saveAccount" class="{{ $panel }}" data-test="account-form">
                <div class="{{ $head }}">
                    <span class="{{ $tile }} bg-brand-500 shadow-brand-500/30"><flux:icon.user-circle variant="mini" class="size-4.5" /></span>
                    <div>
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Login') }}</h2>
                        <p class="text-xs text-zinc-500">{{ __('The name everyone sees and the email you sign in with') }}</p>
                    </div>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                    <flux:input wire:model="name" :label="__('Name')" required autocomplete="name" />
                    <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />
                </div>
                <div class="flex justify-end border-t border-zinc-100 px-5 py-3 sm:px-6 dark:border-white/5">
                    <flux:button type="submit" variant="primary" data-test="save-account">{{ __('Save') }}</flux:button>
                </div>
            </form>

            {{-- Personal details --}}
            @if ($me)
                <form wire:submit="savePersonal" class="{{ $panel }}" data-test="personal-form">
                    <div class="{{ $head }}">
                        <span class="{{ $tile }} bg-violet-500 shadow-violet-500/30"><flux:icon.identification variant="mini" class="size-4.5" /></span>
                        <div>
                            <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Personal details') }}</h2>
                            <p class="text-xs text-zinc-500">{{ __('So the office can reach you, and someone close to you in an emergency') }}</p>
                        </div>
                    </div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                        <flux:input wire:model="phone" :label="__('Phone')" type="tel" icon="phone" placeholder="98XXXXXXXX" />
                        <x-nepali-date wire:model="birthday" :label="__('Birthday')" align="end" />
                        <flux:select wire:model="bloodGroup" :label="__('Blood group')">
                            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                            @foreach ($this::BLOOD_GROUPS as $bg)
                                <flux:select.option value="{{ $bg }}">{{ $bg }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="address" :label="__('Where you live')" icon="map-pin" :placeholder="__('e.g. Baneshwor, Kathmandu')" />
                    </div>
                    <div class="mx-5 mb-5 rounded-xl bg-rose-50/60 p-4 ring-1 ring-rose-100 sm:mx-6 sm:mb-6 dark:bg-rose-500/[0.06] dark:ring-rose-500/15">
                        <p class="mb-3 flex items-center gap-2 text-sm font-semibold text-rose-800 dark:text-rose-200"><flux:icon.heart variant="mini" class="size-4" />{{ __('Emergency contact') }}</p>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:input wire:model="emergencyName" :label="__('Name')" />
                            <flux:input wire:model="emergencyRelation" :label="__('Relation')" :placeholder="__('e.g. Mother')" />
                            <flux:input wire:model="emergencyPhone" :label="__('Phone')" type="tel" />
                        </div>
                    </div>
                    <div class="flex justify-end border-t border-zinc-100 px-5 py-3 sm:px-6 dark:border-white/5">
                        <flux:button type="submit" variant="primary" data-test="save-personal">{{ __('Save details') }}</flux:button>
                    </div>
                </form>
            @endif

            {{-- Theme --}}
            <section class="{{ $panel }}">
                <div class="{{ $head }}">
                    <span class="{{ $tile }} bg-amber-500 shadow-amber-500/30"><flux:icon.paint-brush variant="mini" class="size-4.5" /></span>
                    <div>
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Theme') }}</h2>
                        <p class="text-xs text-zinc-500">{{ __('How the app looks on this device') }}</p>
                    </div>
                </div>
                <div x-data class="grid grid-cols-3 gap-2 p-5 sm:gap-3 sm:p-6">
                    @foreach (['light' => ['Light', 'sun', 'bg-white', 'bg-zinc-100'], 'dark' => ['Dark', 'moon', 'bg-zinc-900', 'bg-zinc-700'], 'system' => ['System', 'computer-desktop', 'bg-linear-to-r from-white from-50% to-zinc-900 to-50%', 'bg-zinc-300']] as $mode => [$label, $icon, $screen, $bar])
                        <button type="button" @click="$flux.appearance = '{{ $mode }}'" class="overflow-hidden rounded-xl text-start ring-1 transition" :class="$flux.appearance === '{{ $mode }}' ? 'ring-2 ring-brand-500 shadow-lg shadow-brand-500/15' : 'ring-zinc-200 hover:ring-zinc-300 dark:ring-white/10'">
                            <div class="{{ $screen }} flex h-14 gap-1.5 border-b border-zinc-200/70 p-2 sm:h-20 dark:border-white/10">
                                <div class="{{ $bar }} w-1/4 rounded opacity-80"></div>
                                <div class="flex flex-1 flex-col gap-1"><div class="h-2 w-3/4 rounded bg-brand-500"></div><div class="{{ $bar }} h-1.5 rounded opacity-70"></div></div>
                            </div>
                            <div class="flex items-center gap-1.5 bg-white px-2.5 py-2 dark:bg-zinc-950">
                                <flux:icon :icon="$icon" variant="micro" class="size-3.5 shrink-0 text-zinc-500" />
                                <span class="truncate text-xs font-semibold text-zinc-800 dark:text-zinc-100">{{ __($label) }}</span>
                            </div>
                        </button>
                    @endforeach
                </div>
            </section>
        </div>

        <aside class="flex min-w-0 flex-col gap-4 sm:gap-6">
            {{-- At work: set by admins --}}
            @if ($me)
                <section class="{{ $panel }}" data-test="at-work">
                    <div class="{{ $head }}">
                        <span class="{{ $tile }} bg-emerald-500 shadow-emerald-500/30"><flux:icon.briefcase variant="mini" class="size-4.5" /></span>
                        <div>
                            <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('At work') }}</h2>
                            <p class="text-xs text-zinc-500">{{ __('Set by an admin. Ask them to change it.') }}</p>
                        </div>
                    </div>
                    <dl class="divide-y divide-zinc-100 px-5 text-sm dark:divide-white/5">
                        @foreach ([
                            ['building-office', __('Department'), __($me->departmentMeta()['label'])],
                            ['briefcase', __('Job title'), $me->designation ?: '—'],
                            ['clock', __('Work hours'), $me->workHours() ?? __('Not set')],
                            ['exclamation-circle', __('Late from'), $me->lateFrom() ?? '—'],
                            ['banknotes', __('Next salary'), $next ? $next->toNepali() : __('Not set')],
                            ['calendar-days', __('Joined'), $me->joined_on?->toNepali() ?? '—'],
                        ] as [$icon, $label, $value])
                            <div class="flex items-center gap-3 py-3">
                                <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0 text-zinc-400" />
                                <dt class="flex-1 text-zinc-500">{{ $label }}</dt>
                                <dd class="truncate text-end font-medium text-zinc-900 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            {{-- Security and leaving --}}
            <section class="{{ $panel }} overflow-hidden">
                <a href="{{ route('security.edit') }}" wire:navigate class="group flex items-center gap-3 px-5 py-4 transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                    <span class="{{ $tile }} bg-zinc-900 shadow-zinc-900/20 dark:bg-white dark:text-zinc-900"><flux:icon.shield-check variant="mini" class="size-4.5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Password & security') }}</span>
                        <span class="block truncate text-xs text-zinc-500">{{ $user->two_factor_confirmed_at ? __('Two-factor is on') : __('Change your password, turn on two-factor') }}</span>
                    </span>
                    <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-zinc-500" />
                </a>
                <form method="POST" action="{{ route('logout') }}" class="border-t border-zinc-100 dark:border-white/5">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 px-5 py-4 text-start transition hover:bg-rose-50/60 dark:hover:bg-rose-500/5" data-test="settings-logout">
                        <span class="{{ $tile }} bg-rose-500 shadow-rose-500/30"><flux:icon.arrow-right-start-on-rectangle variant="mini" class="size-4.5" /></span>
                        <span class="flex-1 text-sm font-semibold text-rose-600 dark:text-rose-400">{{ __('Log out') }}</span>
                    </button>
                </form>
            </section>
        </aside>
    </div>
</div>
