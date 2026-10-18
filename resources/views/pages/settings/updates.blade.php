<?php

use App\Support\Updater;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Updates')] class extends Component {
    /** Set right after "Update now", until GitHub shows the new run. */
    public bool $starting = false;

    #[Computed]
    public function status(): array
    {
        return app(Updater::class)->status();
    }

    #[Computed]
    public function run(): ?array
    {
        return app(Updater::class)->lastRun(fresh: $this->starting);
    }

    public function update(): void
    {
        Flux::modal('confirm-update')->close();
        if ($error = app(Updater::class)->start()) {
            Flux::toast(variant: 'danger', text: $error);

            return;
        }
        $this->starting = true;
        unset($this->status, $this->run);
        Flux::toast(variant: 'success', text: __('Update started. This takes about two minutes.'));
    }

    /** Polled while an update runs. */
    public function refresh(): void
    {
        unset($this->status, $this->run);
        $run = app(Updater::class)->lastRun(fresh: true);
        // The new run is showing on GitHub now; its own status drives the page from here.
        if ($run && $run['status'] !== 'completed') {
            $this->starting = false;
        }
    }
}; ?>

@php
    $ready = Updater::configured();
    $live = Updater::deployed();
    $run = $ready ? $this->run : null;
    $s = $ready ? $this->status : null;
    $busy = $starting || ($run && $run['status'] !== 'completed');
    $short = fn (?string $sha) => $sha ? substr($sha, 0, 7) : '—';
    $when = fn (?string $iso) => $iso ? Carbon::parse($iso)->setTimezone(config('app.timezone'))->toNepali().' · '.Carbon::parse($iso)->diffForHumans() : '';
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Updates')" :subheading="__('Put the newest version of the app live. GitHub does the work, so this site stays fast while it updates.')">
        <div class="flex flex-col gap-6" @if ($busy) wire:poll.8s="refresh" @endif>
            @unless ($ready)
                {{-- Setup --}}
                <div class="rounded-xl bg-amber-50 p-5 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20">
                    <p class="flex items-center gap-2 font-semibold text-amber-900 dark:text-amber-200"><flux:icon.key variant="mini" class="size-5" />{{ __('Connect GitHub to turn on updates') }}</p>
                    <ol class="mt-3 list-decimal space-y-1.5 ps-5 text-sm text-amber-900/90 dark:text-amber-100/90">
                        <li>{!! __('On GitHub open <strong>Settings → Developer settings → Fine-grained tokens → Generate new token</strong>.') !!}</li>
                        <li>{!! __('Repository access: <strong>only :repo</strong>. Permissions: <strong>Actions: Read and write</strong> and <strong>Contents: Read</strong>.', ['repo' => e(config('services.github.repo'))]) !!}</li>
                        <li>{!! __('Put it in this site\'s <strong>.env</strong> file as <code>GITHUB_TOKEN=...</code>, then reload this page.') !!}</li>
                    </ol>
                </div>
            @else
                {{-- Versions --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-white/[0.03]">
                        <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Live now') }}</p>
                        @if ($live)
                            <p class="mt-1.5 font-semibold text-zinc-900 dark:text-white">{{ $live['message'] ?? '' }}</p>
                            <p class="mt-1 text-xs text-zinc-500"><span class="font-mono">{{ $short($live['sha']) }}</span> · {{ $when($live['at'] ?? null) }}</p>
                        @else
                            <p class="mt-1.5 font-semibold text-zinc-900 dark:text-white">{{ __('Unknown') }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ __('Not put live by the Update button yet.') }}</p>
                        @endif
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-white/[0.03]">
                        <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Newest on GitHub') }}</p>
                        @if ($s['latest'])
                            <p class="mt-1.5 font-semibold text-zinc-900 dark:text-white">{{ $s['latest']['message'] }}</p>
                            <p class="mt-1 text-xs text-zinc-500"><span class="font-mono">{{ $short($s['latest']['sha']) }}</span> · {{ $when($s['latest']['at']) }}</p>
                        @else
                            <p class="mt-1.5 text-sm text-rose-600 dark:text-rose-400">{{ $s['error'] }}</p>
                        @endif
                    </div>
                </div>

                {{-- State + button --}}
                @if ($busy)
                    <div class="flex items-center gap-4 rounded-xl bg-brand-50 p-4 ring-1 ring-brand-200/70 dark:bg-brand-500/10 dark:ring-brand-500/20" data-test="update-running">
                        <span class="relative flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-500 text-white"><flux:icon.arrow-path variant="mini" class="size-5 animate-spin" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-brand-800 dark:text-brand-200">{{ ($run['status'] ?? null) === 'in_progress' ? __('Updating… tests, build and upload are running on GitHub') : __('Starting the update…') }}</p>
                            <p class="text-xs text-brand-700/80 dark:text-brand-200/70">{{ __('Usually about two minutes. You can keep working; this page checks by itself.') }}</p>
                        </div>
                        @if ($run)<a href="{{ $run['url'] }}" target="_blank" rel="noopener" class="text-xs font-medium text-brand-700 underline dark:text-brand-200">{{ __('Details') }}</a>@endif
                    </div>
                @elseif ($s['latest'] && $s['ahead'] === 0)
                    <div class="flex items-center gap-4 rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/20" data-test="up-to-date">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><flux:icon.check variant="mini" class="size-5" /></span>
                        <div class="flex-1">
                            <p class="font-semibold text-emerald-800 dark:text-emerald-200">{{ __('Up to date') }}</p>
                            <p class="text-xs text-emerald-700/80 dark:text-emerald-200/70">{{ __('The live site runs the newest version.') }}</p>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="arrow-path" x-on:click="$flux.modal('confirm-update').show()">{{ __('Update again') }}</flux:button>
                    </div>
                @elseif ($s['latest'])
                    <div class="rounded-xl ring-1 ring-zinc-200/70 dark:ring-white/10" data-test="updates-available">
                        <div class="flex flex-wrap items-center gap-4 p-4">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-500 text-white"><flux:icon.sparkles variant="mini" class="size-5" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ trans_choice(':count update ready|:count updates ready', $s['ahead']) }}</p>
                                <p class="text-xs text-zinc-500">{{ __('Tests run first; if any fail, nothing changes on the live site.') }}</p>
                            </div>
                            <flux:button variant="primary" icon="cloud-arrow-down" x-on:click="$flux.modal('confirm-update').show()" data-test="update-now">{{ __('Update now') }}</flux:button>
                        </div>
                        @if ($s['commits'])
                            <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-white/5 dark:border-white/5">
                                @foreach ($s['commits'] as $c)
                                    <li class="flex items-start gap-3 px-4 py-2.5">
                                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm text-zinc-800 dark:text-zinc-100">{{ $c['message'] }}</p>
                                            <p class="text-xs text-zinc-400">{{ $when($c['at']) }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                {{-- Last run --}}
                @if ($run && ! $busy)
                    @php $ok = $run['conclusion'] === 'success'; @endphp
                    <p class="flex items-center gap-2 text-sm text-zinc-500" data-test="last-run">
                        <flux:icon :icon="$ok ? 'check-circle' : 'x-circle'" variant="mini" @class(['size-4.5', 'text-emerald-500' => $ok, 'text-rose-500' => ! $ok]) />
                        {{ $ok ? __('Last update finished') : __('Last update failed: nothing was changed on the live site') }} · {{ $when($run['started']) }}
                        <a href="{{ $run['url'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('Details') }}</a>
                    </p>
                @endif
            @endunless
        </div>

        <flux:modal name="confirm-update" class="w-full max-w-sm">
            <div class="flex flex-col items-center gap-4 text-center">
                <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10"><flux:icon.cloud-arrow-down class="size-7" /></span>
                <div>
                    <flux:heading size="lg">{{ __('Put the update live?') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('The site shows a short "back soon" page for a few seconds while the database updates.') }}</flux:text>
                </div>
                <div class="flex w-full gap-2">
                    <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Not now') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="update" class="flex-1" data-test="confirm-update">{{ __('Update') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
