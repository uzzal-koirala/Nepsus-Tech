<?php

use App\Models\Setting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Business settings')] class extends Component {
    use WithFileUploads;

    public string $name = '';
    public string $tagline = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $website = '';
    public string $pan = '';
    public $logo;

    public function mount(): void
    {
        $this->name = (string) Setting::get('business_name');
        $this->tagline = (string) Setting::get('business_tagline');
        $this->email = (string) Setting::get('business_email');
        $this->phone = (string) Setting::get('business_phone');
        $this->address = (string) Setting::get('business_address');
        $this->website = (string) Setting::get('business_website');
        $this->pan = (string) Setting::get('business_pan');
    }

    public function updatedLogo(): void
    {
        try {
            $this->validateOnly('logo', ['logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('logo');
            throw $e;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'pan' => ['nullable', 'string', 'max:30'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], attributes: ['pan' => __('PAN / VAT number')]);

        $values = [
            'business_name' => $data['name'],
            'business_tagline' => $data['tagline'],
            'business_email' => $data['email'],
            'business_phone' => $data['phone'],
            'business_address' => $data['address'],
            'business_website' => $data['website'],
            'business_pan' => $data['pan'],
        ];

        if ($this->logo) {
            $old = Setting::get('business_logo');
            $values['business_logo'] = $this->logo->store('branding', 'public');
            if ($old) {
                Storage::disk('public')->delete($old);
            }
        }

        Setting::put($values);
        $this->reset('logo');
        Flux::toast(variant: 'success', text: __('Business details saved.'));
    }

    public function removeLogo(): void
    {
        if ($old = Setting::get('business_logo')) {
            Storage::disk('public')->delete($old);
        }
        Setting::put(['business_logo' => '']);
        Flux::toast(text: __('Logo removed. The default logo is used again.'));
    }
}; ?>

@php
    $label = 'mb-1.5 block text-sm font-medium text-zinc-800 dark:text-white';
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Business')" :subheading="__('How your business appears in the app and on exports.')">
        <form wire:submit="save" class="flex flex-col gap-8">
            {{-- Logo --}}
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <div class="relative">
                    <img src="{{ $logo && ! $errors->has('logo') ? $logo->temporaryUrl() : Setting::logoUrl() }}" alt="{{ __('Logo') }}" class="size-24 rounded-2xl object-cover shadow-md ring-4 ring-white dark:ring-zinc-900">
                    <div wire:loading wire:target="logo" class="absolute inset-0 flex items-center justify-center rounded-2xl bg-white/70 dark:bg-zinc-900/70"><flux:icon.arrow-path class="size-5 animate-spin text-brand-500" /></div>
                </div>
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Logo') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('Square PNG, JPG or WebP, up to 2 MB. Shown in the sidebar.') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 shadow-xs ring-1 ring-zinc-200 transition hover:bg-zinc-50 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10">
                            <flux:icon.arrow-up-tray variant="micro" class="size-4" />{{ __('Upload new') }}
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="sr-only" data-test="logo-input">
                        </label>
                        @if (Setting::get('business_logo'))
                            <button type="button" wire:click="removeLogo" wire:confirm="{{ __('Remove the logo?') }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">{{ __('Remove') }}</button>
                        @endif
                    </div>
                    <flux:error name="logo" />
                </div>
            </div>

            <div class="h-px bg-zinc-100 dark:bg-white/5"></div>

            {{-- Identity --}}
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Business name')" icon="building-office-2" required />
                <flux:input wire:model="tagline" :label="__('Tagline')" :placeholder="__('e.g. Digital marketing agency')" icon="sparkles" />
                <flux:input wire:model="pan" :label="__('PAN / VAT number')" :placeholder="__('e.g. 609876543')" icon="identification" />
                <flux:input wire:model="website" type="url" :label="__('Website')" placeholder="https://nepsustech.com" icon="globe-alt" />
            </div>

            <div class="h-px bg-zinc-100 dark:bg-white/5"></div>

            {{-- Contact --}}
            <div>
                <p class="mb-4 text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Contact') }}</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <flux:input wire:model="email" type="email" :label="__('Email')" placeholder="hello@nepsustech.com" icon="envelope" />
                    <flux:input wire:model="phone" type="tel" :label="__('Phone')" placeholder="98XXXXXXXX" icon="phone" />
                    <flux:textarea wire:model="address" :label="__('Address')" :placeholder="__('e.g. New Baneshwor, Kathmandu')" rows="2" class="sm:col-span-2" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-zinc-100 pt-6 dark:border-white/5">
                <flux:button type="submit" variant="primary" icon="check" data-test="save-business">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
