<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Models\Setting;
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
 * What the AI may look up and change: the in-app assistant and outside assistants over MCP. Every input is validated here,
 * because the model's arguments are untrusted. Deletes keep a copy in storage/app/ai-trash.
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
                'name' => 'calendar',
                'description' => 'The calendar: tasks by due day, like the Calendar page. Use for "what is on 5 Kartik", "this week", "next week", "this month", "what does Ram have tomorrow". Give a Nepali month (e.g. 2083-07) or a from/to range. Includes done tasks unless you ask for open only.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'month' => ['type' => 'string', 'description' => 'Nepali month as YYYY-MM, e.g. 2083-07 for Kartik 2083. Use this or from/to.'],
                    'from' => $date,
                    'to' => $date,
                    'calendar' => $calendar,
                    'assignee_id' => ['type' => 'integer'],
                    'client_id' => ['type' => 'integer'],
                    'open_only' => ['type' => 'boolean', 'description' => 'Only tasks not done yet.'],
                ]],
            ],
            [
                'name' => 'get_client',
                'description' => 'Everything about one client: contact, service, price, contract, payments owed and paid, open tasks.',
                'inputSchema' => ['type' => 'object', 'required' => ['client_id'], 'properties' => ['client_id' => ['type' => 'integer']]],
            ],
            [
                'name' => 'create_client',
                'description' => 'Add a new client. Check with find_clients first that they are not already there.',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Contact person'],
                    'company' => ['type' => 'string', 'description' => 'Business name'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'service_type' => ['type' => 'string', 'enum' => array_keys(Client::SERVICE_TYPES)],
                    'notes' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'update_client',
                'description' => 'Change a client: contact details, business name, active or inactive, notes. Only send what changes.',
                'inputSchema' => ['type' => 'object', 'required' => ['client_id'], 'properties' => [
                    'client_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'company' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => Client::STATUSES],
                    'notes' => ['type' => 'string'],
                    'package_id' => ['type' => 'integer', 'description' => 'Give the client this package (its price and billing are copied). 0 removes the package.'],
                ]],
            ],
            [
                'name' => 'list_payments',
                'description' => 'Client payments: what is owed (pending), late (overdue) or received (paid). Includes exact totals per client (by_client) for questions like who owes the most.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['pending', 'overdue', 'paid', 'all'], 'description' => 'Default: pending and overdue.'],
                    'client_id' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer'],
                ]],
            ],
            [
                'name' => 'add_payment',
                'description' => 'Add a client payment: money received now (paid true), or an amount the client will owe on a due date (paid false).',
                'inputSchema' => ['type' => 'object', 'required' => ['client_id', 'amount'], 'properties' => [
                    'client_id' => ['type' => 'integer'],
                    'amount' => ['type' => 'number'],
                    'paid' => ['type' => 'boolean', 'description' => 'Default true.'],
                    'date' => $date + ['description' => 'Paid date when paid, due date when not. Default today.'],
                    'calendar' => $calendar,
                    'method' => ['type' => 'string', 'enum' => Payment::METHODS],
                    'note' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'mark_payment_paid',
                'description' => 'Mark an owed payment (from list_payments or get_client) as received.',
                'inputSchema' => ['type' => 'object', 'required' => ['payment_id'], 'properties' => [
                    'payment_id' => ['type' => 'integer'],
                    'method' => ['type' => 'string', 'enum' => Payment::METHODS],
                    'date' => $date,
                    'calendar' => $calendar,
                ]],
            ],
            [
                'name' => 'list_transactions',
                'description' => 'Money in and out, like the Finance page: client payments, other income, expenses and salaries.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'month' => ['type' => 'string', 'description' => 'Nepali month YYYY-MM. Default this month.'],
                    'kind' => ['type' => 'string', 'enum' => ['income', 'expense', 'all']],
                ]],
            ],
            [
                'name' => 'add_transaction',
                'description' => 'Record other income or an expense (rent, ads, equipment...). Client payments use add_payment; salaries are paid from the Employees page.',
                'inputSchema' => ['type' => 'object', 'required' => ['kind', 'title', 'amount'], 'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['income', 'expense']],
                    'category' => ['type' => 'string', 'enum' => array_values(array_unique([...array_keys(Transaction::INCOME_CATEGORIES), ...array_keys(Transaction::EXPENSE_CATEGORIES)]))],
                    'title' => ['type' => 'string'],
                    'amount' => ['type' => 'number'],
                    'date' => $date,
                    'calendar' => $calendar,
                    'method' => ['type' => 'string', 'enum' => Payment::METHODS],
                    'note' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'list_invoices',
                'description' => 'Invoices or quotations with their totals and status (draft, sent, paid, overdue, accepted...).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['invoice', 'quotation']],
                    'status' => ['type' => 'string'],
                    'client_id' => ['type' => 'integer'],
                ]],
            ],
            [
                'name' => 'list_leads',
                'description' => 'Sales leads (possible clients) in the pipeline, with stage, value and next follow-up.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'stage' => ['type' => 'string', 'enum' => [...array_keys(Lead::STAGES), 'open']],
                    'query' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'create_lead',
                'description' => 'Add a sales lead.',
                'inputSchema' => ['type' => 'object', 'required' => ['name', 'source'], 'properties' => [
                    'name' => ['type' => 'string'],
                    'company' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'source' => ['type' => 'string', 'enum' => array_keys(Lead::SOURCES)],
                    'service_type' => ['type' => 'string', 'enum' => array_keys(Client::SERVICE_TYPES)],
                    'value' => ['type' => 'number', 'description' => 'Expected deal value'],
                    'next_follow_up' => $date,
                    'calendar' => $calendar,
                    'notes' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'update_lead',
                'description' => 'Move a lead to another stage, set the next follow-up or change its value or notes.',
                'inputSchema' => ['type' => 'object', 'required' => ['lead_id'], 'properties' => [
                    'lead_id' => ['type' => 'integer'],
                    'stage' => ['type' => 'string', 'enum' => array_keys(Lead::STAGES)],
                    'lost_reason' => ['type' => 'string'],
                    'next_follow_up' => $date,
                    'calendar' => $calendar,
                    'value' => ['type' => 'number'],
                    'notes' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'team_today',
                'description' => 'The team today: who is present, on leave or absent, with check-in and check-out times.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'create_employee',
                'description' => 'Add a team member. Check with find_people first that they are not already there.',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string'],
                    'designation' => ['type' => 'string', 'description' => 'Job title, e.g. Graphic Designer'],
                    'department' => ['type' => 'string', 'description' => 'Department: Content Creator or Video Editor'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'salary' => ['type' => 'number', 'description' => 'Monthly salary in NPR'],
                    'joined_on' => $date,
                    'calendar' => $calendar,
                ]],
            ],
            [
                'name' => 'update_employee',
                'description' => 'Change a team member: job, department, contact, salary, joining date, or status (active, on_leave, inactive). Only send what changes.',
                'inputSchema' => ['type' => 'object', 'required' => ['employee_id'], 'properties' => [
                    'employee_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'designation' => ['type' => 'string', 'description' => 'Job title, e.g. Graphic Designer'],
                    'department' => ['type' => 'string', 'description' => 'Department: Content Creator or Video Editor'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'salary' => ['type' => 'number', 'description' => 'Monthly salary in NPR'],
                    'joined_on' => $date,
                    'calendar' => $calendar,
                    'status' => ['type' => 'string', 'enum' => array_keys(Employee::STATUSES)],
                ]],
            ],
            [
                'name' => 'get_employee',
                'description' => 'One team member: job, contact, salary and how much is left to pay this month, attendance this month, open tasks.',
                'inputSchema' => ['type' => 'object', 'required' => ['employee_id'], 'properties' => ['employee_id' => ['type' => 'integer']]],
            ],
            [
                'name' => 'pay_salary',
                'description' => 'Pay salary to a team member, like the Pay button on the Employees page. Amount defaults to what is left for that month; more than what is left is refused.',
                'inputSchema' => ['type' => 'object', 'required' => ['employee_id'], 'properties' => [
                    'employee_id' => ['type' => 'integer'],
                    'amount' => ['type' => 'number'],
                    'month' => ['type' => 'string', 'description' => 'Nepali salary month YYYY-MM (this month, last month or the next two). Default this month.'],
                    'paid_on' => $date,
                    'calendar' => $calendar,
                    'title' => ['type' => 'string', 'description' => 'e.g. Advance, Bonus'],
                    'note' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'mark_attendance',
                'description' => 'Mark or correct one day of attendance for a team member. The fingerprint device never overwrites a day set here.',
                'inputSchema' => ['type' => 'object', 'required' => ['employee_id', 'status'], 'properties' => [
                    'employee_id' => ['type' => 'integer'],
                    'date' => $date + ['description' => 'Default today.'],
                    'calendar' => $calendar,
                    'status' => ['type' => 'string', 'enum' => array_keys(Attendance::STATUSES)],
                    'check_in' => ['type' => 'string', 'description' => 'HH:MM, 24-hour'],
                    'check_out' => ['type' => 'string', 'description' => 'HH:MM, 24-hour'],
                    'note' => ['type' => 'string', 'description' => 'Needed for leave: the reason.'],
                ]],
            ],
            [
                'name' => 'list_packages',
                'description' => 'The service packages the business sells, with prices.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'create_package',
                'description' => 'Create a service package (name, price, billing, what is included).',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'description' => 'One short line'],
                    'price' => ['type' => 'number', 'description' => 'NPR'],
                    'billing_cycle' => ['type' => 'string', 'enum' => Client::BILLING_CYCLES],
                    'features' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What is included, one item each (max 12). Replaces the list.'],
                    'active' => ['type' => 'boolean', 'description' => 'Shown when choosing a package for a client'],
                ]],
            ],
            [
                'name' => 'update_package',
                'description' => 'Change a package: name, price, billing, description, features, or turn it on or off. Only send what changes. Existing clients keep their own price.',
                'inputSchema' => ['type' => 'object', 'required' => ['package_id'], 'properties' => [
                    'package_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'description' => 'One short line'],
                    'price' => ['type' => 'number', 'description' => 'NPR'],
                    'billing_cycle' => ['type' => 'string', 'enum' => Client::BILLING_CYCLES],
                    'features' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What is included, one item each (max 12). Replaces the list.'],
                    'active' => ['type' => 'boolean', 'description' => 'Shown when choosing a package for a client'],
                ]],
            ],
            [
                'name' => 'create_invoice',
                'description' => 'Create an invoice or quotation (as a draft) with its items. Use a client_id, or bill_to for someone who is not a client yet.',
                'inputSchema' => ['type' => 'object', 'required' => ['items'], 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['invoice', 'quotation'], 'description' => 'Default invoice.'],
                    'client_id' => ['type' => 'integer'],
                    'bill_to' => ['type' => 'string', 'description' => 'Business name when there is no client_id'],
                    'items' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['description', 'price'], 'properties' => [
                        'description' => ['type' => 'string'],
                        'details' => ['type' => 'string'],
                        'quantity' => ['type' => 'number', 'description' => 'Default 1'],
                        'price' => ['type' => 'number'],
                    ]]],
                    'due_date' => $date + ['description' => 'Invoice: due by. Quotation: valid until. Default 15 or 30 days.'],
                    'calendar' => $calendar,
                    'discount' => ['type' => 'number', 'description' => 'Amount in NPR'],
                    'tax_rate' => ['type' => 'number', 'description' => 'Percent, e.g. 13'],
                    'notes' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'set_invoice_status',
                'description' => 'Change an invoice or quotation status. Marking an invoice paid also records the payment for the client and in Finance.',
                'inputSchema' => ['type' => 'object', 'required' => ['invoice_id', 'status'], 'properties' => [
                    'invoice_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'paid', 'accepted', 'declined']],
                    'method' => ['type' => 'string', 'enum' => Payment::METHODS, 'description' => 'For paid. Default Bank.'],
                    'paid_on' => $date,
                    'calendar' => $calendar,
                ]],
            ],
            [
                'name' => 'convert_quotation',
                'description' => 'The client accepted a quotation: make an invoice with the same items.',
                'inputSchema' => ['type' => 'object', 'required' => ['invoice_id'], 'properties' => ['invoice_id' => ['type' => 'integer']]],
            ],
            [
                'name' => 'delete',
                'description' => 'Delete something for good. Always confirm with the user first, naming exactly what will go. Deleting a client also deletes their payments, tasks and documents; deleting a box deletes its tasks; deleting an employee deletes their salary and attendance history; deleting a package leaves its clients with no package (they keep their price). A copy is kept on the server for recovery.',
                'inputSchema' => ['type' => 'object', 'required' => ['what', 'id'], 'properties' => [
                    'what' => ['type' => 'string', 'enum' => ['task', 'box', 'client', 'payment', 'transaction', 'lead', 'employee', 'invoice', 'attendance', 'package']],
                    'id' => ['type' => 'string', 'description' => 'The id. For transaction: the key from list_transactions (like t12, s3 or p8). For attendance: employee_id:YYYY-MM-DD.'],
                ]],
            ],
            [
                'name' => 'check_for_updates',
                'description' => 'Which version of the app is live, what is new on GitHub since then, and how the last update went.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'put_update_live',
                'description' => 'Put the latest version from GitHub live, like the Update now button in Settings > Updates. GitHub runs the tests first; if any fail, nothing changes. Takes about two minutes; use check_for_updates afterwards to see the result. Only do this when the user asks.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'even_if_up_to_date' => ['type' => 'boolean', 'description' => 'Run it again although nothing is new.'],
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
            'calendar' => $this->calendar($input),
            'get_client' => $this->getClient($input),
            'create_client' => $this->createClient($input),
            'update_client' => $this->updateClient($input),
            'list_payments' => $this->listPayments($input),
            'add_payment' => $this->addPayment($input),
            'mark_payment_paid' => $this->markPaymentPaid($input),
            'list_transactions' => $this->listTransactions($input),
            'add_transaction' => $this->addTransaction($input),
            'list_invoices' => $this->listInvoices($input),
            'list_leads' => $this->listLeads($input),
            'create_lead' => $this->createLead($input),
            'update_lead' => $this->updateLead($input),
            'team_today' => $this->teamToday(),
            'get_employee' => $this->getEmployee($input),
            'create_employee' => $this->saveEmployee($input),
            'update_employee' => $this->saveEmployee($input, true),
            'pay_salary' => $this->paySalary($input),
            'mark_attendance' => $this->markAttendance($input),
            'list_packages' => $this->listPackages(),
            'create_package' => $this->savePackage($input),
            'update_package' => $this->savePackage($input, true),
            'create_invoice' => $this->createInvoice($input),
            'set_invoice_status' => $this->setInvoiceStatus($input),
            'convert_quotation' => $this->convertQuotation($input),
            'delete' => $this->delete($input),
            'check_for_updates' => $this->checkForUpdates(),
            'put_update_live' => $this->putUpdateLive($input),
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
        // ponytail: models often forget calendar=bs; a year of 2050+ can only be Nepali here (Nepali years run ~57 ahead).
        if (($calendar ?? 'ad') === 'bs' || (int) substr($value, 0, 4) >= 2050) {
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
            ->orderByRaw('due_date is null')->orderBy('due_date');
        $total = (clone $tasks)->count();
        $tasks = $tasks->limit($d['limit'] ?? 30)->get();

        return ['count' => $total, 'tasks' => $tasks->map(fn ($t) => $this->taskRow($t))->all(), 'note' => $total > $tasks->count() ? "Showing {$tasks->count()} of {$total} tasks; count covers all of them." : null];
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

    private function calendar(array $input): array
    {
        $d = $this->validate($input, [
            'month' => ['nullable', 'regex:/^\d{4}-\d{1,2}$/'],
            'from' => ['nullable', 'string'], 'to' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'],
            'open_only' => ['nullable', 'boolean'],
        ]);

        if (! empty($d['month'])) {
            [$y, $m] = array_map('intval', explode('-', $d['month']));
            if ($m < 1 || $m > 12 || ! isset(NepaliDate::calendar()[(string) $y])) {
                throw ValidationException::withMessages(['month' => "{$d['month']} is not a Nepali month this app knows."]);
            }
            [$from, $to] = [NepaliDate::toAd($y, $m, 1), NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))];
        } else {
            $from = $this->date($d['from'] ?? null, $d['calendar'] ?? null) ?? today();
            $to = $this->date($d['to'] ?? null, $d['calendar'] ?? null) ?? $from->copy()->addDays(6);
        }
        if ($to->lt($from) || $from->diffInDays($to) > 62) {
            throw ValidationException::withMessages(['range' => 'Ask for at most about two months at a time, with "to" after "from".']);
        }

        $tasks = Task::with(['client', 'group', 'assignees'])
            ->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)
            ->when($d['open_only'] ?? false, fn ($q) => $q->whereNull('completed_at'))
            ->when($d['assignee_id'] ?? null, fn ($q, $id) => $q->whereHas('assignees', fn ($w) => $w->whereKey($id)))
            ->when($d['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->orderBy('due_date')->orderBy('id')->limit(150)->get();

        return [
            'from' => $this->show($from),
            'to' => $this->show($to),
            'total' => $tasks->count(),
            'done' => $tasks->whereNotNull('completed_at')->count(),
            'days' => $tasks->groupBy(fn ($t) => $t->due_date->toDateString())
                ->map(fn ($day, $date) => [
                    'date' => $this->show(Carbon::parse($date)).', '.Carbon::parse($date)->format('l'),
                    'tasks' => $day->map(fn ($t) => $this->taskRow($t))->values()->all(),
                ])->values()->all(),
        ];
    }

    private function money(float|string|null $v): string
    {
        return Client::money(round((float) $v));
    }

    private function paymentRow(Payment $p): array
    {
        return [
            'id' => $p->id, 'client' => $p->client?->displayName(), 'client_id' => $p->client_id,
            'amount' => $this->money($p->amount), 'status' => $p->status(),
            'due' => $this->show($p->due_date), 'paid' => $this->show($p->paid_at), 'method' => $p->method, 'note' => $p->note,
        ];
    }

    private function getClient(array $input): array
    {
        $d = $this->validate($input, ['client_id' => ['required', 'integer', 'exists:clients,id']]);
        $c = Client::with('package')->findOrFail($d['client_id']);
        $payments = $c->payments()->with('client')->latest('id')->get();
        $open = $c->tasks()->with(['client', 'group', 'assignees'])->whereNull('completed_at')->orderByRaw('due_date is null')->orderBy('due_date')->limit(15)->get();

        return [
            'id' => $c->id, 'business' => $c->company, 'contact' => $c->name, 'phone' => $c->phone, 'email' => $c->email,
            'status' => $c->status, 'service' => Client::SERVICE_TYPES[$c->service_type]['label'] ?? null,
            'package' => $c->package?->name, 'price' => $c->formattedPrice(), 'billing' => $c->billingText(),
            'contract' => ['start' => $this->show($c->contract_start), 'end' => $this->show($c->contract_end)],
            'notes' => $c->notes,
            'owed' => $payments->whereNull('paid_at')->map(fn ($p) => $this->paymentRow($p))->values()->all(),
            'owed_total' => $this->money($payments->whereNull('paid_at')->sum('amount')),
            'received_total' => $this->money($payments->whereNotNull('paid_at')->sum('amount')),
            'recent_paid' => $payments->whereNotNull('paid_at')->take(5)->map(fn ($p) => $this->paymentRow($p))->values()->all(),
            'open_tasks' => $open->map(fn ($t) => $this->taskRow($t))->all(),
        ];
    }

    private function createClient(array $input): array
    {
        $d = $this->validate($input, [
            'name' => ['required', 'string', 'max:120'], 'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:120'],
            'service_type' => ['nullable', Rule::in(array_keys(Client::SERVICE_TYPES))], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $c = Client::create($d + ['status' => 'active', 'service_type' => $d['service_type'] ?? 'social_media']);
        $this->actions[] = ['icon' => 'user-plus', 'text' => __('Added client “:name”', ['name' => $c->displayName()]), 'url' => route('clients.show', $c)];

        return ['created_client' => ['id' => $c->id, 'name' => $c->displayName()]];
    }

    private function updateClient(array $input): array
    {
        $d = $this->validate($input, [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'name' => ['nullable', 'string', 'max:120'], 'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:120'],
            'status' => ['nullable', Rule::in(Client::STATUSES)], 'notes' => ['nullable', 'string', 'max:5000'],
            'package_id' => ['nullable', 'integer', function ($attr, $value, $fail) {
                if ((int) $value !== 0 && ! Package::whereKey($value)->exists()) {
                    $fail('No package with that id. Use list_packages.');
                }
            }],
        ]);
        $c = Client::findOrFail($d['client_id']);
        $changes = array_filter(collect($d)->except(['client_id', 'package_id'])->all(), fn ($v) => $v !== null);
        if (array_key_exists('package_id', $d) && $d['package_id'] !== null) {
            // Same as choosing a package on the client form: its price and billing come along.
            $p = (int) $d['package_id'] ? Package::find($d['package_id']) : null;
            $changes['package_id'] = $p?->id;
            if ($p && $p->price !== null) {
                $changes['price'] = $p->price;
                $changes['billing_months'] = Client::monthsForCycle($p->billing_cycle);
            }
        }
        $c->update($changes);
        $this->actions[] = ['icon' => 'pencil-square', 'text' => __('Updated client “:name”', ['name' => $c->displayName()]), 'url' => route('clients.show', $c)];

        return ['updated_client' => ['id' => $c->id, 'name' => $c->displayName(), 'status' => $c->status]];
    }

    private function listPayments(array $input): array
    {
        $d = $this->validate($input, [
            'status' => ['nullable', Rule::in(['pending', 'overdue', 'paid', 'all'])],
            'client_id' => ['nullable', 'integer'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $d['status'] ?? null;
        $query = fn () => Payment::query()
            ->when($d['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($status === null || $status === 'pending', fn ($q) => $q->whereNull('paid_at'))
            ->when($status === 'overdue', fn ($q) => $q->whereNull('paid_at')->whereDate('due_date', '<', today()))
            ->when($status === 'paid', fn ($q) => $q->whereNotNull('paid_at'));
        $payments = $query()->with('client')
            ->when($status === 'paid', fn ($q) => $q->latest('paid_at'), fn ($q) => $q->orderByRaw('due_date is null')->orderBy('due_date'))
            ->limit($d['limit'] ?? 30)->get();

        // Exact totals over every matching payment, so nobody has to add up a cut-short list.
        $all = $query()->count();
        $byClient = $query()->selectRaw('client_id, count(*) as n, sum(amount) as total')->groupBy('client_id')->orderByDesc('total')->limit(25)->get();
        $names = Client::whereIn('id', $byClient->pluck('client_id'))->get()->keyBy('id');

        return [
            'count' => $all,
            'total' => $this->money($query()->sum('amount')),
            'by_client' => $byClient->map(fn ($r) => ['client_id' => $r->client_id, 'client' => $names[$r->client_id]?->displayName(), 'payments' => (int) $r->n, 'total' => $this->money($r->total)])->all(),
            'payments' => $payments->map(fn ($p) => $this->paymentRow($p))->all(),
            'note' => $all > $payments->count() ? "Showing {$payments->count()} of {$all} payments; count, total and by_client cover all of them." : null,
        ];
    }

    private function addPayment(array $input): array
    {
        $d = $this->validate($input, [
            'client_id' => ['required', 'integer', 'exists:clients,id'], 'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'paid' => ['nullable', 'boolean'], 'date' => ['nullable', 'string'],
            'method' => ['nullable', Rule::in(Payment::METHODS)], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $date = $this->date($d['date'] ?? null, $d['calendar'] ?? null) ?? today();
        $paid = $d['paid'] ?? true;
        $c = Client::findOrFail($d['client_id']);
        $p = $c->payments()->create([
            'amount' => $d['amount'], 'method' => $d['method'] ?? null, 'note' => $d['note'] ?? null,
            'due_date' => $paid ? null : $date, 'paid_at' => $paid ? $date->copy()->setTimeFrom(now()) : null,
        ]);
        $this->actions[] = ['icon' => 'banknotes', 'text' => $paid ? __('Recorded :amount from :client', ['amount' => $this->money($p->amount), 'client' => $c->displayName()]) : __('Added :amount due from :client', ['amount' => $this->money($p->amount), 'client' => $c->displayName()]), 'url' => route('clients.show', ['client' => $c, 'tab' => 'payments'])];

        return ['payment' => $this->paymentRow($p->load('client'))];
    }

    private function markPaymentPaid(array $input): array
    {
        $d = $this->validate($input, ['payment_id' => ['required', 'integer', 'exists:payments,id'], 'method' => ['nullable', Rule::in(Payment::METHODS)], 'date' => ['nullable', 'string']]);
        $p = Payment::with('client')->findOrFail($d['payment_id']);
        if ($p->paid_at) {
            throw ValidationException::withMessages(['payment_id' => 'That payment is already marked as paid.']);
        }
        $p->update(['paid_at' => ($this->date($d['date'] ?? null, $d['calendar'] ?? null) ?? today())->setTimeFrom(now()), 'method' => $d['method'] ?? $p->method]);
        $this->actions[] = ['icon' => 'check-circle', 'text' => __('Marked :amount from :client as paid', ['amount' => $this->money($p->amount), 'client' => $p->client?->displayName()]), 'url' => route('clients.show', ['client' => $p->client_id, 'tab' => 'payments'])];

        return ['payment' => $this->paymentRow($p)];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function monthRange(?string $month): array
    {
        if (! $month) {
            return NepaliDate::monthRange(today());
        }
        [$y, $m] = array_map('intval', explode('-', $month) + [1 => 0]);
        if ($m < 1 || $m > 12 || ! isset(NepaliDate::calendar()[(string) $y])) {
            throw ValidationException::withMessages(['month' => "{$month} is not a Nepali month this app knows."]);
        }

        return [NepaliDate::toAd($y, $m, 1)->startOfDay(), NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m))->endOfDay()];
    }

    private function listTransactions(array $input): array
    {
        $d = $this->validate($input, ['month' => ['nullable', 'regex:/^\d{4}-\d{1,2}$/'], 'kind' => ['nullable', Rule::in(['income', 'expense', 'all'])]]);
        [$from, $to] = $this->monthRange($d['month'] ?? null);
        $kind = $d['kind'] ?? 'all';

        $rows = collect()
            ->concat(Payment::with('client')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])->get()
                ->map(fn ($p) => ['key' => 'p'.$p->id, 'kind' => 'income', 'date' => $p->paid_at, 'title' => $p->client?->displayName(), 'category' => 'Client payment', 'amount' => (float) $p->amount, 'method' => $p->method]))
            ->concat(SalaryPayment::with('employee')->whereBetween('paid_on', [$from, $to])->get()
                ->map(fn ($s) => ['key' => 's'.$s->id, 'kind' => 'expense', 'date' => $s->paid_on, 'title' => $s->employee?->name, 'category' => 'Salary', 'amount' => (float) $s->amount, 'method' => null]))
            ->concat(Transaction::whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
                ->map(fn ($t) => ['key' => 't'.$t->id, 'kind' => $t->kind, 'date' => $t->date, 'title' => $t->title, 'category' => $t->categoryLabel(), 'amount' => (float) $t->amount, 'method' => $t->method]))
            ->filter(fn ($r) => $kind === 'all' || $r['kind'] === $kind)
            ->sortByDesc(fn ($r) => $r['date']->timestamp)->values();

        return [
            'month' => NepaliDate::monthYear($from),
            'money_in' => $this->money($rows->where('kind', 'income')->sum('amount')),
            'money_out' => $this->money($rows->where('kind', 'expense')->sum('amount')),
            'entries' => $rows->take(60)->map(fn ($r) => ['key' => $r['key'], 'kind' => $r['kind'], 'date' => $this->show($r['date']), 'title' => $r['title'], 'category' => $r['category'], 'amount' => $this->money($r['amount']), 'method' => $r['method']])->all(),
        ];
    }

    private function addTransaction(array $input): array
    {
        $d = $this->validate($input, [
            'kind' => ['required', Rule::in(['income', 'expense'])], 'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'], 'date' => ['nullable', 'string'],
            'category' => ['nullable', 'string'], 'method' => ['nullable', Rule::in(Payment::METHODS)], 'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $categories = Transaction::categories($d['kind']);
        $category = array_key_exists($d['category'] ?? '', $categories) ? $d['category'] : 'other';
        $t = Transaction::create([
            'kind' => $d['kind'], 'category' => $category, 'title' => $d['title'], 'amount' => $d['amount'],
            'date' => $this->date($d['date'] ?? null, $d['calendar'] ?? null) ?? today(), 'method' => $d['method'] ?? null, 'note' => $d['note'] ?? null,
        ]);
        $this->actions[] = ['icon' => $t->kind === 'income' ? 'arrow-down-left' : 'arrow-up-right', 'text' => __('Recorded :kind “:title” :amount', ['kind' => $t->kind, 'title' => $t->title, 'amount' => $this->money($t->amount)]), 'url' => route('finance.index')];

        return ['transaction' => ['id' => $t->id, 'kind' => $t->kind, 'category' => $t->categoryLabel(), 'title' => $t->title, 'amount' => $this->money($t->amount), 'date' => $this->show($t->date)]];
    }

    private function listInvoices(array $input): array
    {
        $d = $this->validate($input, ['type' => ['nullable', Rule::in(['invoice', 'quotation'])], 'status' => ['nullable', 'string'], 'client_id' => ['nullable', 'integer']]);

        return Invoice::with('items')->where('type', $d['type'] ?? 'invoice')
            ->when($d['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->latest('issue_date')->limit(50)->get()
            ->filter(fn ($i) => empty($d['status']) || $i->displayStatus() === $d['status'])
            ->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'for' => $i->bill_company ?: $i->bill_name, 'total' => $this->money($i->totals()['total']), 'status' => $i->displayStatus(), 'issued' => $this->show($i->issue_date), 'due' => $this->show($i->due_date), 'link' => route('invoices.show', $i)])
            ->values()->all();
    }

    private function leadRow(Lead $l): array
    {
        return ['id' => $l->id, 'name' => $l->name, 'company' => $l->company, 'phone' => $l->phone, 'stage' => Lead::STAGES[$l->stage]['label'] ?? $l->stage, 'source' => Lead::SOURCES[$l->source]['label'] ?? $l->source, 'value' => $l->value ? $this->money($l->value) : null, 'next_follow_up' => $this->show($l->next_follow_up), 'handled_by' => $l->employee?->name, 'notes' => $l->notes];
    }

    private function listLeads(array $input): array
    {
        $d = $this->validate($input, ['stage' => ['nullable', 'string'], 'query' => ['nullable', 'string']]);
        $q = trim((string) ($d['query'] ?? ''));

        return Lead::with('employee')
            ->when(($d['stage'] ?? 'open') === 'open', fn ($w) => $w->whereNotIn('stage', ['won', 'lost']))
            ->when(isset($d['stage']) && $d['stage'] !== 'open', fn ($w) => $w->where('stage', $d['stage']))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('company', 'like', "%{$q}%")))
            ->orderByRaw('next_follow_up is null')->orderBy('next_follow_up')->limit(50)->get()
            ->map(fn ($l) => $this->leadRow($l))->all();
    }

    private function createLead(array $input): array
    {
        $d = $this->validate($input, collect(Lead::rules())->except(['stage', 'employee_id'])->all() + ['next_follow_up' => ['nullable', 'string']]);
        $d['next_follow_up'] = $this->date($d['next_follow_up'] ?? null, $d['calendar'] ?? null);
        unset($d['calendar']);
        $l = Lead::create($d + ['stage' => 'new']);
        $l->activities()->create(['user_id' => auth()->id(), 'type' => 'note', 'body' => __('Lead added by the AI assistant')]);
        $this->actions[] = ['icon' => 'user-plus', 'text' => __('Added lead “:name”', ['name' => $l->company ?: $l->name]), 'url' => route('sales.leads.show', $l)];

        return ['created_lead' => $this->leadRow($l)];
    }

    private function updateLead(array $input): array
    {
        $d = $this->validate($input, [
            'lead_id' => ['required', 'integer', 'exists:leads,id'], 'stage' => ['nullable', Rule::in(array_keys(Lead::STAGES))],
            'lost_reason' => ['nullable', 'string', 'max:255'], 'next_follow_up' => ['nullable', 'string'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $l = Lead::findOrFail($d['lead_id']);
        $changes = array_filter(['value' => $d['value'] ?? null, 'notes' => $d['notes'] ?? null], fn ($v) => $v !== null);
        if (! empty($d['next_follow_up'])) {
            $changes['next_follow_up'] = $this->date($d['next_follow_up'], $d['calendar'] ?? null);
        }
        $l->update($changes);
        if (! empty($d['stage'])) {
            $l->moveTo($d['stage'], $d['lost_reason'] ?? null);
        }
        $this->actions[] = ['icon' => 'arrows-right-left', 'text' => __('Updated lead “:name”', ['name' => $l->company ?: $l->name]), 'url' => route('sales.leads.show', $l)];

        return ['updated_lead' => $this->leadRow($l->fresh('employee'))];
    }

    private function teamToday(): array
    {
        $marks = Attendance::whereDate('date', today())->get()->keyBy('employee_id');

        return [
            'today' => $this->show(today()),
            'holiday' => today()->isHoliday(),
            'team' => Employee::where('status', '!=', 'inactive')->orderBy('name')->get()->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->name,
                'status' => $marks[$e->id]->status ?? 'not marked',
                'check_in' => Attendance::clock($marks[$e->id]->check_in ?? null),
                'check_out' => Attendance::clock($marks[$e->id]->check_out ?? null),
            ])->all(),
        ];
    }

    /** A department by slug or name; new people default to the first one. */
    private function department(?string $wanted): ?string
    {
        $departments = Employee::choosableDepartments();
        foreach ($departments as $slug => $d) {
            if ($wanted && (strcasecmp($slug, $wanted) === 0 || strcasecmp($d['label'], $wanted) === 0)) {
                return $slug;
            }
        }
        if ($wanted) {
            throw ValidationException::withMessages(['department' => "No department called {$wanted}. Choose one of: ".implode(', ', array_column($departments, 'label')).'.']);
        }

        return null;
    }

    private function saveEmployee(array $input, bool $update = false): array
    {
        $d = $this->validate($input, [
            'employee_id' => [$update ? 'required' : 'prohibited', 'integer', 'exists:employees,id'],
            'name' => [$update ? 'nullable' : 'required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'], 'department' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'], 'joined_on' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(array_keys(Employee::STATUSES))],
        ]);
        $fields = array_filter([
            'name' => $d['name'] ?? null, 'designation' => $d['designation'] ?? null, 'phone' => $d['phone'] ?? null,
            'email' => $d['email'] ?? null, 'salary' => $d['salary'] ?? null, 'status' => $d['status'] ?? null,
            'department' => $this->department($d['department'] ?? null),
            'joined_on' => $this->date($d['joined_on'] ?? null, $d['calendar'] ?? null),
        ], fn ($v) => $v !== null);

        if ($update) {
            $e = Employee::findOrFail($d['employee_id']);
            $e->update($fields);
        } else {
            $e = Employee::create($fields + ['status' => 'active', 'department' => Employee::CHOOSABLE_DEPARTMENTS[0]]);
        }
        $this->actions[] = ['icon' => $update ? 'pencil-square' : 'user-plus', 'text' => ($update ? __('Updated :name', ['name' => $e->name]) : __('Added :name to the team', ['name' => $e->name])), 'url' => route('employees.show', $e)];

        return [($update ? 'updated' : 'created').'_employee' => $this->getEmployee(['employee_id' => $e->id])];
    }

    private function getEmployee(array $input): array
    {
        $d = $this->validate($input, ['employee_id' => ['required', 'integer', 'exists:employees,id']]);
        $e = Employee::findOrFail($d['employee_id']);
        [$from, $to] = NepaliDate::monthRange(today());
        $month = Attendance::where('employee_id', $e->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('status')->countBy();
        $open = $e->tasks()->with(['client', 'group', 'assignees'])->whereNull('completed_at')->orderByRaw('due_date is null')->orderBy('due_date')->limit(15)->get();

        return [
            'id' => $e->id, 'name' => $e->name, 'job' => $e->designation, 'department' => $e->departmentMeta()['label'] ?? null,
            'phone' => $e->phone, 'email' => $e->email, 'status' => $e->status, 'joined' => $this->show($e->joined_on),
            'salary' => $e->salary ? $this->money($e->salary) : null,
            'salary_left_this_month' => ($left = $e->salaryLeftFor(NepaliDate::period(today()))) === null ? null : $this->money($left),
            'attendance_this_month' => $month->all(),
            'open_tasks' => $open->map(fn ($t) => $this->taskRow($t))->all(),
        ];
    }

    private function paySalary(array $input): array
    {
        $d = $this->validate($input, [
            'employee_id' => ['required', 'integer', 'exists:employees,id'], 'amount' => ['nullable', 'numeric'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{1,2}$/'], 'paid_on' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $e = Employee::findOrFail($d['employee_id']);
        $period = isset($d['month']) ? vsprintf('%d-%02d', array_map('intval', explode('-', $d['month']))) : NepaliDate::period(today());
        $amount = $d['amount'] ?? $e->salaryLeftFor($period);
        if (! $amount) {
            throw ValidationException::withMessages(['amount' => 'Say how much to pay: this employee has no salary set, or the month is fully paid.']);
        }
        // Same rules as the Pay button: a month in range, and never more than what is left.
        Validator::make(['payPeriod' => $period, 'payAmount' => $amount], SalaryPayment::payRules($e, $period), [], ['payPeriod' => 'month', 'payAmount' => 'amount'])->validate();

        $paidOn = $this->date($d['paid_on'] ?? null, $d['calendar'] ?? null) ?? today();
        $s = $e->salaryPayments()->create(['period' => $period, 'amount' => $amount, 'paid_on' => $paidOn, 'title' => $d['title'] ?? null, 'note' => $d['note'] ?? null]);
        $this->actions[] = ['icon' => 'banknotes', 'text' => __('Paid :amount to :name', ['amount' => $this->money($amount), 'name' => $e->name]), 'url' => route('employees.show', ['employee' => $e, 'tab' => 'salary'])];

        return ['paid' => ['employee' => $e->name, 'amount' => $this->money($s->amount), 'for' => $s->label(), 'paid_on' => $this->show($s->paid_on), 'left_for_month' => $this->money($e->fresh()->salaryLeftFor($period) ?? 0)]];
    }

    private function markAttendance(array $input): array
    {
        $d = $this->validate($input, [
            'employee_id' => ['required', 'integer', 'exists:employees,id'], 'date' => ['nullable', 'string'],
            'status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'check_in' => ['nullable', 'date_format:H:i'], 'check_out' => ['nullable', 'date_format:H:i', ...(! empty($input['check_in']) ? ['after:check_in'] : [])],
            'note' => [($input['status'] ?? null) === 'leave' ? 'required' : 'nullable', 'string', 'max:255'],
        ], ['note.required' => 'Give the reason for the leave.']);
        $date = ($this->date($d['date'] ?? null, $d['calendar'] ?? null) ?? today())->toDateString();
        $working = in_array($d['status'], ['present', 'half_day'], true);
        DB::table('attendance_exclusions')->where('employee_id', $d['employee_id'])->whereDate('date', $date)->delete();
        $a = Attendance::updateOrCreate(['employee_id' => $d['employee_id'], 'date' => $date], [
            'status' => $d['status'], 'check_in' => $working ? ($d['check_in'] ?? null) : null, 'check_out' => $working ? ($d['check_out'] ?? null) : null,
            'note' => $d['note'] ?? null, 'source' => 'edited', // the device never overwrites an edited day
        ]);
        $e = Employee::find($d['employee_id']);
        $this->actions[] = ['icon' => 'finger-print', 'text' => __(':name: :status on :date', ['name' => $e->name, 'status' => Attendance::STATUSES[$a->status]['label'], 'date' => NepaliDate::format(Carbon::parse($date), false)]), 'url' => route('employees.show', $e)];

        return ['attendance' => ['employee' => $e->name, 'date' => $this->show(Carbon::parse($date)), 'status' => $a->status, 'check_in' => Attendance::clock($a->check_in), 'check_out' => Attendance::clock($a->check_out)]];
    }

    private function listPackages(): array
    {
        return Package::withCount('clients')->orderBy('position')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price === null ? null : $this->money($p->price), 'billing' => $p->billing_cycle, 'active' => (bool) $p->is_active, 'description' => $p->description, 'features' => $p->features ?? [], 'clients_using_it' => $p->clients_count])->all();
    }

    private function savePackage(array $input, bool $update = false): array
    {
        $d = $this->validate($input, [
            'package_id' => [$update ? 'required' : 'prohibited', 'integer', 'exists:packages,id'],
            'name' => [$update ? 'nullable' : 'required', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:160'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'], 'billing_cycle' => ['nullable', Rule::in(Client::BILLING_CYCLES)],
            'features' => ['nullable', 'array', 'max:12'], 'features.*' => ['string', 'max:120'], 'active' => ['nullable', 'boolean'],
        ]);
        $fields = array_filter([
            'name' => $d['name'] ?? null, 'description' => $d['description'] ?? null, 'price' => $d['price'] ?? null,
            'billing_cycle' => $d['billing_cycle'] ?? null, 'is_active' => $d['active'] ?? null,
            'features' => isset($d['features']) ? array_values(array_filter(array_map('trim', $d['features']))) : null,
        ], fn ($v) => $v !== null);

        if ($update) {
            $p = Package::findOrFail($d['package_id']);
            $p->update($fields);
        } else {
            $p = Package::create($fields + ['billing_cycle' => 'monthly', 'is_active' => true, 'icon' => 'star', 'color' => array_key_first(PackageTheme::THEMES), 'position' => (int) Package::max('position') + 1]);
        }
        $this->actions[] = ['icon' => 'cube', 'text' => ($update ? __('Updated package “:name”', ['name' => $p->name]) : __('Created package “:name”', ['name' => $p->name])), 'url' => route('packages.index')];

        return [($update ? 'updated' : 'created').'_package' => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price === null ? null : $this->money($p->price), 'billing' => $p->billing_cycle, 'active' => (bool) $p->is_active, 'features' => $p->features ?? []]];
    }

    private function createInvoice(array $input): array
    {
        $d = $this->validate($input, [
            'type' => ['nullable', Rule::in(['invoice', 'quotation'])],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'], 'bill_to' => ['required_without:client_id', 'nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.details' => ['nullable', 'string', 'max:255'], 'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:9999999999'], 'due_date' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], ['bill_to.required_without' => 'Give a client_id, or bill_to with the business name.']);
        $type = $d['type'] ?? 'invoice';
        $quote = $type === 'quotation';
        $c = isset($d['client_id']) ? Client::find($d['client_id']) : null;

        $invoice = DB::transaction(function () use ($d, $type, $quote, $c) {
            $i = new Invoice([
                'type' => $type, 'number' => Invoice::nextNumber($type), 'status' => 'draft', 'client_id' => $c?->id,
                'bill_name' => $c?->name ?? $d['bill_to'], 'bill_company' => $c ? ($c->company ?: $c->name) : $d['bill_to'],
                'bill_phone' => $c?->phone, 'bill_email' => $c?->email,
                'issue_date' => today(), 'due_date' => $this->date($d['due_date'] ?? null, $d['calendar'] ?? null) ?? today()->addDays($quote ? 30 : 15),
                'discount' => (float) ($d['discount'] ?? 0), 'tax_rate' => (float) ($d['tax_rate'] ?? 0), 'notes' => $d['notes'] ?? null,
                'terms' => Setting::get($quote ? 'quotation_terms' : 'invoice_terms', $quote ? 'This quotation is valid until the date above. 50% advance to start the work.' : 'Please pay by the due date. Thank you for working with us.'),
                'payment_info' => Setting::get('invoice_payment_info') ?: null,
                'template' => Setting::get('invoice_template', 'classic'), 'accent' => Setting::get('invoice_accent', 'green'),
            ]);
            $i->save();
            foreach (array_values($d['items']) as $pos => $item) {
                $i->items()->create(['description' => $item['description'], 'details' => $item['details'] ?? null, 'quantity' => $item['quantity'] ?? 1, 'price' => $item['price'], 'position' => $pos]);
            }

            return $i->load('items');
        });
        $this->actions[] = ['icon' => 'receipt-percent', 'text' => __('Created :type :number', ['type' => $type, 'number' => $invoice->number]), 'url' => route('invoices.show', $invoice)];

        return ['created' => ['id' => $invoice->id, 'number' => $invoice->number, 'for' => $invoice->bill_company, 'total' => $this->money($invoice->totals()['total']), 'due' => $this->show($invoice->due_date), 'link' => route('invoices.show', $invoice), 'print' => route('invoices.print', $invoice)]];
    }

    private function setInvoiceStatus(array $input): array
    {
        $d = $this->validate($input, ['invoice_id' => ['required', 'integer', 'exists:invoices,id'], 'status' => ['required', 'string'], 'method' => ['nullable', Rule::in(Payment::METHODS)], 'paid_on' => ['nullable', 'string']]);
        $i = Invoice::with(['items', 'client'])->findOrFail($d['invoice_id']);
        if (! array_key_exists($d['status'], Invoice::STATUSES[$i->type])) {
            throw ValidationException::withMessages(['status' => 'A '.$i->type.' can be: '.implode(', ', array_keys(Invoice::STATUSES[$i->type])).'.']);
        }
        if ($d['status'] === 'paid') {
            if ($i->status === 'paid') {
                throw ValidationException::withMessages(['status' => "{$i->number} is already paid."]);
            }
            // Same as the Mark paid button: the payment is recorded for the client and in Finance.
            DB::transaction(function () use ($i, $d) {
                $payment = $i->client?->payments()->create([
                    'amount' => $i->totals()['total'], 'type' => 'full', 'due_date' => $i->due_date,
                    'paid_at' => ($this->date($d['paid_on'] ?? null, $d['calendar'] ?? null) ?? today())->setTimeFrom(now()),
                    'method' => $d['method'] ?? 'Bank', 'reference' => $i->number, 'note' => __('Invoice :number', ['number' => $i->number]),
                ]);
                $i->update(['status' => 'paid', 'payment_id' => $payment?->id]);
            });
        } else {
            $i->update(['status' => $d['status']]);
        }
        $this->actions[] = ['icon' => 'receipt-percent', 'text' => __(':number marked :status', ['number' => $i->number, 'status' => $d['status']]), 'url' => route('invoices.show', $i)];

        return ['invoice' => ['number' => $i->number, 'status' => $i->fresh()->displayStatus(), 'total' => $this->money($i->totals()['total'])]];
    }

    private function convertQuotation(array $input): array
    {
        $d = $this->validate($input, ['invoice_id' => ['required', 'integer', 'exists:invoices,id']]);
        $q = Invoice::with('items')->findOrFail($d['invoice_id']);
        if (! $q->isQuote() || $q->converted_to_id) {
            throw ValidationException::withMessages(['invoice_id' => $q->isQuote() ? 'This quotation was already turned into an invoice.' : 'That is an invoice, not a quotation.']);
        }
        $new = DB::transaction(function () use ($q) {
            $new = $q->replicate(['number', 'type', 'status', 'payment_id', 'converted_to_id']);
            $new->fill(['type' => 'invoice', 'number' => Invoice::nextNumber('invoice'), 'status' => 'draft', 'issue_date' => today(), 'due_date' => today()->addDays(15), 'terms' => Setting::get('invoice_terms', $q->terms)])->save();
            foreach ($q->items as $item) {
                $new->items()->create($item->only(['description', 'details', 'quantity', 'price', 'position']));
            }
            $q->update(['status' => 'accepted', 'converted_to_id' => $new->id]);

            return $new;
        });
        $this->actions[] = ['icon' => 'document-duplicate', 'text' => __('Invoice :number made from :quote', ['number' => $new->number, 'quote' => $q->number]), 'url' => route('invoices.show', $new)];

        return ['invoice' => ['id' => $new->id, 'number' => $new->number, 'total' => $this->money($new->load('items')->totals()['total']), 'link' => route('invoices.show', $new)]];
    }

    /** Deletes one record, after saving a copy to storage/app/ai-trash for recovery. */
    private function delete(array $input): array
    {
        $d = $this->validate($input, ['what' => ['required', Rule::in(['task', 'box', 'client', 'payment', 'transaction', 'lead', 'employee', 'invoice', 'attendance', 'package'])], 'id' => ['required']]);
        $id = (string) $d['id'];
        $find = fn (string $class, array $with = []) => $class::with($with)->find((int) $id) ?? throw ValidationException::withMessages(['id' => "No {$d['what']} with id {$id}."]);

        [$model, $label] = match ($d['what']) {
            'task' => [$m = $find(Task::class, ['assignees']), "task “{$m->title}”"],
            'box' => [$m = $find(TaskGroup::class, ['tasks']), "box “{$m->name}” and its {$m->tasks->count()} tasks"],
            'client' => [$m = $find(Client::class, ['payments', 'tasks', 'documents']), 'client '.$m->displayName().' with their payments, tasks and documents'],
            'payment' => [$m = $find(Payment::class, ['client']), 'payment of '.$this->money($m->amount).' from '.$m->client?->displayName()],
            'lead' => [$m = $find(Lead::class, ['activities']), 'lead '.($m->company ?: $m->name)],
            'employee' => [$m = $find(Employee::class, ['salaryPayments', 'attendances']), "employee {$m->name} with their salary and attendance history"],
            'invoice' => [$m = $find(Invoice::class, ['items']), $m->number],
            'package' => [$m = $find(Package::class), "package “{$m->name}”"],
            'transaction' => $this->findTransaction($id),
            'attendance' => $this->findAttendance($id),
        };

        $dir = storage_path('app/ai-trash');
        is_dir($dir) || mkdir($dir, 0755, true);
        file_put_contents($dir.'/'.today()->toDateString().'.jsonl', json_encode(['at' => now()->toIso8601String(), 'what' => $d['what'], 'record' => $model->toArray()], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);

        if ($d['what'] === 'attendance') {
            Attendance::forgetDay($model->employee_id, $model->date->toDateString()); // and keep the device from bringing it back
        } elseif ($d['what'] === 'client') {
            foreach ($model->documents as $doc) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($doc->path);
            }
            $model->delete();
        } else {
            $model->delete();
        }
        $this->actions[] = ['icon' => 'trash', 'text' => __('Deleted :what', ['what' => $label]), 'url' => null];

        return ['deleted' => $label, 'recovery' => 'A copy was saved on the server in storage/app/ai-trash.'];
    }

    private function findTransaction(string $key): array
    {
        $id = (int) substr($key, 1);
        $m = match ($key[0] ?? '') {
            'p' => Payment::with('client')->find($id),
            's' => SalaryPayment::with('employee')->find($id),
            't' => Transaction::find($id),
            default => null,
        } ?? throw ValidationException::withMessages(['id' => "No transaction {$key}. Use the key from list_transactions, like t12."]);
        $who = $m instanceof Payment ? $m->client?->displayName() : ($m instanceof SalaryPayment ? $m->employee?->name : $m->title);

        return [$m, $this->money($m->amount)." ({$who})"];
    }

    private function findAttendance(string $key): array
    {
        [$employee, $date] = array_pad(explode(':', $key, 2), 2, null);
        $a = $date ? Attendance::with('employee')->where('employee_id', (int) $employee)->whereDate('date', Carbon::parse($date)->toDateString())->first() : null;
        if (! $a) {
            throw ValidationException::withMessages(['id' => 'No attendance for that day. Use employee_id:YYYY-MM-DD.']);
        }

        return [$a, "{$a->employee?->name}'s attendance on ".NepaliDate::format($a->date)];
    }

    private function updater(): Updater
    {
        if (! Updater::configured()) {
            throw ValidationException::withMessages(['updates' => 'Updates are not set up yet: a GitHub token is needed (Settings > Updates explains how).']);
        }

        return app(Updater::class);
    }

    private function checkForUpdates(): array
    {
        $updater = $this->updater();
        $status = $updater->status();
        $run = $updater->lastRun(fresh: true);
        $live = Updater::deployed();
        $short = fn ($sha) => $sha ? substr($sha, 0, 7) : null;

        return [
            'live' => $live ? ['version' => $short($live['sha']), 'change' => $live['message'] ?? null, 'since' => $live['at'] ?? null] : 'unknown',
            'newest_on_github' => $status['latest'] ? ['version' => $short($status['latest']['sha']), 'change' => $status['latest']['message']] : $status['error'],
            'updates_waiting' => $status['ahead'],
            'whats_new' => array_column($status['commits'], 'message'),
            'last_update' => $run ? ['state' => $run['status'] === 'completed' ? ($run['conclusion'] === 'success' ? 'finished' : 'failed, nothing was changed') : 'running now', 'started' => $run['started'], 'details' => $run['url']] : null,
        ];
    }

    private function putUpdateLive(array $input): array
    {
        $d = $this->validate($input, ['even_if_up_to_date' => ['nullable', 'boolean']]);
        $updater = $this->updater();
        $run = $updater->lastRun(fresh: true);

        if ($run && $run['status'] !== 'completed') {
            return ['started' => false, 'message' => 'An update is already running. Check again in a minute.', 'details' => $run['url']];
        }
        $status = $updater->status();
        if ($status['latest'] && $status['ahead'] === 0 && ! ($d['even_if_up_to_date'] ?? false)) {
            return ['started' => false, 'message' => 'The live site already runs the newest version. Nothing to do.'];
        }
        if ($error = $updater->start()) {
            throw ValidationException::withMessages(['updates' => $error]);
        }
        $this->actions[] = ['icon' => 'cloud-arrow-down', 'text' => __('Started putting the latest update live'), 'url' => route('settings.updates')];

        return ['started' => true, 'updates' => array_column($status['commits'], 'message'), 'message' => 'Started. GitHub runs the tests, builds and uploads; it takes about two minutes. If a test fails, the live site stays as it is.'];
    }

    private function overview(): array
    {
        [$start, $end] = NepaliDate::monthRange(today());
        $money = fn ($v) => Client::money(round((float) $v));
        $tx = Transaction::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
        $received = Payment::whereBetween('paid_at', [$start, $end])->sum('amount');
        $salaries = SalaryPayment::whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $end)->sum('amount');
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
