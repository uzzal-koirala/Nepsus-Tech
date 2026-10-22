<?php

use App\Models\Client;
use App\Models\Script;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Script')] class extends Component {
    public Script $script;

    public string $title = '';

    public string $clientId = '';

    public string $type = 'reel';

    public string $status = 'draft';

    public string $body = '';

    public string $notes = '';

    public ?string $savedAt = null;

    public function mount(Script $script): void
    {
        $this->script = $script;
        $this->title = $script->title;
        $this->clientId = (string) $script->client_id;
        $this->type = $script->type;
        $this->status = $script->status;
        $this->body = (string) $script->body;
        $this->notes = (string) $script->notes;
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    /** Every change is saved as you type. */
    public function updated(string $field): void
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'clientId' => ['nullable', Rule::exists('clients', 'id')],
            'type' => ['required', Rule::in(array_keys(Script::TYPES))],
            'status' => ['required', Rule::in(array_keys(Script::STATUSES))],
            'body' => ['nullable', 'string', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
        if (! isset($rules[$field])) {
            return;
        }
        $value = $this->validate([$field => $rules[$field]], attributes: ['title' => __('title')])[$field];
        $column = ['clientId' => 'client_id'][$field] ?? $field;
        $this->script->update([$column => $value === '' ? null : $value]);
        $this->savedAt = now()->format('g:i A');
    }

    public function duplicate(): void
    {
        $copy = $this->script->replicate()->fill(['title' => __(':title (copy)', ['title' => $this->script->title]), 'status' => 'draft', 'user_id' => auth()->id()]);
        $copy->save();
        Flux::toast(variant: 'success', text: __('Copy made.'));
        $this->redirectRoute('scripts.edit', $copy, navigate: true);
    }

    public function delete(): void
    {
        $this->script->delete();
        Flux::toast(text: __('Script deleted.'));
        $this->redirectRoute('scripts.index', navigate: true);
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $typeOptions = collect(Script::TYPES)->map(fn ($t, $k) => ['value' => $k, 'label' => __($t['label']), 'icon' => $t['icon'], 'tile' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300'])->values();
    $statusOptions = collect(Script::STATUSES)->map(fn ($s, $k) => ['value' => $k, 'label' => __($s['label']), 'icon' => 'flag', 'tile' => $s['tone']])->values();
    $clientOptions = collect([['value' => '', 'label' => __('No client'), 'hint' => __('Our own content'), 'icon' => 'home']])
        ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->company ? $c->name : $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
    $placeholder = __("HOOK (first 3 seconds)\nStop scrolling if you love momo…\n\nSTORY\nShow the kitchen, the steam, the first bite…\n\nCALL TO ACTION\nVisit us in Thamel today — link in bio.");
@endphp

<div class="flex w-full flex-col gap-5" x-data="{
        words(t) { return (t.match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu) || []).length },
        time(t) { const s = Math.round(this.words(t) / {{ Script::WORDS_PER_MINUTE }} * 60); return s < 60 ? s + ' sec' : Math.floor(s / 60) + ' min' + (s % 60 ? ' ' + (s % 60) + ' sec' : '') },
        copied: false,
    }">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('scripts.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium text-zinc-600 transition hover:bg-white hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5"><flux:icon.arrow-left variant="micro" class="size-4" />{{ __('All scripts') }}</a>
        <span class="ms-auto inline-flex items-center gap-1.5 text-xs text-zinc-500" data-test="save-state">
            <span wire:loading.remove wire:target="title,body,notes,type,status,clientId" class="inline-flex items-center gap-1.5">
                @if ($savedAt)<flux:icon.check-circle variant="micro" class="size-4 text-emerald-500" />{{ __('Saved :time', ['time' => $savedAt]) }}@else<flux:icon.cloud variant="micro" class="size-4" />{{ __('Saves as you type') }}@endif
            </span>
            <span wire:loading wire:target="title,body,notes,type,status,clientId" class="inline-flex items-center gap-1.5"><flux:icon.arrow-path variant="micro" class="size-4 animate-spin" />{{ __('Saving…') }}</span>
        </span>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        {{-- Writing --}}
        <section class="{{ $panel }} overflow-hidden">
            <div class="border-b border-zinc-100 px-6 pt-6 pb-4 sm:px-8 dark:border-white/5">
                <input type="text" wire:model.live.debounce.700ms="title" maxlength="200" placeholder="{{ __('Script title') }}" aria-label="{{ __('Title') }}"
                    class="w-full border-0 bg-transparent p-0 text-2xl font-semibold tracking-tight text-zinc-900 placeholder:text-zinc-300 focus:ring-0 focus:outline-none sm:text-3xl dark:text-white" data-test="script-title">
                <flux:error name="title" />
            </div>
            <textarea wire:model.live.debounce.800ms="body" x-ref="body" placeholder="{{ $placeholder }}" aria-label="{{ __('Script') }}"
                x-init="const grow = () => { $el.style.height = 'auto'; $el.style.height = Math.max($el.scrollHeight, window.innerHeight * 0.55) + 'px' }; grow(); $el.addEventListener('input', grow)"
                class="block min-h-[55vh] w-full resize-none border-0 bg-transparent px-6 py-6 text-[0.9375rem] leading-8 text-zinc-800 placeholder:text-zinc-300 focus:ring-0 focus:outline-none sm:px-8 dark:text-zinc-100 dark:placeholder:text-zinc-600" data-test="script-body">{{ $body }}</textarea>
        </section>

        {{-- Details --}}
        <aside class="flex flex-col gap-4 lg:sticky lg:top-6">
            <section class="{{ $panel }} grid grid-cols-3 divide-x divide-zinc-100 dark:divide-white/5">
                <div class="px-3 py-4 text-center"><p class="text-xl font-bold text-zinc-900 tabular-nums dark:text-white" x-text="words($wire.body)">{{ Script::wordCount($body) }}</p><p class="text-[0.6875rem] text-zinc-500">{{ __('words') }}</p></div>
                <div class="px-3 py-4 text-center"><p class="text-xl font-bold text-zinc-900 tabular-nums dark:text-white" x-text="time($wire.body)">{{ Script::speakingTime($body) }}</p><p class="text-[0.6875rem] text-zinc-500">{{ __('to say') }}</p></div>
                <div class="px-3 py-4 text-center"><p class="text-xl font-bold text-zinc-900 tabular-nums dark:text-white" x-text="$wire.body.length">{{ mb_strlen($body) }}</p><p class="text-[0.6875rem] text-zinc-500">{{ __('characters') }}</p></div>
            </section>

            <section class="{{ $panel }} flex flex-col gap-4 p-5">
                <x-rich-select model="status" live :label="__('Status')" :options="$statusOptions" />
                <x-rich-select model="type" live :label="__('Type')" :options="$typeOptions" />
                <x-rich-select model="clientId" live searchable :label="__('Client')" :options="$clientOptions" />
                <flux:textarea wire:model.live.debounce.800ms="notes" :label="__('Notes')" rows="3" :placeholder="__('Shooting notes, music, props, who is in it…')" />
            </section>

            <section class="{{ $panel }} flex flex-col gap-1 p-2">
                <button type="button" @click="navigator.clipboard.writeText($wire.title + '\n\n' + $wire.body); copied = true; setTimeout(() => copied = false, 2000)" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-start text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-white/5">
                    <flux:icon.clipboard-document variant="mini" class="size-4.5 text-zinc-400" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy script'))"></span>
                </button>
                <button type="button" wire:click="duplicate" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-start text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-white/5">
                    <flux:icon.document-duplicate variant="mini" class="size-4.5 text-zinc-400" />{{ __('Make a copy') }}
                </button>
                <button type="button" x-on:click="$flux.modal('delete-script').show()" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-start text-sm font-medium text-rose-600 transition hover:bg-rose-50 dark:hover:bg-rose-500/10">
                    <flux:icon.trash variant="mini" class="size-4.5" />{{ __('Delete script') }}
                </button>
            </section>
            <p class="px-1 text-xs text-zinc-400">{{ __('Speaking time at :wpm words a minute.', ['wpm' => Script::WORDS_PER_MINUTE]) }}</p>
        </aside>
    </div>

    <flux:modal name="delete-script" class="w-full max-w-sm">
        <div class="flex flex-col items-center gap-4 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.trash class="size-7" /></span>
            <div>
                <flux:heading size="lg">{{ __('Delete this script?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('“:title” is removed for good.', ['title' => $title]) }}</flux:text>
            </div>
            <div class="flex w-full gap-2">
                <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
