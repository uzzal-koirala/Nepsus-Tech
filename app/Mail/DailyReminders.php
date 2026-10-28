<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\Payment;
use App\Models\Script;
use App\Models\Setting;
use App\Models\Task;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The morning email: shoots, tasks, payments and salaries that need attention. */
class DailyReminders extends Mailable
{
    /** @param list<array{title: string, color: string, items: list<array{title: string, meta: string, when: string, url: string}>}> $sections */
    public function __construct(public array $sections) {}

    /** What is due today and tomorrow (plus anything late). Empty sections are left out. */
    public static function gather(): array
    {
        $today = today();
        $tomorrow = today()->addDay();
        $when = fn ($date) => $date->isSameDay($today) ? __('Today') : ($date->isSameDay($tomorrow) ? __('Tomorrow') : ($date->lt($today) ? trans_choice(':count day late|:count days late', (int) $date->diffInDays($today)) : $date->toNepali(false)));

        $sections = [
            [
                'title' => __('Shoots'), 'color' => '#7c3aed',
                'items' => Script::with('client')->scheduled()->whereDate('shoot_date', '>=', $today)->whereDate('shoot_date', '<=', $tomorrow)->orderBy('shoot_date')->get()
                    ->map(fn ($s) => ['title' => $s->title, 'meta' => $s->client?->displayName() ?? __('Other scripts'), 'when' => $when($s->shoot_date), 'url' => route('scripts.edit', $s)])->all(),
            ],
            [
                'title' => __('Tasks'), 'color' => '#2563eb',
                'items' => Task::with('client')->whereNull('completed_at')->whereNotNull('due_date')->whereDate('due_date', '<=', $tomorrow)->orderBy('due_date')->limit(15)->get()
                    ->map(fn ($t) => ['title' => $t->title, 'meta' => $t->client?->displayName() ?? __('Team task'), 'when' => $when($t->due_date), 'url' => $t->client ? route('clients.show', ['client' => $t->client_id, 'tab' => 'tasks']) : route('tasks.index')])->all(),
            ],
            [
                'title' => __('Payments due'), 'color' => '#d97706',
                'items' => Payment::with('client')->whereNull('paid_at')->whereNotNull('due_date')->whereDate('due_date', '<=', $tomorrow)->orderBy('due_date')->limit(15)->get()
                    ->map(fn ($p) => ['title' => $p->client?->displayName() ?? __('Payment'), 'meta' => config('app.currency').' '.number_format((float) $p->amount), 'when' => $when($p->due_date), 'url' => route('clients.show', ['client' => $p->client_id, 'tab' => 'payments'])])->all(),
            ],
            [
                'title' => __('Salaries'), 'color' => '#059669',
                'items' => Employee::where('status', 'active')->whereNotNull('salary_day')->get()
                    ->filter(fn ($e) => ($d = $e->nextSalaryDate()) && $d->lte($tomorrow))
                    ->map(fn ($e) => ['title' => $e->name, 'meta' => config('app.currency').' '.number_format((float) $e->salary), 'when' => $when($e->nextSalaryDate()), 'url' => route('employees.show', $e)])->values()->all(),
            ],
        ];

        return array_values(array_filter($sections, fn ($s) => $s['items']));
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        return Setting::systemEmails();
    }

    public function envelope(): Envelope
    {
        $count = array_sum(array_map(fn ($s) => count($s['items']), $this->sections));

        return new Envelope(subject: $count
            ? trans_choice(':count reminder for :date|:count reminders for :date', $count, ['date' => today()->toNepali()])
            : __('No reminders for :date', ['date' => today()->toNepali()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.daily-reminders');
    }
}
