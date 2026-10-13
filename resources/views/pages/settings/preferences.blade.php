<?php

use App\Models\Setting;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferences')] class extends Component {
    public const CURRENCIES = ['NPR' => 'Nepalese rupee', 'INR' => 'Indian rupee', 'USD' => 'US dollar', 'AUD' => 'Australian dollar', 'GBP' => 'British pound', 'EUR' => 'Euro'];

    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public string $currency = 'NPR';
    public string $remindDays = '7';
    public string $weeklyHoliday = '6';

    public function mount(): void
    {
        $this->currency = (string) Setting::get('currency');
        $this->remindDays = (string) Setting::get('remind_days');
        $this->weeklyHoliday = (string) Setting::get('weekly_holiday');
    }

    public function save(): void
    {
        $data = $this->validate([
            'currency' => ['required', Rule::in(array_keys(self::CURRENCIES))],
            'remindDays' => ['required', 'integer', 'min:0', 'max:60'],
            'weeklyHoliday' => ['required', 'integer', 'min:0', 'max:6'],
        ], attributes: ['remindDays' => __('reminder days'), 'weeklyHoliday' => __('weekly holiday')]);

        Setting::put([
            'currency' => $data['currency'],
            'remind_days' => $data['remindDays'],
            'weekly_holiday' => $data['weeklyHoliday'],
        ]);
        config(['app.currency' => $data['currency']]);

        Flux::toast(variant: 'success', text: __('Preferences saved.'));
    }
}; ?>

@php
    $tile = 'flex size-10 shrink-0 items-center justify-center rounded-xl';
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Preferences')" :subheading="__('How money, reminders and attendance work across the app.')">
        <form wire:submit="save" class="flex flex-col divide-y divide-zinc-100 dark:divide-white/5">
            {{-- Currency --}}
            <div class="flex flex-col gap-4 pb-7 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex gap-4">
                    <span class="{{ $tile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.banknotes variant="mini" class="size-5" /></span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Currency') }}</p>
                        <p class="mt-0.5 max-w-sm text-sm text-zinc-500">{{ __('Used for every price, payment, salary and report.') }}</p>
                    </div>
                </div>
                <flux:select wire:model="currency" class="sm:w-60">
                    @foreach (self::CURRENCIES as $code => $name)
                        <flux:select.option :value="$code">{{ $code }} · {{ __($name) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            {{-- Reminders --}}
            <div class="flex flex-col gap-4 py-7 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex gap-4">
                    <span class="{{ $tile }} bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300"><flux:icon.bell-alert variant="mini" class="size-5" /></span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Payment reminders') }}</p>
                        <p class="mt-0.5 max-w-sm text-sm text-zinc-500">{{ __('Client cards and profiles show "Due soon" this many days before a payment is due.') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:w-60">
                    <flux:input wire:model="remindDays" type="number" min="0" max="60" step="1" class="w-24" />
                    <span class="text-sm whitespace-nowrap text-zinc-500">{{ __('days before') }}</span>
                </div>
            </div>
            <flux:error name="remindDays" class="-mt-5 pb-4" />

            {{-- Weekly holiday --}}
            <div class="flex flex-col gap-4 py-7">
                <div class="flex gap-4">
                    <span class="{{ $tile }} bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.sparkles variant="mini" class="size-5" /></span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Weekly holiday') }}</p>
                        <p class="mt-0.5 max-w-md text-sm text-zinc-500">{{ __('This day is a holiday on every employee attendance calendar and is not counted in attendance.') }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-1.5 sm:ms-14 sm:grid-cols-7">
                    @foreach (self::DAYS as $i => $day)
                        <button type="button" @click="$wire.weeklyHoliday = '{{ $i }}'" class="flex flex-col items-center rounded-xl py-2.5 text-xs font-semibold ring-1 transition" :class="$wire.weeklyHoliday == '{{ $i }}' ? 'bg-rose-500 text-white ring-rose-500 shadow-md shadow-rose-500/30' : 'text-zinc-600 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:hover:bg-white/5'" data-test="holiday-{{ $i }}">
                            <span class="text-[0.6875rem] font-medium opacity-75">{{ mb_substr(__($day), 0, 3) }}</span>
                            <span>{{ __($day) }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Calendar --}}
            <div class="flex gap-4 py-7">
                <span class="{{ $tile }} bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.calendar-days variant="mini" class="size-5" /></span>
                <div>
                    <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Calendar') }}</p>
                    <p class="mt-0.5 text-sm text-zinc-500">{{ __('Dates are shown in the Nepali calendar (Bikram Sambat). Today is :date.', ['date' => today()->toNepali()]) }}</p>
                    <p class="mt-1 text-xs text-zinc-400">{{ __('Time zone: :tz', ['tz' => config('app.timezone')]) }}</p>
                </div>
            </div>

            <div class="flex justify-end pt-6">
                <flux:button type="submit" variant="primary" icon="check" data-test="save-preferences">{{ __('Save preferences') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
