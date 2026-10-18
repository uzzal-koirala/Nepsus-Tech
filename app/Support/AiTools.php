<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\Transaction;
use App\Models\WorkType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the AI assistant may look up and change. Every input is validated here,
 * because the model's arguments are untrusted. Deleting is left to people.
 */
class AiTools
{
    /** @var list<array{icon: string, text: string, url: ?string}> Changes made this turn, shown under the reply. */
    public array $actions = [];

    /** @return list<array<string, mixed>> Tool definitions in the PHP SDK's camelCase shape. */
    public static function definitions(): array
    {
        $date = ['type' => 'string', 'description' => 'YYYY-MM-DD. Nepali (Bikram Sambat) dates are fine: set calendar to "bs".'];
        $calendar = ['type' => 'string', 'enum' => ['bs', 'ad'], 'description' => 'Calendar of the dates you send. "bs" for Nepali dates like 2083-07-15, "ad" for English dates. Default "ad".'];
        $priority = ['type' => 'string', 'enum' => array_keys(Task::PRIORITIES)];
        $ids = ['type' => 'array', 'items' => ['type' => 'integer']];

        return [
            [
                'name' => 'find_clients',
                'description' => 'Search clients by business or contact name. Returns id, name, business, phone and status. Call this to get a client_id before creating work for a client.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Part of the name; empty lists everyone.']]],
            ],
            [
                'name' => 'find_people',
                'description' => 'Search the team (employees) by name. Returns id, name, job title and how many open tasks each has. Call this to get assignee ids.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            ],
            [
                'name' => 'list_boxes',
                'description' => 'List task boxes (named groups of tasks, like "Dashain campaign") with progress.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'list_tasks',
                'description' => 'List tasks with filters. Use for questions like "what is overdue", "what does Ram have this week", "tasks for Everest Gym".',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['open', 'done', 'overdue', 'all'], 'description' => 'Default "open".'],
                    'assignee_id' => ['type' => 'integer'],
                    'client_id' => ['type' => 'integer'],
                    'box_id' => ['type' => 'integer'],
                    'due_from' => $date,
                    'due_to' => $date,
                    'calendar' => $calendar,
                    'limit' => ['type' => 'integer', 'description' => 'Default 30, max 100.'],
                ]],
            ],
            [
                'name' => 'create_task',
                'description' => 'Create a task. Find client, box and people ids first. If a box belongs to a client, the task gets that client automatically.',
                'inputSchema' => ['type' => 'object', 'required' => ['title'], 'properties' => [
                    'title' => ['type' => 'string'],
                    'client_id' => ['type' => 'integer'],
                    'box_id' => ['type' => 'integer'],
                    'work_type' => ['type' => 'string', 'description' => 'Type of work by name, e.g. Post, Reel, Video shoot, Design, Meeting, Task.'],
                    'priority' => $priority,
                    'due_date' => $date,
                    'calendar' => $calendar,
                    'assignee_ids' => $ids,
                    'details' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'update_task',
                'description' => 'Change a task: mark it done or reopen it, move it, rename it, change date, priority, box or people. Only send what changes. assignee_ids replaces the whole list.',
                'inputSchema' => ['type' => 'object', 'required' => ['task_id'], 'properties' => [
                    'task_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => array_keys(Task::STATUSES)],
                    'priority' => $priority,
                    'due_date' => $date,
                    'calendar' => $calendar,
                    'box_id' => ['type' => 'integer'],
                    'assignee_ids' => $ids,
                ]],
            ],
            [
                'name' => 'create_box',
                'description' => 'Create a task box: a titled group of tasks, optionally for a client.',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string'],
                    'client_id' => ['type' => 'integer'],
                    'priority' => $priority,
                    'due_date' => $date,
                    'calendar' => $calendar,
                ]],
            ],
            [
                'name' => 'business_overview',
                'description' => 'Numbers for this Nepali month: clients, money in and out, money owed by clients, unpaid invoices, open leads, task status.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    /** Runs one tool. Returns a JSON string for the tool_result; throws ValidationException on bad input. */
    public function run(string $name, array $input): string
    {
        $result = match ($name) {
            'find_clients' => $this->findClients($input),
            'find_people' => $this->findPeople($input),
            'list_boxes' => $this->listBoxes(),
            'list_tasks' => $this->listTasks($input),
            'create_task' => $this->createTask($input),
            'update_task' => $this->updateTask($input),
            'create_box' => $this->createBox($input),
            'business_overview' => $this->overview(),
            default => throw ValidationException::withMessages(['tool' => "Unknown tool {$name}."]),
        };

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules + ['calendar' => ['nullable', Rule::in(['bs', 'ad'])]])->validate();
    }

    /** A date the model sent, in AD or BS. */
    private function date(?string $value, ?string $calendar): ?Carbon
    {
        if (! $value) {
            return null;
        }
        if (($calendar ?? 'ad') === 'bs') {
            if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) || $m[2] < 1 || $m[2] > 12 || $m[3] < 1 || $m[3] > NepaliDate::monthLength((int) $m[1], (int) $m[2])) {
                throw ValidationException::withMessages(['date' => "{$value} is not a valid Nepali date."]);
            }

            return NepaliDate::toAd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return Carbon::parse($value);
    }

    private function show(?CarbonInterface $d): ?string
    {
        return $d ? NepaliDate::format($d).' ('.$d->toDateString().')' : null;
    }

    private function workType(?string $wanted): string
    {
        $types = Task::types();
        if ($wanted) {
            foreach ($types as $slug => $t) {
                if (strcasecmp($slug, $wanted) === 0 || strcasecmp($t['label'], $wanted) === 0) {
                    return $slug;
                }
            }
        }

        return array_key_exists('task', $types) ? 'task' : (string) array_key_first($types);
    }

    private function taskRow(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->stage(),
            'priority' => $t->priority,
            'work_type' => (Task::types()[$t->type] ?? Task::unknownType($t->type))['label'],
            'due' => $this->show($t->due_date),
            'overdue' => $t->isOverdue(),
            'client' => $t->client?->displayName(),
            'box' => $t->group?->name,
            'assigned_to' => $t->assignees->pluck('name')->all(),
        ];
    }

    private function findClients(array $input): array
    {
        $q = trim((string) ($input['query'] ?? ''));

        return Client::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('company', 'like', "%{$q}%")))
            ->orderBy('company')->orderBy('name')->limit(25)->get()
            ->map(fn ($c) => ['id' => $c->id, 'business' => $c->company, 'contact' => $c->name, 'phone' => $c->phone, 'status' => $c->status])
            ->all();
    }

    private function findPeople(array $input): array
    {
        $q = trim((string) ($input['query'] ?? ''));

        return Employee::where('status', '!=', 'inactive')
            ->when($q !== '', fn ($w) => $w->where('name', 'like', "%{$q}%"))
            ->withCount(['tasks as open_tasks' => fn ($w) => $w->whereNull('completed_at')])
            ->orderBy('name')->limit(25)->get()
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'job' => $e->designation, 'open_tasks' => $e->open_tasks])
            ->all();
    }

    private function listBoxes(): array
    {
        return TaskGroup::with('client')->withCount(['tasks', 'tasks as done_count' => fn ($w) => $w->whereNotNull('completed_at')])
            ->orderBy('position')->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'client' => $b->client?->displayName(), 'tasks' => $b->tasks_count, 'done' => $b->done_count, 'due' => $this->show($b->due_date), 'priority' => $b->priority])
            ->all();
    }

    private function listTasks(array $input): array
    {
        $d = $this->validate($input, [
            'status' => ['nullable', Rule::in(['open', 'done', 'overdue', 'all'])],
            'assignee_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'], 'box_id' => ['nullable', 'integer'],
            'due_from' => ['nullable', 'string'], 'due_to' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = $this->date($d['due_from'] ?? null, $d['calendar'] ?? null);
        $to = $this->date($d['due_to'] ?? null, $d['calendar'] ?? null);

        $tasks = Task::with(['client', 'group', 'assignees'])
            ->when(($d['status'] ?? 'open') === 'open', fn ($q) => $q->whereNull('completed_at'))
            ->when(($d['status'] ?? null) === 'done', fn ($q) => $q->whereNotNull('completed_at'))
            ->when(($d['status'] ?? null) === 'overdue', fn ($q) => $q->whereNull('completed_at')->whereDate('due_date', '<', today()))
            ->when($d['assignee_id'] ?? null, fn ($q, $id) => $q->whereHas('assignees', fn ($w) => $w->whereKey($id)))
            ->when($d['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($d['box_id'] ?? null, fn ($q, $id) => $q->where('task_group_id', $id))
            ->when($from, fn ($q) => $q->whereDate('due_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('due_date', '<=', $to))
            ->orderByRaw('due_date is null')->orderBy('due_date')
            ->limit($d['limit'] ?? 30)->get();

        return ['count' => $tasks->count(), 'tasks' => $tasks->map(fn ($t) => $this->taskRow($t))->all()];
    }

    private function createTask(array $input): array
    {
        $d = $this->validate($input, [
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'box_id' => ['nullable', 'integer', 'exists:task_groups,id'],
            'work_type' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'due_date' => ['nullable', 'string'],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['integer', 'exists:employees,id'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);
        $box = isset($d['box_id']) ? TaskGroup::find($d['box_id']) : null;

        $t = DB::transaction(function () use ($d, $box) {
            $t = new Task([
                'title' => $d['title'], 'description' => $d['details'] ?? null, 'type' => $this->workType($d['work_type'] ?? null),
                'priority' => $d['priority'] ?? 'medium', 'status' => 'todo',
                'due_date' => $this->date($d['due_date'] ?? null, $d['calendar'] ?? null),
            ]);
            $t->client_id = $d['client_id'] ?? $box?->client_id;
            $t->task_group_id = $box?->id;
            $t->save();
            $t->assignees()->sync($d['assignee_ids'] ?? []);

            return $t->load(['client', 'group', 'assignees']);
        });

        $this->actions[] = ['icon' => 'plus-circle', 'text' => __('Created task “:title”', ['title' => $t->title]), 'url' => route('tasks.index', ['view' => $t->task_group_id ? 'boxes' : 'list'])];

        return ['created' => $this->taskRow($t)];
    }

    private function updateTask(array $input): array
    {
        $d = $this->validate($input, [
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_keys(Task::STATUSES))],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'due_date' => ['nullable', 'string'],
            'box_id' => ['nullable', 'integer', 'exists:task_groups,id'],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['integer', 'exists:employees,id'],
        ]);
        $t = Task::findOrFail($d['task_id']);

        foreach (['title', 'priority'] as $f) {
            if (isset($d[$f])) {
                $t->{$f} = $d[$f];
            }
        }
        if (isset($d['status'])) {
            $t->status = $d['status'];
            $t->completed_at = $d['status'] === 'done' ? ($t->completed_at ?? now()) : null;
        }
        if (isset($d['due_date'])) {
            $t->due_date = $this->date($d['due_date'], $d['calendar'] ?? null);
        }
        if (isset($d['box_id'])) {
            $t->task_group_id = $d['box_id'];
        }
        $t->save();
        if (array_key_exists('assignee_ids', $d) && $d['assignee_ids'] !== null) {
            $t->assignees()->sync($d['assignee_ids']);
        }

        $t->load(['client', 'group', 'assignees']);
        $this->actions[] = ['icon' => $t->completed_at ? 'check-circle' : 'pencil-square', 'text' => __('Updated task “:title”', ['title' => $t->title]), 'url' => route('tasks.index', ['view' => 'list'])];

        return ['updated' => $this->taskRow($t)];
    }

    private function createBox(array $input): array
    {
        $d = $this->validate($input, [
            'name' => ['required', 'string', 'max:120'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'due_date' => ['nullable', 'string'],
        ]);
        $colors = array_values(array_diff(array_keys(WorkType::PALETTE), ['zinc']));
        $b = TaskGroup::create([
            'name' => $d['name'], 'client_id' => $d['client_id'] ?? null, 'priority' => $d['priority'] ?? 'medium',
            'due_date' => $this->date($d['due_date'] ?? null, $d['calendar'] ?? null),
            'color' => $colors[TaskGroup::count() % count($colors)], 'position' => (int) TaskGroup::max('position') + 1,
        ]);
        $this->actions[] = ['icon' => 'folder-plus', 'text' => __('Created box “:name”', ['name' => $b->name]), 'url' => route('tasks.index')];

        return ['created_box' => ['id' => $b->id, 'name' => $b->name]];
    }

    private function overview(): array
    {
        [$start, $end] = NepaliDate::monthRange(today());
        $money = fn ($v) => Client::money(round((float) $v));
        $tx = Transaction::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
        $received = Payment::whereBetween('paid_at', [$start, $end])->sum('amount');
        $salaries = SalaryPayment::whereBetween('paid_on', [$start->toDateString(), $end->toDateString()])->sum('amount');
        $openTasks = Task::whereNull('completed_at');

        return [
            'month' => NepaliDate::monthYear(today()),
            'today' => $this->show(today()),
            'clients_active' => Client::where('status', 'active')->count(),
            'clients_total' => Client::count(),
            'money_in_this_month' => $money($received + $tx->where('kind', 'income')->sum('amount')),
            'client_payments_received_this_month' => $money($received),
            'expenses_this_month' => $money($tx->where('kind', 'expense')->sum('amount') + $salaries),
            'salaries_paid_this_month' => $money($salaries),
            'owed_by_clients' => $money(Payment::whereNull('paid_at')->sum('amount')),
            'overdue_client_payments' => $money(Payment::whereNull('paid_at')->whereDate('due_date', '<', today())->sum('amount')),
            'unpaid_invoices' => Invoice::where('type', 'invoice')->where('status', '!=', 'paid')->get()->filter(fn ($i) => in_array($i->displayStatus(), ['sent', 'overdue', 'draft'], true))->count(),
            'open_leads' => Lead::whereNotIn('stage', ['won', 'lost'])->count(),
            'open_tasks' => (clone $openTasks)->count(),
            'overdue_tasks' => (clone $openTasks)->whereDate('due_date', '<', today())->count(),
            'tasks_due_today' => (clone $openTasks)->whereDate('due_date', today())->count(),
        ];
    }
}
