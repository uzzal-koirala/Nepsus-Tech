<?php

use App\Models\CctvCamera;
use App\Models\Setting;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('CCTV cameras')] class extends Component {
    public string $gateway = '';

    /** @var array<string, mixed> Add / edit camera form. */
    public array $form = [];
    public ?int $editingId = null;
    public ?int $deletingId = null;

    public function mount(): void
    {
        Gate::authorize('manage-cctv');
        $this->gateway = (string) Setting::get('cctv_gateway_url', '');
    }

    #[Computed]
    public function cameras()
    {
        return CctvCamera::withCount('logs')->orderBy('position')->orderBy('id')->get();
    }

    #[Computed]
    public function users()
    {
        return User::orderByDesc('is_admin')->orderBy('name')->get();
    }

    public function saveGateway(): void
    {
        Gate::authorize('manage-cctv');
        $this->validate(['gateway' => ['nullable', 'url:http,https', 'max:200']], ['gateway.url' => __('Enter a full address like https://office-pc.your-tailnet.ts.net or http://127.0.0.1:8888')], ['gateway' => __('gateway address')]);

        Setting::put(['cctv_gateway_url' => rtrim(trim($this->gateway), '/')]);
        Flux::toast(variant: 'success', text: __('Gateway address saved.'));
    }

    public function create(): void
    {
        Gate::authorize('manage-cctv');
        $this->editingId = null;
        $this->form = ['name' => '', 'location' => '', 'stream_path' => '', 'camera_ip' => '', 'rtsp_path' => '/h264/ch1/main/av_stream', 'transcode' => true, 'enabled' => true];
        $this->resetErrorBag();
        Flux::modal('cctv-camera')->show();
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-cctv');
        $camera = CctvCamera::findOrFail($id);
        $this->editingId = $camera->id;
        $this->form = $camera->only(['name', 'stream_path', 'rtsp_path', 'transcode', 'enabled']) + ['location' => (string) $camera->location, 'camera_ip' => (string) $camera->camera_ip];
        $this->resetErrorBag();
        Flux::modal('cctv-camera')->show();
    }

    /** While adding, the stream name follows the camera name (office-entrance) until it is edited by hand. */
    public function updatingForm(mixed $value, string $key): void
    {
        if ($key === 'name' && ! $this->editingId && ($this->form['stream_path'] ?? '') === Str::slug($this->form['name'] ?? '')) {
            $this->form['stream_path'] = Str::limit(Str::slug((string) $value), 60, '');
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-cctv');
        $rules = collect(CctvCamera::rules($this->editingId))->mapWithKeys(fn ($r, $k) => ["form.$k" => $r])->all();
        $data = $this->validate($rules, [
            'form.stream_path.regex' => __('Use lowercase letters, numbers and dashes, like office-entrance.'),
            'form.rtsp_path.regex' => __('Start with / and leave out the password, like /h264/ch1/main/av_stream.'),
        ], ['form.name' => __('name'), 'form.stream_path' => __('stream name'), 'form.camera_ip' => __('camera IP'), 'form.rtsp_path' => __('RTSP path')])['form'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if ($this->editingId) {
            CctvCamera::findOrFail($this->editingId)->update($data);
        } else {
            CctvCamera::create($data + ['position' => (int) CctvCamera::max('position') + 1]);
        }

        unset($this->cameras);
        Flux::modal('cctv-camera')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Camera updated.') : __('Camera added. Download the gateway setup again so the office PC knows about it.'));
    }

    public function toggle(int $id): void
    {
        Gate::authorize('manage-cctv');
        $camera = CctvCamera::findOrFail($id);
        $camera->update(['enabled' => ! $camera->enabled]);
        unset($this->cameras);
    }

    public function move(int $id, int $step): void
    {
        Gate::authorize('manage-cctv');
        $ids = $this->cameras->pluck('id')->all();
        $i = array_search($id, $ids, true);
        $j = $i + ($step < 0 ? -1 : 1);
        if ($i === false || ! isset($ids[$j])) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $pos => $cameraId) {
            CctvCamera::whereKey($cameraId)->update(['position' => $pos + 1]);
        }
        unset($this->cameras);
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('manage-cctv');
        $this->deletingId = CctvCamera::findOrFail($id)->id;
        Flux::modal('cctv-delete')->show();
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-cctv');
        CctvCamera::findOrFail($id)->delete();
        $this->deletingId = null;
        unset($this->cameras);
        Flux::modal('cctv-delete')->close();
        Flux::toast(text: __('Camera removed. Its access log entries are kept.'));
    }

    /** Lets a staff member watch CCTV (admins always can). */
    public function toggleViewer(int $userId): void
    {
        Gate::authorize('manage-cctv');
        $user = User::findOrFail($userId);
        abort_if($user->is_admin, 422);
        $user->forceFill(['can_view_cctv' => ! $user->can_view_cctv])->save(); // not mass assignable on purpose
        unset($this->users);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $step = 'flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white shadow-md shadow-brand-500/30';
    $saved = CctvCamera::gateway();
@endphp

<div class="stagger mx-auto flex w-full max-w-7xl flex-col gap-6">
    @include('partials.cctv-tabs')

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex min-w-0 flex-col gap-6">
            {{-- Cameras --}}
            <section class="{{ $panel }} overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div>
                        <h2 class="text-lg leading-tight font-semibold text-zinc-900 dark:text-white">{{ __('Cameras') }}</h2>
                        <p class="text-xs text-zinc-500">{{ __('Up to 4 are shown on the live dashboard. Passwords are never stored here.') }}</p>
                    </div>
                    <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-camera">{{ __('Add camera') }}</flux:button>
                </div>

                @forelse ($this->cameras as $cam)
                    <div wire:key="cam-row-{{ $cam->id }}" class="flex flex-wrap items-center gap-4 border-t border-zinc-100 px-5 py-4 dark:border-white/5">
                        <div class="flex flex-col">
                            <button type="button" wire:click="move({{ $cam->id }}, -1)" @disabled($loop->first) class="text-zinc-400 hover:text-zinc-700 disabled:opacity-30" aria-label="{{ __('Move up') }}"><flux:icon.chevron-up variant="micro" class="size-4" /></button>
                            <button type="button" wire:click="move({{ $cam->id }}, 1)" @disabled($loop->last) class="text-zinc-400 hover:text-zinc-700 disabled:opacity-30" aria-label="{{ __('Move down') }}"><flux:icon.chevron-down variant="micro" class="size-4" /></button>
                        </div>
                        <span @class(['flex size-11 shrink-0 items-center justify-center rounded-xl', 'bg-zinc-900 text-white' => $cam->enabled, 'bg-zinc-100 text-zinc-400 dark:bg-white/5' => ! $cam->enabled])><flux:icon.video-camera variant="mini" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white">
                                <span class="truncate">{{ $cam->name }}</span>
                                @unless ($cam->enabled)<span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-zinc-500 dark:bg-white/10">{{ __('Disabled') }}</span>@endunless
                            </p>
                            <p class="truncate text-xs text-zinc-500">{{ $cam->location ?: __('No location') }} · <span class="font-mono">{{ $cam->stream_path }}</span>@if ($cam->camera_ip) · <span class="font-mono">{{ $cam->camera_ip }}</span>@endif</p>
                        </div>
                        <div class="text-end text-xs">
                            <p @class(['inline-flex items-center gap-1.5 font-medium', 'text-emerald-600 dark:text-emerald-400' => $cam->last_online_at?->gt(now()->subDay()), 'text-zinc-400' => ! $cam->last_online_at?->gt(now()->subDay())])>
                                <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $cam->last_online_at?->gt(now()->subDay()), 'bg-zinc-300' => ! $cam->last_online_at?->gt(now()->subDay())])></span>
                                {{ $cam->last_online_at ? __('Last live :time', ['time' => $cam->last_online_at->diffForHumans()]) : __('Never watched') }}
                            </p>
                            <p class="text-zinc-400">{{ trans_choice(':count view|:count views', $cam->logs_count) }}</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:switch :checked="$cam->enabled" wire:click="toggle({{ $cam->id }})" :aria-label="__('Enabled')" />
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $cam->id }})" :aria-label="__('Edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $cam->id }})" :aria-label="__('Remove')" />
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-3 border-t border-zinc-100 px-5 py-12 text-center dark:border-white/5">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-white/5"><flux:icon.video-camera class="size-6" /></span>
                        <p class="text-sm text-zinc-500">{{ __('No cameras yet. Add Office Entrance, Reception, Workspace and Main Office.') }}</p>
                    </div>
                @endforelse
            </section>

            {{-- Who can watch --}}
            <section class="{{ $panel }} overflow-hidden">
                <div class="px-5 py-4">
                    <h2 class="text-lg leading-tight font-semibold text-zinc-900 dark:text-white">{{ __('Who can watch') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Admins always can. Turn it on for staff who need CCTV.') }}</p>
                </div>
                @foreach ($this->users as $person)
                    <div wire:key="viewer-{{ $person->id }}" class="flex items-center gap-3 border-t border-zinc-100 px-5 py-3 dark:border-white/5">
                        <span class="flex size-9 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ $person->initials() }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $person->name }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ $person->email }}</p>
                        </div>
                        @if ($person->is_admin)
                            <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ __('Admin') }}</span>
                        @else
                            <flux:switch :checked="$person->can_view_cctv" wire:click="toggleViewer({{ $person->id }})" :label="__('Can watch')" align="left" data-test="viewer-{{ $person->id }}" />
                        @endif
                    </div>
                @endforeach
            </section>
        </div>

        {{-- Gateway --}}
        <aside class="flex flex-col gap-6">
            <section class="{{ $panel }} p-5" x-data="cctvGateway(@js($saved))">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.server-stack variant="mini" class="size-5" /></span>
                    <div>
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Gateway') }}</h2>
                        <p class="text-xs text-zinc-500">{{ __('MediaMTX on the office PC') }}</p>
                    </div>
                </div>

                <form wire:submit="saveGateway" class="mt-4 flex flex-col gap-3">
                    <flux:input wire:model="gateway" :label="__('Gateway address')" placeholder="https://office-pc.your-tailnet.ts.net" data-test="gateway-url" />
                    <flux:button type="submit" variant="primary" icon="check" class="w-full">{{ __('Save address') }}</flux:button>
                </form>

                @if ($saved)
                    <div class="mt-4 flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                        <span class="size-2 rounded-full" :class="{ 'bg-emerald-500': status === 'online', 'bg-zinc-400': status === 'checking', 'bg-amber-500': status === 'offline' || status === 'insecure' }"></span>
                        <span class="flex-1 text-zinc-700 dark:text-zinc-200" x-text="{ online: '{{ __('Reachable from this device') }}', checking: '{{ __('Checking...') }}', offline: '{{ __('Not reachable (PC off or not on Tailscale)') }}', insecure: '{{ __('HTTP address blocked on an HTTPS page') }}' }[status]"></span>
                        <button type="button" @click="check()" class="text-zinc-400 hover:text-zinc-700" aria-label="{{ __('Check again') }}"><flux:icon.arrow-path variant="micro" class="size-4" /></button>
                    </div>
                @endif

                <div class="mt-5 border-t border-zinc-100 pt-5 dark:border-white/5">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Office PC setup') }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ __('MediaMTX config for your cameras, start scripts and step-by-step instructions. You add the camera passwords on the PC.') }}</p>
                    <flux:button icon="arrow-down-tray" :href="route('cctv.gateway-setup')" class="mt-3 w-full" :disabled="$this->cameras->isEmpty()" data-test="download-gateway">{{ __('Download gateway setup') }}</flux:button>
                </div>
            </section>

            <section class="{{ $panel }} p-5 text-sm">
                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Which address?') }}</p>
                <dl class="mt-3 flex flex-col gap-3 text-xs">
                    <div>
                        <dt class="font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Nepsus on Hostinger (HTTPS)') }}</dt>
                        <dd class="mt-0.5 text-zinc-500">{{ __('Use the HTTPS address from "tailscale serve" on the office PC. Viewers need Tailscale on their device.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Nepsus running on the office PC') }}</dt>
                        <dd class="mt-0.5 text-zinc-500"><span class="font-mono">http://127.0.0.1:8888</span></dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>

    {{-- Add / edit camera --}}
    <flux:modal name="cctv-camera" class="w-full max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-zinc-900 text-white"><flux:icon.video-camera variant="mini" class="size-5" /></span>
                <flux:heading size="lg">{{ $editingId ? __('Edit camera') : __('Add camera') }}</flux:heading>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model.live.debounce.400ms="form.name" :label="__('Name')" :placeholder="__('e.g. Office Entrance')" required data-test="camera-name" />
                <flux:input wire:model="form.location" :label="__('Location')" :placeholder="__('e.g. Ground floor, front door')" />
                <flux:input wire:model="form.stream_path" :label="__('Stream name on the gateway')" placeholder="office-entrance" class:input="font-mono" />
                <flux:input wire:model="form.camera_ip" :label="__('Camera IP (office network)')" placeholder="192.168.1.64" class:input="font-mono" />
                <div class="sm:col-span-2">
                    <flux:input wire:model="form.rtsp_path" :label="__('RTSP path')" placeholder="/h264/ch1/main/av_stream" class:input="font-mono" :description="__('The part after :554 in the address that works in VLC. Leave the password out: it goes in the gateway file on the office PC.')" />
                </div>
                <div class="sm:col-span-2">
                    <flux:switch wire:model="form.transcode" :label="__('Convert video so every browser can play it')" align="left" :description="__('Needed for EZVIZ cameras that send H.265 (like the H6c). Uses the office PC processor only while someone watches. Turn off only if the camera already sends H.264.')" />
                </div>
                <div class="sm:col-span-2">
                    <flux:switch wire:model="form.enabled" :label="__('Show on the live dashboard')" align="left" />
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" data-test="save-camera">{{ __('Save camera') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove camera --}}
    <flux:modal name="cctv-delete" class="w-full max-w-md p-0!">
        @php $deleting = $deletingId ? $this->cameras->firstWhere('id', $deletingId) : null; @endphp
        <div class="flex flex-col items-center px-6 pt-8 pb-6 text-center">
            <span class="flex size-14 items-center justify-center rounded-full bg-rose-50 text-rose-600 ring-8 ring-rose-50/50 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/5"><flux:icon.trash class="size-6" /></span>
            <flux:heading size="lg" class="mt-5">{{ __('Remove :name?', ['name' => $deleting?->name ?? __('this camera')]) }}</flux:heading>
            <flux:text class="mt-1 max-w-xs">{{ __('It disappears from the dashboard. Its access log entries are kept. Remove it from the gateway file on the office PC too.') }}</flux:text>
        </div>
        <div class="grid grid-cols-2 gap-2 rounded-b-xl border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
            <flux:modal.close><flux:button variant="ghost" class="w-full">{{ __('Keep it') }}</flux:button></flux:modal.close>
            <flux:button variant="danger" icon="trash" class="w-full" wire:click="delete({{ (int) $deletingId }})" :disabled="! $deleting">{{ __('Remove') }}</flux:button>
        </div>
    </flux:modal>
</div>
