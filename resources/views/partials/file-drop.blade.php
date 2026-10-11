<label class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border border-dashed border-zinc-300 px-4 py-6 text-center transition hover:border-brand-400 hover:bg-brand-50/40 dark:border-zinc-700 dark:hover:bg-brand-500/5">
    <flux:icon :icon="$file ? 'document-check' : 'arrow-up-tray'" class="size-5 {{ $file ? 'text-emerald-500' : 'text-zinc-400' }}" />
    <span class="max-w-full truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">
        {{ $file ? $file->getClientOriginalName() : __('Choose a file') }}
    </span>
    <span class="text-xs text-zinc-400">{{ __('PDF, Word or image, up to 10 MB') }}</span>
    <input type="file" wire:model="{{ $model }}" class="sr-only" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp">
</label>
<div wire:loading wire:target="{{ $model }}" class="text-xs text-zinc-500">{{ __('Uploading…') }}</div>
<flux:error :name="$model" />
