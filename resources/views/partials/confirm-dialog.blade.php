{{-- The app's "Are you sure?" box. Opened by resources/js/confirm.js for every wire:confirm and appConfirm(). --}}
<dialog id="app-confirm" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-text"
    class="m-auto w-[calc(100%-2rem)] max-w-sm overflow-hidden rounded-2xl bg-white p-0 text-zinc-900 shadow-[0_30px_80px_-20px_rgb(15_23_42/0.45)] ring-1 ring-zinc-200 backdrop:bg-zinc-950/40 backdrop:backdrop-blur-sm open:animate-[confirm-in_180ms_cubic-bezier(0.32,0.72,0,1)] dark:bg-zinc-900 dark:text-white dark:ring-white/10">
    <form method="dialog" class="flex flex-col gap-5 p-6">
        <div class="flex items-start gap-4">
            {{-- Danger (delete, remove) or a normal question --}}
            <span data-confirm-icon="danger" class="flex size-11 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-600 ring-8 ring-rose-50/50 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/5">
                <flux:icon.trash variant="mini" class="size-5" />
            </span>
            <span data-confirm-icon="question" class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 ring-8 ring-brand-50/50 dark:bg-brand-500/15 dark:text-brand-300 dark:ring-brand-500/5">
                <flux:icon.question-mark-circle variant="mini" class="size-5" />
            </span>
            <div class="min-w-0 pt-0.5">
                <h2 id="app-confirm-title" class="text-base font-semibold">{{ __('Are you sure?') }}</h2>
                <p id="app-confirm-text" class="mt-1 text-sm leading-relaxed whitespace-pre-line text-zinc-600 dark:text-zinc-300"></p>
            </div>
        </div>
        <div class="flex gap-2">
            <button value="cancel" class="h-10 flex-1 rounded-xl bg-white text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 transition hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:outline-none dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10 dark:hover:bg-white/10" data-test="confirm-cancel">{{ __('Cancel') }}</button>
            <button value="ok" data-confirm-ok class="h-10 flex-1 rounded-xl text-sm font-semibold text-white shadow-md transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none data-[tone=danger]:bg-rose-600 data-[tone=danger]:shadow-rose-600/25 data-[tone=danger]:hover:bg-rose-700 data-[tone=danger]:focus-visible:ring-rose-400 data-[tone=question]:bg-brand-500 data-[tone=question]:shadow-brand-500/25 data-[tone=question]:hover:bg-brand-600 data-[tone=question]:focus-visible:ring-brand-400 dark:focus-visible:ring-offset-zinc-900" data-test="confirm-ok"
                data-label-danger="{{ __('Delete') }}" data-label-question="{{ __('Yes, continue') }}"></button>
        </div>
    </form>
</dialog>
