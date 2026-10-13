<?php

use App\Models\Setting;
use App\Support\Ezviz;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cameras')] class extends Component {
    public string $region = 'https://open.ezvizlife.com';
    public string $appKey = '';
    public string $secret = '';

    /** Result of "Test connection": ['ok' => bool, 'text' => string]. */
    public ?array $test = null;

    public function mount(): void
    {
        $this->region = (string) Setting::get('ezviz_region', 'https://open.ezvizlife.com');
        $this->appKey = (string) Setting::get('ezviz_app_key', '');
    }

    public function hasSecret(): bool
    {
        return filled(Setting::get('ezviz_secret'));
    }

    private function rules(): array
    {
        return [
            'region' => ['required', Rule::in(array_keys(Ezviz::REGIONS))],
            'appKey' => ['required', 'string', 'max:64'],
            'secret' => [$this->hasSecret() ? 'nullable' : 'required', 'string', 'max:128'],
        ];
    }

    public function save(): void
    {
        $this->validate($this->rules(), attributes: ['appKey' => 'AppKey', 'secret' => 'Secret']);

        Setting::put(array_filter([
            'ezviz_region' => $this->region,
            'ezviz_app_key' => trim($this->appKey),
            'ezviz_secret' => $this->secret !== '' ? Crypt::encryptString(trim($this->secret)) : null,
        ], fn ($v) => $v !== null));
        Cache::forget('ezviz.cameras');

        $this->reset('secret');
        Flux::toast(variant: 'success', text: __('EZVIZ account saved.'));
    }

    public function testConnection(): void
    {
        $this->validate($this->rules(), attributes: ['appKey' => 'AppKey', 'secret' => 'Secret']);
        $secret = $this->secret !== '' ? trim($this->secret) : Crypt::decryptString((string) Setting::get('ezviz_secret'));

        try {
            $cameras = (new Ezviz(trim($this->appKey), $secret, $this->region))->cameras();
            $online = collect($cameras)->where('online', true)->count();
            $this->test = ['ok' => true, 'text' => trans_choice('Connected. :count camera found|Connected. :count cameras found', count($cameras)).' · '.__(':online online', ['online' => $online])];
        } catch (\Throwable $e) {
            $this->test = ['ok' => false, 'text' => $e instanceof \RuntimeException && $e->getCode() ? $e->getMessage() : __('Could not reach EZVIZ. Check the internet connection and the region.')];
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Cameras')" :subheading="__('Connect your EZVIZ account to watch your CCTV cameras in the app.')">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div class="flex gap-4 rounded-2xl bg-zinc-50 p-4 dark:bg-white/[0.03]">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.video-camera variant="mini" class="size-5" /></span>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">
                    <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Where to get the keys') }}</p>
                    <ol class="mt-1 list-decimal space-y-0.5 ps-4">
                        <li>{{ __('Sign in at open.ezvizlife.com with your EZVIZ app account.') }}</li>
                        <li>{{ __('Create an application (Developer → My application).') }}</li>
                        <li>{{ __('Copy its AppKey and Secret here.') }}</li>
                    </ol>
                    <p class="mt-2 text-xs text-zinc-500">{{ __('Tip: turn off "Video encryption" for each camera in the EZVIZ app so it can play here.') }}</p>
                </div>
            </div>

            <flux:select wire:model="region" :label="__('Region')">
                @foreach (Ezviz::REGIONS as $url => $label)
                    <flux:select.option :value="$url">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="appKey" label="AppKey" icon="key" placeholder="e.g. 2b7c1d0e9f..." data-test="ezviz-key" />
            <flux:input wire:model="secret" type="password" label="Secret" icon="lock-closed" viewable :placeholder="$this->hasSecret() ? __('Saved, leave blank to keep') : __('Application secret')" data-test="ezviz-secret" />

            @if ($test)
                <div @class(['flex items-start gap-3 rounded-xl px-4 py-3 text-sm', 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20' => $test['ok'], 'bg-rose-50 text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20' => ! $test['ok']]) data-test="ezviz-test-result">
                    <flux:icon :icon="$test['ok'] ? 'check-circle' : 'exclamation-triangle'" variant="mini" class="mt-0.5 size-5 shrink-0" />
                    <span>{{ $test['text'] }}</span>
                </div>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                @if ($test['ok'] ?? false)
                    <flux:button icon="video-camera" :href="route('cameras.index')" wire:navigate>{{ __('Open cameras') }}</flux:button>
                @endif
                <flux:button icon="signal" wire:click="testConnection" wire:target="testConnection" data-test="ezviz-test">{{ __('Test connection') }}</flux:button>
                <flux:button type="submit" variant="primary" icon="check" data-test="ezviz-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
