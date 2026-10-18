<?php

use App\Models\AiConversation;
use App\Models\Setting;
use App\Support\AiAssistant;
use Flux\Flux;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('AI Assistant')] class extends Component {
    #[Url(as: 'c', except: null)]
    public ?int $conversationId = null;

    public string $apiKey = '';

    public function mount(): void
    {
        // Only your own chats.
        if ($this->conversationId && ! $this->conversation) {
            $this->conversationId = null;
        }
    }

    #[Computed]
    public function conversation(): ?AiConversation
    {
        return $this->conversationId
            ? AiConversation::where('user_id', auth()->id())->with('messages')->find($this->conversationId)
            : null;
    }

    #[Computed]
    public function conversations()
    {
        return AiConversation::where('user_id', auth()->id())->latest('updated_at')->limit(50)->get();
    }

    public function newChat(): void
    {
        $this->conversationId = null;
    }

    public function open(int $id): void
    {
        $this->conversationId = AiConversation::where('user_id', auth()->id())->findOrFail($id)->id;
    }

    public function deleteChat(int $id): void
    {
        AiConversation::where('user_id', auth()->id())->whereKey($id)->delete();
        if ($this->conversationId === $id) {
            $this->conversationId = null;
        }
        unset($this->conversations);
    }

    public function saveKey(): void
    {
        $this->validate(['apiKey' => ['required', 'string', 'starts_with:sk-or-,sk-ant-', 'max:300']], ['apiKey.starts_with' => __('Paste an OpenRouter key (sk-or-...) or an Anthropic key (sk-ant-...).')], ['apiKey' => __('API key')]);
        $key = trim($this->apiKey);
        Setting::put([(str_starts_with($key, 'sk-or-') ? 'openrouter' : 'anthropic').'_api_key' => Crypt::encryptString($key)]);
        $this->apiKey = '';
        Flux::toast(variant: 'success', text: __('AI connected.'));
    }

    public function send(string $text): void
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000 || ! AiAssistant::configured()) {
            return;
        }

        // Free models can take a minute or more with several tool calls; PHP's default limit is 30 seconds.
        set_time_limit(300);

        $chat = $this->conversation ?? AiConversation::create(['user_id' => auth()->id(), 'title' => Str::limit($text, 60)]);
        $chat->messages()->create(['role' => 'user', 'content' => $text]);

        // Plain text of the recent turns is enough context; actions are already saved in the app.
        // The relation sorts oldest first; replace that to take the newest 30, then put them back in order.
        $history = $chat->messages()->reorder('id', 'desc')->limit(30)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();

        $reply = app(AiAssistant::class)->reply($history, auth()->user()->name);

        $chat->messages()->create(['role' => 'assistant', 'content' => $reply['text'], 'actions' => $reply['actions'] ?: null, 'model' => $reply['model'] ?? null]);
        $chat->touch();

        $this->conversationId = $chat->id;
        unset($this->conversation, $this->conversations);
        $this->dispatch('ai-replied');
    }
}; ?>

@php
    $chat = $this->conversation;
    $ready = AiAssistant::configured();
    $provider = AiAssistant::provider();
    $modelLabel = AiAssistant::label(AiAssistant::model());
    $suggestions = [
        ['clipboard-document-check', __('Add a task'), __('Add a task for Ram to design 4 Dashain posts for Everest Gym, due 25 Kartik')],
        ['exclamation-triangle', __('What is late?'), __('Which tasks are overdue, and who has them?')],
        ['chart-bar', __('This month'), __('How is the business doing this month?')],
        ['user-group', __('Team load'), __('Who on the team has the most open tasks right now?')],
    ];
    $md = fn ($text) => Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

<div class="-m-6 flex h-[calc(100dvh-3.5rem)] min-h-0 lg:-m-8 lg:h-dvh" x-data="{
        draft: '',
        pending: null,
        submit() {
            const text = this.draft.trim();
            if (! text || this.pending) return;
            this.pending = text;
            this.draft = '';
            this.$nextTick(() => { this.grow(); this.toBottom(); });
            $wire.send(text).finally(() => { this.pending = null; this.$nextTick(() => this.toBottom()); });
        },
        ask(text) { this.draft = text; this.submit(); },
        grow() { const t = this.$refs.input; if (! t) return; t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 200) + 'px'; },
        toBottom() { const s = this.$refs.scroll; if (s) s.scrollTop = s.scrollHeight; },
    }" x-init="$nextTick(() => toBottom())" @ai-replied.window="$nextTick(() => toBottom())">

    {{-- Chats --}}
    <aside class="hidden w-64 shrink-0 flex-col border-e border-zinc-200/70 bg-white/60 p-3 md:flex dark:border-white/5 dark:bg-zinc-950/40">
        <flux:button variant="primary" icon="pencil-square" wire:click="newChat" class="w-full" data-test="new-chat">{{ __('New chat') }}</flux:button>
        <p class="mt-5 mb-1.5 px-2 text-[0.6875rem] font-semibold tracking-wider text-zinc-400 uppercase">{{ __('Recent') }}</p>
        <nav class="-mx-1 flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @forelse ($this->conversations as $c)
                <div wire:key="c-{{ $c->id }}" @class([
                    'group flex items-center rounded-lg transition',
                    'bg-zinc-200/60 dark:bg-white/10' => $chat?->id === $c->id,
                    'hover:bg-zinc-100 dark:hover:bg-white/5' => $chat?->id !== $c->id,
                ])>
                    <button type="button" wire:click="open({{ $c->id }})" class="min-w-0 flex-1 truncate px-2.5 py-2 text-start text-sm text-zinc-700 dark:text-zinc-200">{{ $c->title }}</button>
                    <button type="button" wire:click="deleteChat({{ $c->id }})" class="me-1 rounded-md p-1 text-zinc-400 opacity-0 transition group-hover:opacity-100 hover:bg-white hover:text-rose-600 dark:hover:bg-white/10" aria-label="{{ __('Delete chat') }}"><flux:icon.trash variant="micro" class="size-3.5" /></button>
                </div>
            @empty
                <p class="px-2 py-1 text-xs text-zinc-400">{{ __('Your chats will show here.') }}</p>
            @endforelse
        </nav>
    </aside>

    {{-- Conversation --}}
    <section class="flex min-w-0 flex-1 flex-col">
        <div x-ref="scroll" class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6">
                @if (! $ready)
                    {{-- Connect --}}
                    <div class="mx-auto mt-10 w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-[0_1px_2px_rgb(15_23_42/0.05),0_8px_24px_-12px_rgb(15_23_42/0.12)] dark:bg-zinc-900">
                        <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.sparkles class="size-7" /></span>
                        <h2 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Connect the AI') }}</h2>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Paste an OpenRouter key (free models at openrouter.ai) or an Anthropic key. It is stored encrypted.') }}</p>
                        <form wire:submit="saveKey" class="mt-5 flex flex-col gap-3 text-start">
                            <flux:input wire:model="apiKey" type="password" :label="__('API key')" placeholder="sk-or-... or sk-ant-..." viewable data-test="api-key" />
                            <flux:button type="submit" variant="primary" icon="check">{{ __('Connect') }}</flux:button>
                        </form>
                    </div>
                @elseif (! $chat || $chat->messages->isEmpty())
                    {{-- Welcome --}}
                    <div class="mt-10 flex flex-col items-center text-center" x-show="! pending">
                        <span class="flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.sparkles class="size-7" /></span>
                        <h1 class="mt-5 text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ __('Hi :name, how can I help?', ['name' => Str::before(auth()->user()->name, ' ')]) }}</h1>
                        <p class="mt-2 text-sm text-zinc-500">{{ __('Ask a question, or tell me to add and assign tasks. I work with your real data.') }}</p>
                        <div class="mt-8 grid w-full gap-3 sm:grid-cols-2">
                            @foreach ($suggestions as [$icon, $label, $prompt])
                                <button type="button" @click="ask(@js($prompt))" class="group flex items-start gap-3 rounded-xl bg-white p-4 text-start shadow-[0_1px_2px_rgb(15_23_42/0.05)] ring-1 ring-zinc-200/70 transition hover:-translate-y-0.5 hover:shadow-md dark:bg-zinc-900 dark:ring-white/10">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon :icon="$icon" variant="mini" class="size-4.5" /></span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</span>
                                        <span class="mt-0.5 block text-xs text-zinc-500">{{ $prompt }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($chat)
                    @foreach ($chat->messages as $m)
                        @if ($m->role === 'user')
                            <div wire:key="m-{{ $m->id }}" class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl rounded-br-md bg-brand-500 px-4 py-2.5 text-sm whitespace-pre-wrap text-white shadow-md shadow-brand-500/20">{{ $m->content }}</div>
                            </div>
                        @else
                            <div wire:key="m-{{ $m->id }}" class="flex gap-3">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-brand-500 to-violet-600 text-white"><flux:icon.sparkles variant="micro" class="size-4" /></span>
                                <div class="min-w-0 flex-1 pt-1">
                                    <div class="ai-answer text-sm leading-relaxed text-zinc-800 dark:text-zinc-100">{!! $md($m->content) !!}</div>
                                    @if ($m->model)
                                        <p class="mt-2 text-[0.6875rem] text-zinc-400" title="{{ $m->model }}">{{ AiAssistant::label($m->model) }}</p>
                                    @endif
                                    @if ($m->actions)
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach ($m->actions as $a)
                                                <a href="{{ $a['url'] ?? '#' }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 transition hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300">
                                                    <flux:icon :icon="$a['icon'] ?? 'check-circle'" variant="micro" class="size-3.5" />{{ $a['text'] }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                @endif

                {{-- Sent, waiting for the answer --}}
                <template x-if="pending">
                    <div class="flex flex-col gap-6">
                        <div class="flex justify-end"><div class="max-w-[85%] rounded-2xl rounded-br-md bg-brand-500 px-4 py-2.5 text-sm whitespace-pre-wrap text-white shadow-md shadow-brand-500/20" x-text="pending"></div></div>
                        <div class="flex items-center gap-3">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-brand-500 to-violet-600 text-white"><flux:icon.sparkles variant="micro" class="size-4 animate-pulse" /></span>
                            <span class="flex items-center gap-1 rounded-2xl bg-white px-4 py-3 shadow-sm dark:bg-zinc-900">
                                <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.3s]"></span>
                                <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.15s]"></span>
                                <span class="size-1.5 animate-bounce rounded-full bg-zinc-400"></span>
                            </span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Composer --}}
        <div class="px-4 pb-5 sm:px-6">
            <form @submit.prevent="submit()" class="mx-auto flex w-full max-w-3xl items-end gap-2 rounded-2xl bg-white p-2 shadow-[0_1px_2px_rgb(15_23_42/0.05),0_8px_28px_-10px_rgb(15_23_42/0.18)] ring-1 ring-zinc-200/70 focus-within:ring-2 focus-within:ring-brand-500/40 dark:bg-zinc-900 dark:ring-white/10">
                <textarea x-ref="input" x-model="draft" @input="grow()" @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); submit(); }" rows="1" maxlength="4000" @disabled(! $ready)
                    placeholder="{{ $ready ? __('Ask anything, or say “add a task for…”') : __('Connect the AI first') }}"
                    class="max-h-[200px] min-h-10 flex-1 resize-none border-0 bg-transparent px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:ring-0 focus:outline-none dark:text-white" data-test="ai-input"></textarea>
                <button type="submit" :disabled="! draft.trim() || pending" @disabled(! $ready) class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30 transition hover:bg-brand-600 disabled:bg-zinc-200 disabled:text-zinc-400 disabled:shadow-none dark:disabled:bg-white/10" aria-label="{{ __('Send') }}">
                    <flux:icon.arrow-up variant="mini" class="size-5" />
                </button>
            </form>
            <div class="mx-auto mt-2 flex max-w-3xl flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[0.6875rem] text-zinc-400">
                @if ($ready)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2 py-0.5 ring-1 ring-zinc-200/70 dark:bg-zinc-900 dark:ring-white/10" title="{{ AiAssistant::model() }}" data-test="ai-model">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>{{ $modelLabel }} · {{ $provider === 'openrouter' ? 'OpenRouter' : 'Anthropic' }}
                    </span>
                @endif
                <span>{{ __('The assistant can make mistakes. It can add and change tasks, but never deletes anything.') }}</span>
            </div>
        </div>
    </section>
</div>
