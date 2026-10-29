<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Support\AttendanceDevice;
use App\Support\AttendancePunches;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Attendance device')] class extends Component {
    use WithFileUploads;

    public string $host = '';
    public string $username = 'admin';
    public string $password = '';
    public bool $autoSync = false;

    /** Result of the last "Test connection": ['ok' => bool, 'text' => string]. */
    public ?array $test = null;

    /** @var array<int, string> employee id => device ID */
    public array $ids = [];

    public $file = null;
    public ?array $imported = null;

    public function mount(): void
    {
        $this->host = (string) Setting::get('device_host', '');
        $this->username = (string) Setting::get('device_username', 'admin');
        $this->autoSync = Setting::get('device_auto_sync') === '1';
        $this->ids = $this->employees->mapWithKeys(fn ($e) => [$e->id => (string) $e->device_user_id])->all();
    }

    #[Computed]
    public function employees()
    {
        return Employee::where('status', '!=', 'inactive')->orderBy('name')->get();
    }

    #[Computed]
    public function hasPassword(): bool
    {
        return filled(Setting::get('device_password'));
    }

    #[Computed]
    public function hasKey(): bool
    {
        return filled(Setting::get('device_push_token'));
    }

    /** Key the office PC helper sends with every upload. */
    public function createKey(): void
    {
        Setting::put(['device_push_token' => Str::random(40)]);
        unset($this->hasKey);
        Flux::toast(variant: 'success', text: __('Secret key ready. Download the helper on the office PC.'));
    }

    private function connectionRules(): array
    {
        return [
            'host' => ['required', 'string', 'max:120', 'regex:/^(https?:\/\/)?[A-Za-z0-9.\-]+(:\d{1,5})?\/?$/'],
            'username' => ['required', 'string', 'max:64'],
            'password' => [$this->hasPassword ? 'nullable' : 'required', 'string', 'max:128'],
        ];
    }

    public function saveConnection(): void
    {
        $this->validate($this->connectionRules(), ['host.regex' => __('Enter the device IP address, like 192.168.1.64.')], ['host' => __('device address')]);

        Setting::put(array_filter([
            'device_host' => trim($this->host),
            'device_username' => trim($this->username),
            'device_password' => $this->password !== '' ? Crypt::encryptString($this->password) : null,
            'device_auto_sync' => $this->autoSync ? '1' : '0',
        ], fn ($v) => $v !== null));

        $this->reset('password');
        unset($this->hasPassword);
        Flux::toast(variant: 'success', text: __('Device settings saved.'));
    }

    public function testConnection(): void
    {
        $this->validate($this->connectionRules(), ['host.regex' => __('Enter the device IP address, like 192.168.1.64.')], ['host' => __('device address')]);
        $password = $this->password !== '' ? $this->password : Crypt::decryptString((string) Setting::get('device_password'));

        try {
            $info = (new AttendanceDevice(trim($this->host), trim($this->username), $password))->info();
            $this->test = ['ok' => true, 'text' => __('Connected to :model', ['model' => $info['model'] ?: $info['name'] ?: __('the device')]).($info['serial'] ? ' · '.$info['serial'] : '')];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $this->test = ['ok' => false, 'text' => $e->response->status() === 401 ? $this->wrongLogin($e->response->body()) : __('The device answered with an error (:code). It may not support network sync; use file import below.', ['code' => $e->response->status()])];
        } catch (\Throwable) {
            // The hosted website is outside the office network; only the office PC helper can reach the device.
            $this->test = ['ok' => false, 'text' => app()->isProduction()
                ? __('This website runs online, so it cannot reach a device inside the office network. Use step 4: install the helper on an office PC.')
                : __('Could not reach the device. Check the IP address and that this PC is on the same network.')];
        }
    }

    /** Hikvision says how many tries are left before it locks the account; pass that on. */
    private function wrongLogin(string $body): string
    {
        $xml = @simplexml_load_string($body);
        if ($xml && (string) $xml->lockStatus === 'lock') {
            return __('The device has locked this account after too many wrong passwords. Wait :min minutes, then try the correct password.', ['min' => max(1, (int) ceil((int) $xml->unlockTime / 60))]);
        }
        $left = $xml ? (int) $xml->retryLoginTime : 0;

        return $left > 0
            ? trans_choice('The device answered, but the username or password is wrong. :count try left before the device locks the account.|The device answered, but the username or password is wrong. :count tries left before the device locks the account.', $left)
            : __('The device answered, but the username or password is wrong.');
    }

    /** Set while waiting for the office PC helper to answer a "Sync now". */
    public ?string $waitingSince = null;

    /**
     * Sync right away: read the device directly when this app runs inside the office,
     * otherwise (the hosted website) ask the office PC helper and wait for it.
     */
    public function syncNow(): void
    {
        $this->waitingSince = null;
        // The hosted website is outside the office network: reaching the device only times out.
        $hosted = app()->isProduction();

        if (! $hosted && AttendanceDevice::fromSettings()) {
            try {
                $r = AttendancePunches::syncFromDevice(7);
                Flux::toast(variant: 'success', text: trans_choice(':count day marked present from the device.|:count days marked present from the device.', $r['marked']));

                return;
            } catch (\Throwable $e) {
                if (! $this->hasKey) {
                    Flux::toast(variant: 'danger', text: __('Sync failed: :error', ['error' => Str::limit(AttendancePunches::readableError($e), 160)]));

                    return;
                }
            }
        }

        if (! $this->hasKey) {
            Flux::toast(variant: 'warning', text: $hosted
                ? __('This website cannot reach the device inside the office. Download the helper in step 4 and install it on an office PC.')
                : __('Connect the device in step 1, or set up the office PC helper in step 4.'));

            return;
        }

        $this->waitingSince = now()->toIso8601String();
        Setting::put(['device_sync_requested' => $this->waitingSince]);
    }

    /** Polled while waiting: done once the helper has sent punches after the request. */
    public function checkHelper(): void
    {
        if (! $this->waitingSince) {
            return;
        }

        $since = Carbon::parse($this->waitingSince);
        $seen = Setting::get('device_helper_seen');

        if ($seen && Carbon::parse($seen)->gte($since)) {
            $this->waitingSince = null;
            $r = json_decode((string) Setting::get('device_last_result'), true) ?? ['marked' => 0];
            Flux::toast(variant: 'success', text: trans_choice(':count day marked present from the office PC.|:count days marked present from the office PC.', $r['marked']));
        } elseif ($since->diffInSeconds(now()) > 150) {
            $this->waitingSince = null;
            Flux::toast(variant: 'danger', text: __('The office PC did not answer. Check that it is on and the helper is installed.'));
        }
    }

    public function saveIds(): void
    {
        $this->validate(
            collect($this->ids)->mapWithKeys(fn ($v, $id) => ["ids.$id" => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('employees', 'device_user_id')->ignore($id)]])->all(),
            ['ids.*.unique' => __('Another employee already has this ID.'), 'ids.*.regex' => __('Use only letters and numbers.')],
        );

        $seen = [];
        foreach ($this->ids as $id => $deviceId) {
            $norm = AttendancePunches::normalise((string) $deviceId);
            if ($deviceId !== '' && isset($seen[$norm])) {
                $this->addError("ids.$id", __('Another employee already has this ID.'));

                return;
            }
            $seen[$norm] = true;
        }

        foreach ($this->ids as $id => $deviceId) {
            Employee::whereKey($id)->update(['device_user_id' => trim((string) $deviceId) ?: null]);
        }

        Flux::toast(variant: 'success', text: __('Device IDs saved.'));
    }

    public function import(): void
    {
        $this->validate(['file' => ['required', 'file', 'max:10240', 'extensions:csv,txt,xlsx']], ['file.extensions' => __('Use a .csv or .xlsx file. Old .xls files: open in Excel and save as .xlsx or .csv.')], ['file' => __('file')]);

        try {
            $this->imported = AttendancePunches::apply(AttendancePunches::fromFile($this->file->getRealPath(), $this->file->getClientOriginalExtension()));
            Flux::toast(variant: 'success', text: trans_choice(':count day marked present.|:count days marked present.', $this->imported['marked']));
        } catch (\RuntimeException $e) {
            $this->addError('file', $e->getMessage());
        }

        $this->reset('file');
    }
}; ?>

@php
    $tile = 'flex size-10 shrink-0 items-center justify-center rounded-xl';
    $step = 'flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white shadow-md shadow-brand-500/30';
    $lastSync = Setting::get('device_last_sync');
    $lastError = Setting::get('device_last_error');
    $lastResult = json_decode((string) Setting::get('device_last_result'), true);
    $unknown = collect(array_merge($lastResult['unknown'] ?? [], $imported['unknown'] ?? []))->unique()->values();
    $linked = collect($ids)->filter()->count();
    $helperSeen = Setting::get('device_helper_seen');
    $helperComputer = Setting::get('device_helper_computer');
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Attendance device')" :subheading="__('Connect the Hikvision fingerprint machine so punches mark attendance automatically.')" :wide="true">
        <div class="flex flex-col gap-8">
            {{-- How it works --}}
            <div class="flex flex-col gap-5 rounded-2xl bg-linear-to-br from-brand-500 to-brand-600 p-5 text-white">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/25"><flux:icon.finger-print class="size-8" /></span>
                    <div class="flex-1">
                        <p class="font-semibold">{{ __('Hikvision attendance terminal') }}</p>
                        <p class="mt-0.5 text-sm text-brand-100">{{ __('When someone punches in, their day turns Present with the check-in time. Days you mark by hand (leave, absent) are never changed.') }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2 text-center text-xs">
                        <div class="rounded-xl bg-white/15 px-3 py-2 ring-1 ring-white/20"><p class="text-lg font-bold tabular-nums">{{ $linked }}/{{ count($ids) }}</p><p class="text-brand-100">{{ __('linked') }}</p></div>
                        <div class="rounded-xl bg-white/15 px-3 py-2 ring-1 ring-white/20"><p class="text-lg font-bold">{{ ($autoSync && $this->hasPassword) || $helperSeen ? __('On') : __('Off') }}</p><p class="text-brand-100">{{ __('auto sync') }}</p></div>
                    </div>
                </div>

                {{-- Sync right now --}}
                <div class="flex flex-col gap-3 rounded-xl bg-white/10 p-3 ring-1 ring-white/20 sm:flex-row sm:items-center">
                    <div class="flex flex-1 items-center gap-2.5 text-sm">
                        @if ($waitingSince)
                            <div wire:poll.3s="checkHelper"></div>
                            <flux:icon.loading class="size-5" />
                            <span>{{ __('Waiting for the office PC to send punches (up to a minute)...') }}</span>
                        @else
                            <flux:icon.clock variant="mini" class="size-5 text-brand-100" />
                            <span>
                                {{ $lastSync ? __('Last synced :time', ['time' => Carbon::parse($lastSync)->diffForHumans()]) : __('Not synced yet') }}
                                @if ($lastResult && ! $lastError)
                                    <span class="text-brand-100">· {{ __(':punches punches', ['punches' => $lastResult['punches']]) }}</span>
                                @endif
                            </span>
                        @endif
                    </div>
                    <button type="button" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow" @disabled($waitingSince) data-test="sync-now-top"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-brand-600 shadow-sm transition hover:bg-brand-50 disabled:cursor-wait disabled:opacity-70">
                        <flux:icon.arrow-path variant="micro" class="size-4" wire:loading.class="animate-spin" wire:target="syncNow" />
                        <span wire:loading.remove wire:target="syncNow">{{ $waitingSince ? __('Syncing...') : __('Sync now') }}</span>
                        <span wire:loading wire:target="syncNow">{{ __('Syncing...') }}</span>
                    </button>
                </div>
            </div>

            {{-- 1. Connect --}}
            <form wire:submit="saveConnection" class="flex flex-col gap-5">
                <div class="flex items-center gap-3">
                    <span class="{{ $step }}">1</span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Connect the device') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('On the device: Menu → Comm. → Network shows its IP address. Plug it into the office router with a network cable.') }}</p>
                    </div>
                </div>
                <div class="grid gap-4 sm:ms-10 sm:grid-cols-3">
                    <flux:input wire:model="host" :label="__('Device IP address')" placeholder="192.168.1.64" icon="wifi" data-test="device-host" />
                    <flux:input wire:model="username" :label="__('Username')" placeholder="admin" icon="user" />
                    <flux:input wire:model="password" type="password" :label="__('Password')" :placeholder="$this->hasPassword ? __('Saved, leave blank to keep') : __('Device admin password')" icon="lock-closed" viewable />
                </div>
                <div class="flex flex-col gap-4 sm:ms-10 sm:flex-row sm:items-center sm:justify-between">
                    <flux:switch wire:model="autoSync" :label="__('Sync automatically every 5 minutes')" align="left" />
                    <div class="flex gap-2">
                        <flux:button icon="signal" wire:click="testConnection" wire:target="testConnection" data-test="test-device">{{ __('Test connection') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon="check" data-test="save-device">{{ __('Save') }}</flux:button>
                    </div>
                </div>
                @if ($test)
                    <div @class(['flex items-start gap-3 rounded-xl px-4 py-3 text-sm sm:ms-10', 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20' => $test['ok'], 'bg-rose-50 text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20' => ! $test['ok']]) data-test="device-test-result">
                        <flux:icon :icon="$test['ok'] ? 'check-circle' : 'exclamation-triangle'" variant="mini" class="mt-0.5 size-5 shrink-0" />
                        <span>{{ $test['text'] }}</span>
                    </div>
                @endif
            </form>

            <hr class="border-zinc-100 dark:border-white/5">

            {{-- 2. Link employees --}}
            <form wire:submit="saveIds" class="flex flex-col gap-5">
                <div class="flex items-center gap-3">
                    <span class="{{ $step }}">2</span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Link employees') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Enter the ID each person has on the device (the number used when adding their fingerprint).') }}</p>
                    </div>
                </div>

                @if ($unknown->isNotEmpty())
                    <div class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 sm:ms-10 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                        <flux:icon.question-mark-circle variant="mini" class="mt-0.5 size-5 shrink-0" />
                        <span>{{ __('The device has punches from IDs not linked to anyone yet:') }} <strong class="tabular-nums">{{ $unknown->join(', ') }}</strong></span>
                    </div>
                @endif

                <div class="overflow-hidden rounded-xl ring-1 ring-zinc-200/70 sm:ms-10 dark:ring-white/10">
                    @forelse ($this->employees as $emp)
                        <div wire:key="dev-emp-{{ $emp->id }}" class="flex items-center gap-3 border-b border-zinc-100 px-4 py-2.5 last:border-0 dark:border-white/5">
                            <x-employee-avatar :employee="$emp" class="size-9 text-xs" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $emp->name }}</p>
                                <p class="truncate text-xs text-zinc-500">{{ $emp->designation ?? __('No role set') }}</p>
                            </div>
                            <div class="w-36">
                                <flux:input wire:model="ids.{{ $emp->id }}" size="sm" icon="finger-print" :placeholder="__('Device ID')" :aria-label="__('Device ID for :name', ['name' => $emp->name])" />
                            </div>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-zinc-500">{{ __('Add employees first.') }}</p>
                    @endforelse
                </div>
                @if ($this->employees->isNotEmpty())
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="check" data-test="save-ids">{{ __('Save IDs') }}</flux:button>
                    </div>
                @endif
            </form>

            <hr class="border-zinc-100 dark:border-white/5">

            {{-- 3. Sync or import --}}
            <div class="flex flex-col gap-5">
                <div class="flex items-center gap-3">
                    <span class="{{ $step }}">3</span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Bring in punches') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Sync over the network, or upload the file exported from the device.') }}</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:ms-10 lg:grid-cols-2">
                    {{-- Network sync --}}
                    <div class="flex flex-col gap-4 rounded-xl p-5 ring-1 ring-zinc-200/70 dark:ring-white/10">
                        <div class="flex items-center gap-3">
                            <span class="{{ $tile }} bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.arrow-path variant="mini" class="size-5" /></span>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Network sync') }}</p>
                                <p class="text-xs text-zinc-500">{{ __('Reads the last 7 days from the device') }}</p>
                            </div>
                        </div>
                        <div class="rounded-lg bg-zinc-50 px-3 py-2.5 text-sm dark:bg-white/[0.03]">
                            @if ($lastSync)
                                <p class="text-zinc-700 dark:text-zinc-200">{{ __('Last sync :time', ['time' => Carbon::parse($lastSync)->diffForHumans()]) }}</p>
                                @if ($lastError)
                                    <p class="mt-0.5 text-xs text-rose-600 dark:text-rose-400">{{ $lastError }}</p>
                                @elseif ($lastResult)
                                    <p class="mt-0.5 text-xs text-zinc-500">{{ __(':punches punches · :marked days marked present', ['punches' => $lastResult['punches'], 'marked' => $lastResult['marked']]) }}</p>
                                @endif
                            @else
                                <p class="text-zinc-500">{{ __('Not synced yet.') }}</p>
                            @endif
                        </div>
                        <flux:button icon="arrow-path" wire:click="syncNow" wire:target="syncNow" :disabled="(bool) $waitingSince" class="mt-auto" data-test="sync-now">{{ __('Sync now') }}</flux:button>
                    </div>

                    {{-- File import --}}
                    <form wire:submit="import" class="flex flex-col gap-4 rounded-xl p-5 ring-1 ring-zinc-200/70 dark:ring-white/10">
                        <div class="flex items-center gap-3">
                            <span class="{{ $tile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.arrow-up-tray variant="mini" class="size-5" /></span>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Import a file') }}</p>
                                <p class="text-xs text-zinc-500">{{ __('Device: Menu → Data → Export to USB (.csv or .xlsx)') }}</p>
                            </div>
                        </div>
                        <label class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border-2 border-dashed border-zinc-200 px-4 py-4 text-center transition hover:border-brand-300 hover:bg-brand-50/40 dark:border-white/10 dark:hover:bg-brand-500/5">
                            <input type="file" wire:model="file" accept=".csv,.txt,.xlsx" class="sr-only" data-test="import-file">
                            <flux:icon.document-arrow-up class="size-6 text-zinc-400" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200" wire:loading.remove wire:target="file">{{ $file ? $file->getClientOriginalName() : __('Choose attendance file') }}</span>
                            <span class="text-sm text-zinc-500" wire:loading wire:target="file">{{ __('Uploading…') }}</span>
                        </label>
                        <flux:error name="file" />
                        @if ($imported)
                            <p class="text-xs text-zinc-500" data-test="import-result">{{ __(':punches punches read · :marked days marked present · :kept hand-marked days kept', ['punches' => $imported['punches'], 'marked' => $imported['marked'], 'kept' => $imported['kept']]) }}</p>
                        @endif
                        <flux:button type="submit" variant="primary" icon="arrow-up-tray" :disabled="! $file" class="mt-auto" data-test="import-button">{{ __('Import') }}</flux:button>
                    </form>
                </div>

            </div>

            <hr class="border-zinc-100 dark:border-white/5">

            {{-- 4. Hosted online --}}
            <div class="flex flex-col gap-5" data-test="helper-section">
                <div class="flex items-center gap-3">
                    <span class="{{ $step }}">4</span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Website hosted online (Hostinger)') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('The website cannot reach the device inside the office, so a small helper on the office PC sends punches to it every 5 minutes.') }}</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:ms-10 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <ol class="flex flex-col gap-3 rounded-xl p-5 ring-1 ring-zinc-200/70 dark:ring-white/10">
                        @foreach ([
                            ['arrow-down-tray', __('Download the helper on the office PC'), __('Open this page on the hosted website from the office PC and download it there. It carries a secret key, so only it can send punches.')],
                            ['folder-open', __('Unzip it and double-click Install.bat'), __('Allow administrator access, then type the device password once.')],
                            ['check-badge', __('Done'), __('Windows sends new punches every 5 minutes, even after a restart.')],
                        ] as [$icon, $title, $hint])
                            <li class="flex gap-3">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300"><flux:icon :icon="$icon" variant="micro" class="size-4" /></span>
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $title }}</p>
                                    <p class="text-xs text-zinc-500">{{ $hint }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <div class="flex flex-col gap-3 rounded-xl bg-zinc-50 p-5 dark:bg-white/[0.03]">
                        <p class="text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Office PC helper') }}</p>
                        @if ($helperSeen)
                            <p class="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-300"><span class="size-2 rounded-full bg-emerald-500"></span>{{ __('Last sent :time', ['time' => Carbon::parse($helperSeen)->diffForHumans()]) }}</p>
                            @if ($helperComputer)
                                <p class="-mt-2 text-xs text-zinc-500">{{ __('From :pc', ['pc' => $helperComputer]) }}</p>
                            @endif
                        @else
                            <p class="text-sm text-zinc-500">{{ __('Not installed yet.') }}</p>
                        @endif

                        {{-- The download makes the secret key the first time. --}}
                        <flux:button variant="primary" icon="arrow-down-tray" :href="route('settings.attendance-device.helper')" data-test="download-helper">{{ __('Download helper') }}</flux:button>
                        @if ($this->hasKey)
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="createKey" wire:confirm="{{ __('Make a new key? A helper already installed will stop working until you install the new download.') }}">{{ __('New secret key') }}</flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
