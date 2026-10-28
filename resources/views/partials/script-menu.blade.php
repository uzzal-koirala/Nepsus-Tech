{{-- ⋮ on a script row (Scripts page): view, copy, edit, completed. Needs $s. --}}
<flux:dropdown align="end">
    <button type="button" class="flex size-8 shrink-0 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5 dark:hover:text-zinc-200" aria-label="{{ __('More') }}" data-test="script-menu-{{ $s->id }}"><flux:icon.ellipsis-vertical variant="mini" class="size-4" /></button>
    <flux:menu>
        <flux:menu.item icon="eye" wire:click="viewScript({{ $s->id }})">{{ __('View') }}</flux:menu.item>
        <flux:menu.item icon="clipboard-document" x-on:click="navigator.clipboard.writeText(@js((string) $s->body)); $flux.toast({ text: @js(__('Script copied.')) })">{{ __('Copy script') }}</flux:menu.item>
        <flux:menu.item icon="pencil-square" :href="route('scripts.edit', $s)" wire:navigate>{{ __('Edit') }}</flux:menu.item>
        @if ($s->status === 'approved')
            <flux:menu.separator />
            <flux:menu.item icon="check-circle" wire:click="complete({{ $s->id }})" data-test="complete-{{ $s->id }}">{{ __('Completed') }}</flux:menu.item>
        @endif
    </flux:menu>
</flux:dropdown>
