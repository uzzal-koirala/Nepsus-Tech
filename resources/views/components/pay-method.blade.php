@props(['model' => 'payMethod'])

{{-- How a salary was paid: four tiles, one picked. Binds to the Livewire property in $model. --}}
<div class="grid gap-2" data-test="pay-method">
    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Payment method') }}</span>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="{{ __('Payment method') }}">
        @foreach (\App\Models\SalaryPayment::METHODS as $key => [$label, $icon])
            <label class="cursor-pointer">
                <input type="radio" wire:model="{{ $model }}" value="{{ $key }}" class="peer sr-only">
                <span class="flex items-center justify-center gap-1.5 rounded-xl px-2 py-2.5 text-[0.8125rem] font-medium whitespace-nowrap text-zinc-600 ring-1 ring-zinc-200 transition peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-checked:ring-2 peer-checked:ring-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-300 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-white/10 dark:peer-checked:bg-brand-500/15 dark:peer-checked:text-brand-200 dark:hover:bg-white/5">
                    <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0" />{{ __($label) }}
                </span>
            </label>
        @endforeach
    </div>
    <flux:error :name="$model" />
</div>
