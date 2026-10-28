{{-- Shown to Video Editors in the task forms: they add tasks for themselves only. --}}
<div class="flex gap-2.5 rounded-xl bg-amber-50 px-3.5 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20" data-test="editor-task-warning">
    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-amber-500" />
    <p><span class="font-semibold">{{ __('Add only tasks you really need.') }}</span> {{ __('This task is for you only. Adding unnecessary tasks just to show work can lead to a salary deduction.') }}</p>
</div>
