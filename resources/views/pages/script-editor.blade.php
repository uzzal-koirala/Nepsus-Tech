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

    public string $shootDate = '';

    public string $body = '';

    public string $notes = '';

    public ?string $savedAt = null;

    /** The admin's reason when approving or sending a script back. */
    public string $reviewNote = '';

    public function mount(Script $script): void
    {
        $this->script = $script;
        $this->title = $script->title;
        $this->clientId = (string) $script->client_id;
        $this->type = $script->type;
        $this->status = $script->status;
        $this->shootDate = (string) $script->shoot_date?->toDateString();
        $this->body = (string) $script->body;
        $this->notes = (string) $script->notes;
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'clientId' => ['nullable', Rule::exists('clients', 'id')],
            'type' => ['required', Rule::in(array_keys(Script::TYPES))],
            'status' => ['required', Rule::in(array_keys(Script::STATUSES))],
            'shootDate' => ['nullable', 'date'],
            'body' => ['nullable', 'string', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Every change is saved as you type. */
    public function updated(string $field): void
    {
        $rules = $this->rules();
        if (! isset($rules[$field])) {
            return;
        }
        // The shoot date waits for an admin's approval.
        if ($field === 'shootDate' && ! $this->script->canSchedule()) {
            $this->shootDate = (string) $this->script->shoot_date?->toDateString();

            return;
        }
        if ($field === 'status' && ! auth()->user()->isAdmin()) {
            $this->status = $this->script->status;

            return;
        }
        $value = $this->validate([$field => $rules[$field]], attributes: ['title' => __('title')])[$field];
        $column = ['clientId' => 'client_id', 'shootDate' => 'shoot_date'][$field] ?? $field;
        $this->script->update([$column => $value === '' ? null : $value]);
        $this->savedAt = now()->format('g:i A');
    }

    /** Content Creators: saves everything and sends the script to an admin for approval. */
    public function submitScript(): void
    {
        abort_if(in_array($this->script->status, ['review', 'approved', 'shot'], true), 422);
        // The script itself must be written before it goes for approval.
        $d = $this->validate(array_merge(array_diff_key($this->rules(), ['status' => 1]), ['body' => ['required', 'string', 'max:100000']]), attributes: ['title' => __('title'), 'body' => __('script')]);
        $this->script->update([
            'title' => $d['title'], 'client_id' => $d['clientId'] ?: null, 'type' => $d['type'], 'status' => 'review', 'review_note' => null,
            'body' => $d['body'], 'notes' => $d['notes'] ?: null,
        ]);
        // Tell the office right away (Settings > Email). A mail problem must not stop the submit.
        if (\App\Models\Setting::get('script_emails_on') === '1' && $to = \App\Models\Setting::systemEmails()) {
            try {
                \Illuminate\Support\Facades\Mail::to($to)->send(new \App\Mail\ScriptSubmitted($this->script->fresh('client'), auth()->user()));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        Flux::toast(variant: 'success', text: __('“:title” sent for approval.', ['title' => $d['title']]));
        $this->redirectRoute('scripts.index', navigate: true);
    }

    /** Once approved, the writer (or an admin) marks the script as shot. */
    public function markShot(): void
    {
        abort_unless(auth()->user()->canPlanContent(), 403);
        abort_unless($this->script->status === 'approved', 422);
        $this->script->update(['status' => 'shot']);
        $this->status = 'shot';
        Flux::toast(variant: 'success', text: __('“:title” marked as shot.', ['title' => $this->script->title]));
    }

    /** Admins: approve, with an optional note. */
    public function approveScript(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate(['reviewNote' => ['nullable', 'string', 'max:500']]);
        $this->script->update(['status' => 'approved', 'review_note' => trim($this->reviewNote) ?: null]);
        [$this->status, $this->reviewNote] = ['approved', ''];
        Flux::toast(variant: 'success', text: __('Script approved.'));
    }

    /** Admins: send it back with a reason the writer sees. */
    public function rejectScript(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate(['reviewNote' => ['required', 'string', 'min:3', 'max:500']], attributes: ['reviewNote' => __('reason')]);
        $this->script->update(['status' => 'rejected', 'review_note' => trim($this->reviewNote)]);
        [$this->status, $this->reviewNote] = ['rejected', ''];
        Flux::toast(text: __('Sent back to the writer.'));
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
    $statusOptions = collect(Script::STATUSES)->map(fn ($s, $k) => ['value' => $k, 'label' => __($s['label']), 'icon' => 'flag', 'tile' => $s['tone']])->values();
    $clientOptions = collect([['value' => '', 'label' => __('No client'), 'hint' => __('Our own content'), 'icon' => 'home']])
        ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->company ? $c->name : $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
    // Content Creators get a simpler panel: no status, one Save button. Nobody picks a type; scripts stay Reel unless set elsewhere.
    $simple = ! auth()->user()->isAdmin();
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
            <span wire:loading.remove wire:target="title,body,notes,type,status,clientId,shootDate" class="inline-flex items-center gap-1.5">
                @if ($savedAt)<flux:icon.check-circle variant="micro" class="size-4 text-emerald-500" />{{ __('Saved :time', ['time' => $savedAt]) }}@else<flux:icon.cloud variant="micro" class="size-4" />{{ __('Saves as you type') }}@endif
            </span>
            <span wire:loading wire:target="title,body,notes,type,status,clientId,shootDate" class="inline-flex items-center gap-1.5"><flux:icon.arrow-path variant="micro" class="size-4 animate-spin" />{{ __('Saving…') }}</span>
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

            <section class="{{ $panel }} flex flex-col gap-4 p-5" data-test="script-details">
                <div>
                    <div class="min-w-0">
                        @if (! $this->script->canSchedule())
                            <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Shoot date') }}</span>
                            <p class="mt-2 flex items-start gap-1.5 rounded-lg bg-zinc-50 px-3 py-2.5 text-xs text-zinc-500 ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/10" data-test="shoot-locked"><flux:icon.lock-closed variant="micro" class="mt-px size-3.5 shrink-0" />{{ __('Pick the shoot day once an admin approves the script.') }}</p>
                        @else
                        <x-nepali-date wire:model.live="shootDate" :label="__('Shoot date')" :placeholder="$simple ? __('Pick a day') : __('Pick the shoot day')" />
                        @endif
                        @if ($shootDate && $this->script->canSchedule())
                            <div class="mt-1.5 flex items-center justify-between gap-2 text-xs">
                                <span class="truncate text-violet-700 dark:text-violet-300">{{ $simple ? __('On the calendar') : __('Shows on the client calendar') }}</span>
                                <button type="button" wire:click="$set('shootDate', '')" class="shrink-0 text-zinc-500 hover:text-rose-600">{{ __('Clear') }}</button>
                            </div>
                        @endif
                    </div>
                </div>
                @unless ($simple)
                    <x-rich-select model="status" live :label="__('Status')" :options="$statusOptions" />
                @endunless
                <x-rich-select model="clientId" live searchable :label="__('Client')" :options="$clientOptions" />
                <flux:textarea wire:model.live.debounce.800ms="notes" :label="__('Notes')" rows="3" :placeholder="__('Shooting notes, music, props, who is in it…')" />
            </section>

            @if ($simple)
                @php $stage = $this->script->status; @endphp
                @if ($stage === 'review')
                    <div class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:ring-amber-500/20" data-test="stage-review">
                        <flux:icon.clock variant="mini" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-300" />
                        <div class="text-sm">
                            <p class="font-semibold text-amber-900 dark:text-amber-100">{{ __('Waiting for approval') }}</p>
                            <p class="text-amber-800/80 dark:text-amber-200/80">{{ __('An admin is looking at it. You can still fix typos.') }}</p>
                        </div>
                    </div>
                @elseif (in_array($stage, ['approved', 'shot'], true))
                    <div class="flex items-start gap-3 rounded-xl bg-emerald-50 px-4 py-3 ring-1 ring-emerald-200/70 dark:bg-emerald-500/10 dark:ring-emerald-500/20" data-test="stage-approved">
                        <flux:icon.check-badge variant="mini" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-300" />
                        <div class="text-sm">
                            <p class="font-semibold text-emerald-900 dark:text-emerald-100">{{ $stage === 'shot' ? __('Approved and shot') : __('Approved') }}</p>
                            <p class="text-emerald-800/80 dark:text-emerald-200/80">{{ $this->script->review_note ?: ($stage === 'shot' ? __('The footage is on its way to the editors.') : __('Ready to shoot.')) }}</p>
                        </div>
                    </div>
                    @if ($stage === 'approved')
                        <flux:button variant="primary" icon="video-camera" wire:click="markShot" wire:confirm="{{ __('Mark “:title” as shot?', ['title' => $this->script->title]) }}" class="w-full shadow-md shadow-brand-500/20" data-test="mark-shot">{{ __('Mark as shot') }}</flux:button>
                    @endif
                @elseif ($stage === 'rejected')
                    <div class="flex items-start gap-3 rounded-xl bg-rose-50 px-4 py-3 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:ring-rose-500/20" data-test="stage-rejected">
                        <flux:icon.x-circle variant="mini" class="mt-0.5 size-5 shrink-0 text-rose-600 dark:text-rose-300" />
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold text-rose-900 dark:text-rose-100">{{ __('Not approved') }}</p>
                            @if ($this->script->review_note)<p class="text-rose-800 dark:text-rose-200"><span class="font-medium">{{ __("Admin's reason:") }}</span> {{ $this->script->review_note }}</p>@endif
                            <p class="mt-1 text-rose-800/80 dark:text-rose-200/80">{{ __('Change it, then submit again.') }}</p>
                        </div>
                    </div>
                @endif
                @if (! in_array($stage, ['review', 'approved', 'shot'], true))
                    <flux:button variant="primary" icon="paper-airplane" wire:click="submitScript" class="w-full shadow-md shadow-brand-500/20" data-test="submit-script">{{ $stage === 'rejected' ? __('Submit again') : __('Submit for approval') }}</flux:button>
                    <flux:error name="body" />
                @endif
            @else
                @if ($this->script->status === 'approved')
                    {{-- Admin: the approved script was filmed --}}
                    <section class="{{ $panel }} flex flex-col gap-3 p-4 ring-emerald-300/70 dark:ring-emerald-500/30" data-test="shot-panel">
                        <p class="flex items-center gap-2 text-sm font-semibold text-emerald-800 dark:text-emerald-200"><flux:icon.check-badge variant="mini" class="size-4.5" />{{ __('Approved, ready to shoot') }}</p>
                        <flux:button variant="primary" icon="video-camera" wire:click="markShot" wire:confirm="{{ __('Mark “:title” as shot?', ['title' => $this->script->title]) }}" class="w-full" data-test="mark-shot">{{ __('Mark as shot') }}</flux:button>
                    </section>
                @endif
                @if ($this->script->status === 'review')
                    {{-- Admin: decide on a submitted script --}}
                    <section class="{{ $panel }} flex flex-col gap-3 p-4 ring-amber-300/70 dark:ring-amber-500/30" data-test="review-panel">
                        <p class="flex items-center gap-2 text-sm font-semibold text-amber-800 dark:text-amber-200"><flux:icon.clock variant="mini" class="size-4.5" />{{ __('Waiting for your approval') }}</p>
                        <flux:textarea wire:model="reviewNote" rows="2" :placeholder="__('Note for the writer (needed if not approved)')" data-test="review-note" />
                        <div class="grid grid-cols-2 gap-2">
                            <flux:button variant="danger" icon="x-mark" wire:click="rejectScript" data-test="reject-script">{{ __('Not approved') }}</flux:button>
                            <flux:button variant="primary" icon="check" wire:click="approveScript" data-test="approve-script">{{ __('Approve') }}</flux:button>
                        </div>
                    </section>
                @endif
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
            @endif
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
