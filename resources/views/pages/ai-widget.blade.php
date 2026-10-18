<?php

use App\Models\Setting;
use App\Support\AiAssistant;
use Flux\Flux;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Component;

/** The floating AI chat on every page. Nothing is saved: a reload or "clear" starts fresh. */
new class extends Component {
    /** @var list<array{role: string, content: string, actions?: array, model?: ?string}> */
    public array $messages = [];

    public string $apiKey = '';

    public function send(string $text): void
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000 || ! AiAssistant::configured()) {
            return;
        }

        // Free models can take a minute or more with several tool calls; PHP's default limit is 30 seconds.
        set_time_limit(300);

        $this->messages[] = ['role' => 'user', 'content' => $text];
        $history = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], array_slice($this->messages, -30));

        $reply = app(AiAssistant::class)->reply($history, auth()->user()->name);
        $this->messages[] = ['role' => 'assistant', 'content' => $reply['text'], 'actions' => $reply['actions'], 'model' => $reply['model'] ?? null];
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function saveKey(): void
    {
        $this->validate(['apiKey' => ['required', 'string', 'starts_with:sk-or-,sk-ant-', 'max:300']], ['apiKey.starts_with' => __('Paste an OpenRouter key (sk-or-...) or an Anthropic key (sk-ant-...).')], ['apiKey' => __('API key')]);
        $key = trim($this->apiKey);
        Setting::put([(str_starts_with($key, 'sk-or-') ? 'openrouter' : 'anthropic').'_api_key' => Crypt::encryptString($key)]);
        $this->apiKey = '';
        Flux::toast(variant: 'success', text: __('AI connected.'));
    }
}; ?>

@php
    $ready = AiAssistant::configured();
    $md = fn ($text) => Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    $suggestions = [__('What tasks are overdue?'), __('How is this month going?'), __('Add a task for…')];
@endphp

<div x-data="{
        open: false,
        draft: '',
        pending: null,
        submit() {
            const text = this.draft.trim();
            if (! text || this.pending) return;
            if (text.endsWith('…')) { this.draft = text.slice(0, -1); this.$refs.input.focus(); return; }
            this.pending = text;
            this.draft = '';
            this.$nextTick(() => { this.grow(); this.toBottom(); });
            $wire.send(text).finally(() => { this.pending = null; this.$nextTick(() => this.toBottom()); });
        },
        ask(text) { this.draft = text; this.submit(); },
        grow() { const t = this.$refs.input; if (! t) return; t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 120) + 'px'; },
        toBottom() { const s = this.$refs.scroll; if (s) s.scrollTop = s.scrollHeight; },
        toggle() { this.open = ! this.open; if (this.open) this.$nextTick(() => { this.toBottom(); this.$refs.input?.focus(); }); },
    }" @keydown.escape.window="open = false" class="fixed right-4 bottom-4 z-50 flex flex-col items-end gap-3 sm:right-6 sm:bottom-6" data-test="ai-widget">

    {{-- Chat --}}
    <div x-show="open" x-cloak x-transition.origin.bottom.right.duration.200ms
        class="flex h-[min(34rem,calc(100dvh-7rem))] w-[calc(100vw-2rem)] origin-bottom-right flex-col overflow-hidden rounded-2xl bg-white shadow-[0_24px_64px_-16px_rgb(15_23_42/0.35)] ring-1 ring-zinc-200/70 sm:w-[23rem] dark:bg-zinc-900 dark:ring-white/10"
        role="dialog" aria-label="{{ __('AI Assistant') }}">

        {{-- Header --}}
        <div class="flex items-center gap-3 bg-linear-to-br from-brand-500 to-violet-600 px-4 py-3 text-white">
            <span class="flex size-8 items-center justify-center rounded-full bg-white/20"><flux:icon.sparkles variant="micro" class="size-4" /></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ __('AI Assistant') }}</p>
                @if ($ready)
                    <p class="truncate text-[0.6875rem] text-white/75" title="{{ AiAssistant::model() }}" data-test="ai-model">{{ AiAssistant::label(AiAssistant::model()) }}</p>
                @endif
            </div>
            @if ($messages)
                <button type="button" wire:click="clear" class="rounded-lg p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white" title="{{ __('New chat') }}" aria-label="{{ __('New chat') }}"><flux:icon.arrow-path variant="micro" class="size-4" /></button>
            @endif
            <button type="button" @click="open = false" class="rounded-lg p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white" aria-label="{{ __('Close') }}"><flux:icon.x-mark variant="micro" class="size-4" /></button>
        </div>

        {{-- Messages --}}
        <div x-ref="scroll" class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-4 py-4 [scrollbar-width:thin]">
            @if (! $ready)
                <form wire:submit="saveKey" class="my-auto flex flex-col gap-3 text-center">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Connect the AI') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('Paste an OpenRouter key (free models at openrouter.ai) or an Anthropic key.') }}</p>
                    <flux:input wire:model="apiKey" type="password" placeholder="sk-or-..." viewable size="sm" data-test="api-key" />
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Connect') }}</flux:button>
                </form>
            @elseif (! $messages)
                <div class="my-auto text-center" x-show="! pending">
                    <span class="mx-auto flex size-11 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.sparkles variant="mini" class="size-5" /></span>
                    <p class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Hi :name, how can I help?', ['name' => Str::before(auth()->user()->name, ' ')]) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ __('Ask anything or tell me to add a task.') }}</p>
                    <div class="mt-4 flex flex-col gap-1.5">
                        @foreach ($suggestions as $q)
                            <button type="button" @click="ask(@js($q))" class="rounded-xl bg-zinc-50 px-3 py-2 text-xs font-medium text-zinc-700 transition hover:bg-brand-50 hover:text-brand-700 dark:bg-white/5 dark:text-zinc-200 dark:hover:bg-brand-500/10">{{ $q }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach ($messages as $i => $m)
                @if ($m['role'] === 'user')
                    <div wire:key="w-{{ $i }}" class="flex justify-end">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-brand-500 px-3.5 py-2 text-sm whitespace-pre-wrap text-white">{{ $m['content'] }}</div>
                    </div>
                @else
                    <div wire:key="w-{{ $i }}" class="flex max-w-[92%] flex-col gap-1.5">
                        <div class="ai-answer rounded-2xl rounded-bl-md bg-zinc-100 px-3.5 py-2 text-sm leading-relaxed text-zinc-800 dark:bg-white/5 dark:text-zinc-100">{!! $md($m['content']) !!}</div>
                        @foreach ($m['actions'] ?? [] as $a)
                            <a href="{{ $a['url'] ?? '#' }}" wire:navigate class="inline-flex w-fit items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[0.6875rem] font-medium text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300">
                                <flux:icon :icon="$a['icon'] ?? 'check-circle'" variant="micro" class="size-3" />{{ $a['text'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach

            <template x-if="pending">
                <div class="flex flex-col gap-3">
                    <div class="flex justify-end"><div class="max-w-[85%] rounded-2xl rounded-br-md bg-brand-500 px-3.5 py-2 text-sm whitespace-pre-wrap text-white" x-text="pending"></div></div>
                    <span class="flex w-fit items-center gap-1 rounded-2xl rounded-bl-md bg-zinc-100 px-3.5 py-3 dark:bg-white/5">
                        <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.3s]"></span>
                        <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.15s]"></span>
                        <span class="size-1.5 animate-bounce rounded-full bg-zinc-400"></span>
                    </span>
                </div>
            </template>
        </div>

        {{-- Composer --}}
        @if ($ready)
            <form @submit.prevent="submit()" class="flex items-end gap-2 border-t border-zinc-100 p-2.5 dark:border-white/5">
                <textarea x-ref="input" x-model="draft" @input="grow()" @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); submit(); }" rows="1" maxlength="4000"
                    placeholder="{{ __('Message the assistant…') }}"
                    class="max-h-[120px] min-h-9 flex-1 resize-none rounded-xl border-0 bg-zinc-100 px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:bg-white focus:ring-2 focus:ring-brand-500/40 focus:outline-none dark:bg-white/5 dark:text-white" data-test="ai-input"></textarea>
                <button type="submit" :disabled="! draft.trim() || pending" class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white transition hover:bg-brand-600 disabled:bg-zinc-200 disabled:text-zinc-400 dark:disabled:bg-white/10" aria-label="{{ __('Send') }}">
                    <flux:icon.arrow-up variant="micro" class="size-4" />
                </button>
            </form>
        @endif
    </div>

    {{-- Floating button --}}
    <button type="button" @click="toggle()" :aria-expanded="open" aria-label="{{ __('AI Assistant') }}"
        class="group relative flex size-14 items-center justify-center rounded-full bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-[0_12px_28px_-8px_rgb(79_70_229/0.6)] transition hover:scale-105 active:scale-95" data-test="ai-toggle">
        <flux:icon.sparkles class="size-6 transition duration-200" x-show="! open" />
        <flux:icon.x-mark class="size-6" x-show="open" x-cloak />
        <span x-show="pending && ! open" x-cloak class="absolute -top-0.5 -right-0.5 flex size-3.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-amber-400 opacity-75"></span><span class="relative inline-flex size-3.5 rounded-full bg-amber-400 ring-2 ring-white"></span></span>
    </button>
</div>
