<div wire:key="doc-{{ $doc->id }}" class="group flex items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2.5 ring-1 ring-zinc-200/60 transition hover:ring-brand-200 dark:bg-white/[0.03] dark:ring-white/5">
    <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-white text-brand-500 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10">
        <flux:icon.document-text variant="mini" class="size-4.5" />
    </span>
    <div class="min-w-0 flex-1">
        <a href="{{ route('documents.download', $doc) }}" class="block truncate text-sm font-medium text-zinc-800 hover:text-brand-600 dark:text-zinc-100">{{ $doc->name }}</a>
        <p class="truncate text-xs text-zinc-500">{{ __(\App\Models\Document::TYPES[$doc->type] ?? 'Other') }} · {{ $fileSize($doc->size) }} · <span title="{{ $doc->created_at->format('j M Y') }}">{{ $doc->created_at->toNepali() }}</span></p>
    </div>
    <a href="{{ route('documents.download', $doc) }}" class="text-zinc-400 hover:text-brand-600" aria-label="{{ __('Download :name', ['name' => $doc->name]) }}">
        <flux:icon.arrow-down-tray variant="micro" class="size-4" />
    </a>
    <button type="button" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="{{ __('Delete this document?') }}" class="text-zinc-300 hover:text-rose-500" aria-label="{{ __('Delete :name', ['name' => $doc->name]) }}">
        <flux:icon.trash variant="micro" class="size-4" />
    </button>
</div>
