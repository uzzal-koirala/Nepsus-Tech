{{-- Add / edit / delete task modals for components using App\Concerns\ManagesTasks. --}}
@php
    $formTypes = $this->types;
    $formTone = fn ($slug) => \App\Models\WorkType::PALETTE[$formTypes[$slug]['color'] ?? 'zinc'] ?? \App\Models\WorkType::PALETTE['zinc'];
    $strict = $this->needsClientAndDate();
    $clientOptions = collect($strict ? [] : [['value' => '', 'label' => __('No client'), 'hint' => __('Internal work for the team'), 'icon' => 'building-office']])
        ->concat($this->clients->map(fn ($c) => ['value' => $c->id, 'label' => $c->displayName(), 'hint' => $c->company ? $c->name : $c->phone, 'initials' => $c->initials(), 'tile' => 'bg-linear-to-br from-brand-500 to-violet-600 text-white']));
    $typeOptions = collect($formTypes)->map(fn ($t, $slug) => ['value' => $slug, 'label' => $t['label'], 'icon' => $t['icon'], 'tile' => $formTone($slug)['chip']])->values();
    $priorityOptions = collect(\App\Models\Task::PRIORITIES)->map(fn ($label, $k) => ['value' => $k, 'label' => __($label), 'icon' => \App\Models\Task::PRIORITY_STYLES[$k]['icon'], 'tile' => \App\Models\Task::PRIORITY_STYLES[$k]['tone']])->values();
    $boxOptions = collect([['value' => '', 'label' => __('No box'), 'hint' => __('A single task'), 'icon' => 'square-2-stack']])
        ->concat($this->boxes->map(fn ($b) => ['value' => $b->id, 'label' => $b->name, 'icon' => 'archive-box', 'tile' => (\App\Models\WorkType::PALETTE[$b->color] ?? \App\Models\WorkType::PALETTE['violet'])['dot'].' text-white']));
    $statusOptions = collect(\App\Models\Task::STATUSES)->map(fn ($s, $k) => ['value' => $k, 'label' => __($s['label']), 'icon' => $s['icon']])->values();
@endphp

<flux:modal name="task-form" class="w-full max-w-2xl overflow-visible!">
    <form wire:submit="saveTask" class="flex flex-col gap-5">
        <div class="flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.clipboard-document-check variant="mini" class="size-5" /></span>
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit task') : __('New task') }}</flux:heading>
                <flux:text>{{ __('Say what needs doing, by when, and who does it.') }}</flux:text>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><flux:input wire:model="task.title" :label="__('Task')" :placeholder="__('e.g. Design 4 posts for Dashain')" required data-test="task-title" /></div>
            @if ($this->boxes->isNotEmpty())
                <x-rich-select model="task.task_group_id" class="sm:col-span-2" :label="__('Box')" :options="$boxOptions" :searchable="$this->boxes->count() > 6" />
            @endif
            <x-rich-select model="task.client_id" class="sm:col-span-2" :label="$strict ? __('Client') : __('Client (optional)')" :placeholder="__('Pick a client')" :options="$clientOptions" searchable />
            <x-rich-select model="task.type" :label="__('Work type')" :options="$typeOptions" :searchable="count($formTypes) > 6" />
            <x-nepali-date wire:model="task.due_date" :label="$strict ? __('Due date') : __('Due date (optional)')" />
            <x-rich-select model="task.priority" :label="__('Priority')" :options="$priorityOptions" />
            <x-rich-select model="task.status" :label="__('Status')" :options="$statusOptions" />
            @if ($this->people->isNotEmpty())
                <div class="sm:col-span-2">
                    <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Assign to') }}</p>
                    <div class="flex max-h-44 flex-wrap gap-2 overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        @foreach ($this->people as $e)
                            <label wire:key="as-{{ $e->id }}" class="group/p flex cursor-pointer items-center gap-2 rounded-full py-1 ps-1 pe-3 text-sm text-zinc-700 ring-1 ring-zinc-200 transition hover:ring-zinc-300 has-checked:bg-brand-50 has-checked:text-brand-700 has-checked:ring-2 has-checked:ring-brand-500 dark:text-zinc-200 dark:ring-white/10 dark:has-checked:bg-brand-500/10 dark:has-checked:text-brand-200" data-test="assign-{{ $e->id }}">
                                <input type="checkbox" wire:model="task.assignees" value="{{ $e->id }}" class="peer sr-only">
                                <x-employee-avatar :employee="$e" class="size-6 text-[0.625rem]" />
                                {{ $e->name }}
                                <flux:icon.check variant="micro" class="hidden size-3.5 peer-checked:block" />
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="sm:col-span-2"><flux:textarea wire:model="task.description" :label="__('Details')" rows="2" :placeholder="__('Brief, links, captions...')" /></div>
        </div>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary" icon="check" data-test="save-task">{{ __('Save task') }}</flux:button>
        </div>
    </form>
</flux:modal>

<flux:modal name="task-delete" class="w-full max-w-sm">
    <div class="flex flex-col items-center gap-4 text-center">
        <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.trash class="size-7" /></span>
        <div>
            <flux:heading size="lg">{{ __('Delete this task?') }}</flux:heading>
            <flux:text class="mt-1">{{ __('It will be removed everywhere, including the client profile.') }}</flux:text>
        </div>
        <div class="flex w-full gap-2">
            <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button variant="danger" wire:click="deleteTask" class="flex-1" data-test="confirm-delete">{{ __('Delete') }}</flux:button>
        </div>
    </div>
</flux:modal>
