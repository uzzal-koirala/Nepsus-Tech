<?php

use App\Mail\DailyReminders;
use App\Models\Setting;
use Flux\Flux;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Admins: which addresses get the system's emails, and which emails are sent. */
new #[Title('Email')] class extends Component {
    /** @var list<string> */
    public array $emails = [];

    public string $newEmail = '';

    public bool $remindersOn = true;

    public bool $leaveOn = true;

    public function mount(): void
    {
        $this->emails = Setting::systemEmails();
        $this->remindersOn = Setting::get('reminder_emails_on') === '1';
        $this->leaveOn = Setting::get('leave_emails_on') === '1';
    }

    public function addEmail(): void
    {
        $this->validate(['newEmail' => ['required', 'email:rfc', 'max:120']], attributes: ['newEmail' => __('email')]);
        $email = mb_strtolower(trim($this->newEmail));
        if (! in_array($email, $this->emails, true)) {
            $this->emails[] = $email;
        }
        $this->reset('newEmail');
        $this->save(quiet: true);
    }

    public function removeEmail(string $email): void
    {
        $this->emails = array_values(array_diff($this->emails, [$email]));
        $this->save(quiet: true);
    }

    public function save(bool $quiet = false): void
    {
        if (($this->remindersOn || $this->leaveOn) && ! $this->emails) {
            $this->addError('newEmail', __('Add at least one address, or turn the emails off.'));

            return;
        }
        Setting::put([
            'reminder_emails' => implode(', ', $this->emails),
            'reminder_emails_on' => $this->remindersOn ? '1' : '0',
            'leave_emails_on' => $this->leaveOn ? '1' : '0',
        ]);
        $quiet || Flux::toast(variant: 'success', text: __('Email settings saved.'));
    }

    public function updatedRemindersOn(): void
    {
        $this->save(quiet: true);
    }

    public function updatedLeaveOn(): void
    {
        $this->save(quiet: true);
    }

    /** Sends today's morning email now, so you can see where it lands. */
    public function sendTest(): void
    {
        if (! $this->emails) {
            $this->addError('newEmail', __('Add an email address first.'));

            return;
        }
        try {
            Mail::to($this->emails)->send(new DailyReminders(DailyReminders::gather()));
            Flux::toast(variant: 'success', text: __('Test email sent to :to.', ['to' => implode(', ', $this->emails)]));
        } catch (\Throwable $e) {
            report($e);
            Flux::toast(variant: 'danger', text: __('Could not send: :error', ['error' => str($e->getMessage())->limit(120)]));
        }
    }
}; ?>

@php
    $tile = 'flex size-10 shrink-0 items-center justify-center rounded-xl';
    $mailer = config('mail.default');
    $sending = ! in_array($mailer, ['log', 'array'], true);
    $from = config('mail.from.address');
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Email')" :subheading="__('Where the system sends its emails, and which emails it sends.')">
        <div class="flex flex-col divide-y divide-zinc-100 dark:divide-white/5">
            {{-- Is mail really going out? --}}
            <div class="pb-6">
                @if ($sending)
                    <div class="flex items-start gap-3 rounded-xl bg-emerald-50 px-4 py-3 ring-1 ring-emerald-200/70 dark:bg-emerald-500/10 dark:ring-emerald-500/20" data-test="mail-on">
                        <flux:icon.check-circle variant="mini" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-300" />
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold text-emerald-900 dark:text-emerald-100">{{ __('Emails are being sent') }}</p>
                            <p class="truncate text-emerald-800/80 dark:text-emerald-200/80">{{ __('From :from', ['from' => $from]) }}</p>
                        </div>
                    </div>
                @else
                    <div class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:ring-amber-500/20" data-test="mail-off">
                        <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-300" />
                        <div class="text-sm">
                            <p class="font-semibold text-amber-900 dark:text-amber-100">{{ __('Emails are not leaving the server yet') }}</p>
                            <p class="text-amber-800/80 dark:text-amber-200/80">{{ __('They are only written to the log. Set the MAIL_ settings in the server .env (for example your Hostinger email) to send them for real.') }}</p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Who gets them --}}
            <div class="flex flex-col gap-4 py-7" data-test="system-emails">
                <div class="flex gap-4">
                    <span class="{{ $tile }} bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.inbox variant="mini" class="size-5" /></span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Send system emails to') }}</p>
                        <p class="mt-0.5 max-w-md text-sm text-zinc-500">{{ __('Every email below goes to all of these addresses.') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:ms-14">
                    @if ($emails)
                        <ul class="flex flex-wrap gap-2">
                            @foreach ($emails as $email)
                                <li wire:key="em-{{ $email }}" class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 py-1 ps-3 pe-1 text-sm font-medium text-zinc-800 dark:bg-white/10 dark:text-zinc-100">
                                    <flux:icon.envelope variant="micro" class="size-3.5 text-zinc-400" />{{ $email }}
                                    <button type="button" wire:click="removeEmail(@js($email))" wire:confirm="{{ __('Stop sending emails to :email?', ['email' => $email]) }}" class="flex size-6 items-center justify-center rounded-full text-zinc-400 transition hover:bg-rose-100 hover:text-rose-600 dark:hover:bg-rose-500/15" aria-label="{{ __('Remove :email', ['email' => $email]) }}" data-test="remove-email"><flux:icon.x-mark variant="micro" class="size-3.5" /></button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-zinc-500">{{ __('No addresses yet, so nothing is sent.') }}</p>
                    @endif
                    <form wire:submit="addEmail" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                        <flux:input wire:model="newEmail" type="email" icon="at-symbol" class="flex-1" :placeholder="__('you@gmail.com')" data-test="new-email" />
                        <div class="flex gap-2">
                            <flux:button type="submit" variant="primary" icon="plus" class="flex-1 sm:flex-none" data-test="add-email">{{ __('Add') }}</flux:button>
                            <flux:button type="button" icon="paper-airplane" wire:click="sendTest" class="flex-1 sm:flex-none" data-test="send-test">{{ __('Send test email') }}</flux:button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Which emails --}}
            <div class="flex flex-col gap-5 pt-7" data-test="email-kinds">
                @foreach ([
                    ['remindersOn', 'sun', 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300', __('Morning reminders'), __('Every day at 7:00 AM: shoots, tasks, payments and salaries due today or tomorrow, plus anything late.')],
                    ['leaveOn', 'paper-airplane', 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300', __('Leave requests'), __('As soon as an employee asks for leave, with the dates and the reason.')],
                ] as [$model, $icon, $tone, $label, $hint])
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-4">
                            <span class="{{ $tile }} {{ $tone }}"><flux:icon :icon="$icon" variant="mini" class="size-5" /></span>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ $label }}</p>
                                <p class="mt-0.5 max-w-md text-sm text-zinc-500">{{ $hint }}</p>
                            </div>
                        </div>
                        <flux:switch wire:model.live="{{ $model }}" data-test="toggle-{{ $model }}" />
                    </div>
                @endforeach
            </div>
        </div>
    </x-pages::settings.layout>
</section>
