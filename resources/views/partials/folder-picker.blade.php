{{-- Folder dropdown for the Documents page: pick, add or delete a folder. Needs $model (Livewire property) and $folders. --}}
<div class="relative grid gap-2" x-data="{ open: false, value: $wire.entangle('{{ $model }}').live }" @click.outside="open = false" @keydown.escape.stop="open = false">
    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Folder') }}</span>
    <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="listbox"
        class="flex h-10 w-full items-center gap-2.5 rounded-lg border border-zinc-200 bg-white px-2 text-start text-sm shadow-xs transition hover:border-zinc-300 dark:border-white/10 dark:bg-white/5"
        :class="open && 'border-brand-400 ring-2 ring-brand-500/20'" data-test="folder-picker-{{ $model }}">
        @foreach ($folders as $slug => $f)
            <span x-show="value === @js($slug)" x-cloak class="flex min-w-0 items-center gap-2.5">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon :icon="$f['icon']" variant="micro" class="size-4" /></span>
                <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $f['label'] }}</span>
            </span>
        @endforeach
        <flux:icon.chevron-down variant="micro" class="ms-auto size-4 shrink-0 text-zinc-400 transition" ::class="open && 'rotate-180'" />
    </button>

    <div x-show="open" x-cloak x-transition.origin.top class="absolute inset-x-0 top-full z-40 mt-1.5 overflow-hidden rounded-xl bg-white shadow-[0_24px_60px_-16px_rgb(15_23_42/0.4)] ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10" role="listbox">
        <div class="flex max-h-60 flex-col gap-0.5 overflow-y-auto p-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($folders as $slug => $f)
                <div wire:key="fp-{{ $model }}-{{ $slug }}" class="group flex items-center gap-1 rounded-lg transition hover:bg-zinc-50 dark:hover:bg-white/5" :class="value === @js($slug) && 'bg-brand-50/80 dark:bg-brand-500/10'">
                    <button type="button" role="option" @click="value = @js($slug); open = false" class="flex min-w-0 flex-1 items-center gap-2.5 px-2.5 py-2 text-start">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-300"><flux:icon :icon="$f['icon']" variant="micro" class="size-4" /></span>
                        <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $f['label'] }}</span>
                        <flux:icon.check variant="mini" class="ms-auto size-4 text-brand-600" x-show="value === @js($slug)" />
                    </button>
                    @if ($slug !== \App\Models\DocumentFolder::FALLBACK)
                        <button type="button" wire:click="confirmDeleteFolder('{{ $slug }}')" @click="open = false" class="me-1.5 rounded-md p-1.5 text-zinc-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-500 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="{{ __('Delete folder :name', ['name' => $f['label']]) }}" data-test="delete-folder-{{ $slug }}">
                            <flux:icon.trash variant="micro" class="size-3.5" />
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="flex items-center gap-1.5 border-t border-zinc-100 bg-zinc-50/70 p-2 dark:border-white/5 dark:bg-white/[0.02]">
            <input type="text" wire:model="newFolder" @keydown.enter.prevent="$wire.addFolder('{{ $model }}')" placeholder="{{ __('New folder name') }}" maxlength="40"
                class="h-8 min-w-0 flex-1 rounded-lg border-0 bg-white px-2.5 text-sm ring-1 ring-zinc-200 placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 dark:bg-white/5 dark:text-white dark:ring-white/10" data-test="new-folder">
            <button type="button" wire:click="addFolder('{{ $model }}')" class="inline-flex h-8 items-center gap-1 rounded-lg bg-brand-500 px-2.5 text-xs font-semibold text-white transition hover:bg-brand-600"><flux:icon.plus variant="micro" class="size-3.5" />{{ __('Add') }}</button>
        </div>
    </div>
    <flux:error name="newFolder" />
</div>
