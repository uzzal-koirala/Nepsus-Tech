<?php

namespace App\Concerns;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskGroup;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/** The add / edit / tick off / delete task flow shared by the Tasks and Calendar pages (form: partials.task-form). */
trait ManagesTasks
{
    public ?int $editingId = null;

    /** @var array<string, mixed> Add / edit task. */
    public array $task = [];

    public ?int $deletingId = null;

    /**
     * Livewire runs this on mount for every page using the trait. The form's dropdowns bind to task.client_id,
     * task.type and so on as soon as the page loads, so the keys must exist then; with an empty array they
     * fail to connect and a picked client is never sent.
     */
    public function mountManagesTasks(): void
    {
        $this->task = $this->blankTask();
    }

    /** The calendar only holds client work with a date. */
    public function needsClientAndDate(): bool
    {
        return false;
    }

    protected function taskSaved(Task $task): void {}

    /** A task this login may change: admins and Content Creators reach every task, other employee logins only their own. */
    protected function findTask(int $id): Task
    {
        $user = auth()->user();

        return Task::with('assignees')
            ->unless($user->canPlanContent(), fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->employee?->id ?? 0)))
            ->findOrFail($id);
    }

    protected function blankTask(?string $date = null, array $preset = []): array
    {
        return $preset + ['task_group_id' => '', 'client_id' => '', 'title' => '', 'type' => array_key_first($this->types) ?? '', 'priority' => 'medium', 'status' => 'todo', 'due_date' => $date ?? '', 'description' => '', 'assignees' => []];
    }

    #[Computed]
    public function types(): array
    {
        return Task::types();
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('company')->orderBy('name')->get();
    }

    #[Computed]
    public function boxes()
    {
        return TaskGroup::orderBy('position')->orderBy('id')->get();
    }

    #[Computed]
    public function people()
    {
        return Employee::where('status', '!=', 'inactive')->orderBy('name')->get();
    }

    public function newTask(?string $date = null, ?string $status = null): void
    {
        $this->editingId = null;
        $this->task = $this->blankTask($date, $status && array_key_exists($status, Task::STATUSES) ? ['status' => $status] : []);
        $this->resetErrorBag();
        Flux::modal('task-form')->show();
    }

    public function editTask(int $id): void
    {
        $t = $this->findTask($id);
        $this->editingId = $t->id;
        $this->task = ['task_group_id' => (string) $t->task_group_id, 'client_id' => (string) $t->client_id, 'title' => $t->title, 'type' => $t->type, 'priority' => $t->priority, 'status' => $t->stage(), 'due_date' => (string) $t->due_date?->toDateString(), 'description' => (string) $t->description, 'assignees' => $t->assignees->pluck('id')->map('strval')->all()];
        $this->resetErrorBag();
        Flux::modal('task-form')->show();
    }

    public function saveTask(): void
    {
        $strict = $this->needsClientAndDate();
        $data = $this->validate([
            'task.task_group_id' => ['nullable', 'exists:task_groups,id'],
            'task.client_id' => [$strict ? 'required' : 'nullable', 'exists:clients,id'],
            'task.title' => ['required', 'string', 'max:255'],
            'task.type' => ['required', Rule::exists('work_types', 'slug')],
            'task.priority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
            'task.status' => ['required', Rule::in(array_keys(Task::STATUSES))],
            'task.due_date' => [$strict ? 'required' : 'nullable', 'date'],
            'task.description' => ['nullable', 'string', 'max:2000'],
            'task.assignees' => ['array'],
            'task.assignees.*' => ['integer', Rule::exists('employees', 'id')],
        ], attributes: ['task.client_id' => __('client'), 'task.title' => __('task'), 'task.due_date' => __('due date'), 'task.type' => __('work type')])['task'];

        $t = $this->editingId ? $this->findTask($this->editingId) : new Task;
        $t->task_group_id = $data['task_group_id'] ?: null;
        // A task in a client's box belongs to that client unless one was picked.
        $t->client_id = $data['client_id'] ?: TaskGroup::find($t->task_group_id)?->client_id;
        $t->fill([
            'title' => $data['title'], 'type' => $data['type'], 'priority' => $data['priority'], 'status' => $data['status'],
            'due_date' => $data['due_date'] ?: null, 'description' => $data['description'] ?: null,
            'completed_at' => $data['status'] === 'done' ? ($t->completed_at ?? now()) : null,
        ])->save();
        $assignees = $data['assignees'] ?? [];
        // An employee who only sees their own work stays on the tasks they add or change, so the task does not vanish from their view.
        if (! auth()->user()->canPlanContent() && $me = auth()->user()->employee?->id) {
            $assignees = array_values(array_unique([...array_map('intval', $assignees), $me]));
        }
        $t->assignees()->sync($assignees);

        $this->taskSaved($t);
        Flux::modal('task-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Task updated.') : __('Task added.'));
    }

    public function toggleDone(int $id): void
    {
        $t = $this->findTask($id);
        $t->update($t->completed_at ? ['completed_at' => null, 'status' => 'todo'] : ['completed_at' => now(), 'status' => 'done']);
    }

    public function moveTask(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, Task::STATUSES), 422);
        $t = $this->findTask($id);
        $t->update(['status' => $status, 'completed_at' => $status === 'done' ? ($t->completed_at ?? now()) : null]);
    }

    public function confirmDelete(int $id): void
    {
        abort_unless(auth()->user()->canPlanContent(), 403);
        $this->deletingId = $id;
        Flux::modal('task-delete')->show();
    }

    public function deleteTask(): void
    {
        abort_unless(auth()->user()->canPlanContent(), 403);
        Task::whereKey($this->deletingId)->delete();
        $this->deletingId = null;
        Flux::modal('task-delete')->close();
        Flux::toast(text: __('Task deleted.'));
    }
}
