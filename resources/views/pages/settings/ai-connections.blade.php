<?php

use App\Models\Setting;
use App\Support\AiTools;
use App\Support\Mcp;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('AI connections')] class extends Component {
    public function turnOn(): void
    {
        Mcp::regenerate();
        Flux::toast(variant: 'success', text: __('Connection turned on. Copy the link below.'));
    }

    public function newLink(): void
    {
        Mcp::regenerate();
        Flux::modal('new-link')->close();
        Flux::toast(variant: 'success', text: __('New link made. The old link no longer works.'));
    }

    public function turnOff(): void
    {
        Mcp::disable();
        Flux::modal('turn-off')->close();
        Flux::toast(text: __('Connection turned off.'));
    }
}; ?>

@php
    $url = Mcp::url();
    $lastUsed = Setting::get('mcp_last_used');
    $lastClient = Setting::get('mcp_last_client');
    $groups = [
        [__('Tasks & calendar'), 'clipboard-document-check', ['list_tasks', 'create_task', 'update_task', 'list_boxes', 'create_box', 'calendar']],
        [__('Clients'), 'building-office-2', ['find_clients', 'get_client', 'create_client', 'update_client']],
        [__('Payments & finance'), 'banknotes', ['list_payments', 'add_payment', 'mark_payment_paid', 'list_transactions', 'add_transaction', 'list_invoices', 'business_overview']],
        [__('Sales'), 'presentation-chart-line', ['list_leads', 'create_lead', 'update_lead']],
        [__('Team'), 'user-group', ['find_people', 'get_employee', 'team_today']],
    ];
    $count = count(AiTools::definitions());
    $step = 'flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white';
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('AI connections')" :subheading="__('Use ChatGPT, Claude or another AI assistant with this system: ask questions and add or change things by chatting.')">
        <div class="flex flex-col gap-8">
            {{-- Status and link --}}
            @if (! $url)
                <div class="flex flex-col items-center gap-4 rounded-2xl bg-zinc-50 px-6 py-10 text-center dark:bg-white/[0.03]">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.link class="size-7" /></span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Connect an AI assistant') }}</p>
                        <p class="mt-1 max-w-md text-sm text-zinc-500">{{ __('This makes a private link that you add to ChatGPT or Claude. It uses MCP (Model Context Protocol), the standard way AI assistants connect to apps.') }}</p>
                    </div>
                    <flux:button variant="primary" icon="power" wire:click="turnOn" data-test="mcp-on">{{ __('Turn on and make a link') }}</flux:button>
                </div>
            @else
                <div class="rounded-2xl ring-1 ring-zinc-200/70 dark:ring-white/10" x-data="{ copied: false, shown: false }">
                    <div class="flex flex-wrap items-center gap-3 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20"><span class="size-1.5 rounded-full bg-emerald-500"></span>{{ __('On') }}</span>
                        <p class="flex-1 text-sm text-zinc-500">
                            @if ($lastUsed)
                                {{ __('Last connected :when by :client', ['when' => Carbon::parse($lastUsed)->diffForHumans(), 'client' => $lastClient]) }}
                            @else
                                {{ __('Not connected yet') }}
                            @endif
                        </p>
                        <flux:button size="sm" variant="ghost" icon="arrow-path" x-on:click="$flux.modal('new-link').show()">{{ __('New link') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="power" class="text-rose-600!" x-on:click="$flux.modal('turn-off').show()">{{ __('Turn off') }}</flux:button>
                    </div>
                    <div class="p-5">
                        <p class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Your connection link (MCP server URL)') }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <div class="min-w-0 flex-1 truncate rounded-lg bg-zinc-100 px-3 py-2.5 font-mono text-sm text-zinc-800 dark:bg-white/5 dark:text-zinc-100" data-test="mcp-url">
                                <span x-show="shown" x-cloak>{{ $url }}</span>
                                <span x-show="! shown">{{ Str::beforeLast($url, '/') }}/••••••••••••••••</span>
                            </div>
                            <flux:button size="sm" variant="ghost" icon="eye" x-on:click="shown = ! shown" :aria-label="__('Show link')" />
                            <flux:button size="sm" variant="primary" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 2000)">
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                            </flux:button>
                        </div>
                        <p class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                            <flux:icon.shield-exclamation variant="micro" class="mt-0.5 size-4 shrink-0" />
                            {{ __('This link works like a password: anyone who has it can see and change your data. Only paste it into your own AI account. If it leaks, press "New link". Deleting is never possible through it.') }}
                        </p>
                    </div>
                </div>
            @endif

            {{-- Guidance --}}
            <div x-data="{ tab: 'chatgpt' }">
                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('How to connect') }}</p>
                <nav class="mt-3 flex w-fit gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                    @foreach (['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'other' => __('Other apps')] as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'" class="rounded-lg px-4 py-1.5 text-sm font-medium transition" :class="tab === '{{ $key }}' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-white'">{{ $label }}</button>
                    @endforeach
                </nav>

                <ol x-show="tab === 'chatgpt'" class="mt-4 flex flex-col gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <li class="flex gap-3"><span class="{{ $step }}">1</span><span>{!! __('In ChatGPT (a paid plan), open <strong>Settings → Apps & Connectors → Advanced settings</strong> and turn on <strong>Developer mode</strong>.') !!}</span></li>
                    <li class="flex gap-3"><span class="{{ $step }}">2</span><span>{!! __('Back in <strong>Apps & Connectors</strong>, press <strong>Create</strong>. Name: <strong>Nepsus Tech</strong>. MCP server URL: paste <strong>your link</strong> from above.') !!}</span></li>
                    <li class="flex gap-3"><span class="{{ $step }}">3</span><span>{!! __('Authentication: <strong>No authentication</strong> (the secret is inside the link). Tick that you trust it, then press <strong>Create</strong>.') !!}</span></li>
                    <li class="flex gap-3"><span class="{{ $step }}">4</span><span>{!! __('In a new chat, press <strong>+</strong>, choose <strong>Developer mode</strong> and pick <strong>Nepsus Tech</strong>. Then just ask, for example “What tasks are overdue?”') !!}</span></li>
                    <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-zinc-600 dark:bg-white/10 dark:text-zinc-300"><flux:icon.information-circle variant="micro" class="size-4" /></span><span class="text-zinc-500">{{ __('ChatGPT asks you to confirm before it changes anything. Menu names can differ slightly between ChatGPT versions.') }}</span></li>
                </ol>

                <ol x-show="tab === 'claude'" x-cloak class="mt-4 flex flex-col gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <li class="flex gap-3"><span class="{{ $step }}">1</span><span>{!! __('On claude.ai or the Claude app, open <strong>Settings → Connectors</strong> and press <strong>Add custom connector</strong>.') !!}</span></li>
                    <li class="flex gap-3"><span class="{{ $step }}">2</span><span>{!! __('Name: <strong>Nepsus Tech</strong>. Remote MCP server URL: paste <strong>your link</strong>. Press <strong>Add</strong>.') !!}</span></li>
                    <li class="flex gap-3"><span class="{{ $step }}">3</span><span>{!! __('In a chat, open the tools menu and make sure <strong>Nepsus Tech</strong> is switched on. Then ask, for example “Add a task for Ram to design 4 posts, due 25 Kartik”.') !!}</span></li>
                </ol>

                <div x-show="tab === 'other'" x-cloak class="mt-4 flex flex-col gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <p>{{ __('Any app that supports remote MCP servers (Streamable HTTP) can connect: add your link as the server URL, with no authentication.') }}</p>
                    <p class="text-zinc-500">{{ __('For apps configured with a JSON file (Claude Desktop, Cursor and others):') }}</p>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-4 text-xs leading-relaxed text-zinc-100">{"mcpServers": {"nepsus-tech": {"url": "{{ $url ? Str::beforeLast($url, '/').'/YOUR-SECRET' : 'YOUR LINK' }}"}}}</pre>
                </div>
            </div>

            {{-- What it can do --}}
            <div>
                <p class="font-semibold text-zinc-900 dark:text-white">{{ __('What the assistant can do (:count tools)', ['count' => $count]) }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($groups as [$label, $icon, $names])
                        <div class="rounded-xl bg-zinc-50 p-4 dark:bg-white/[0.03]">
                            <p class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white"><flux:icon :icon="$icon" variant="mini" class="size-4.5 text-brand-500" />{{ $label }}</p>
                            <p class="mt-1.5 text-xs leading-relaxed text-zinc-500">{{ collect($names)->map(fn ($n) => str_replace('_', ' ', $n))->implode(' · ') }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-zinc-500">{{ __('Payroll, salary payments, invoice editing and deleting stay in the app.') }}</p>
            </div>
        </div>

        <flux:modal name="new-link" class="w-full max-w-sm">
            <div class="flex flex-col gap-4 text-center">
                <flux:heading size="lg">{{ __('Make a new link?') }}</flux:heading>
                <flux:text>{{ __('The current link stops working at once. You will need to update it in ChatGPT or Claude.') }}</flux:text>
                <div class="flex gap-2">
                    <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="newLink" class="flex-1">{{ __('New link') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="turn-off" class="w-full max-w-sm">
            <div class="flex flex-col gap-4 text-center">
                <flux:heading size="lg">{{ __('Turn off AI connections?') }}</flux:heading>
                <flux:text>{{ __('ChatGPT, Claude and other assistants lose access straight away.') }}</flux:text>
                <div class="flex gap-2">
                    <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="turnOff" class="flex-1">{{ __('Turn off') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
