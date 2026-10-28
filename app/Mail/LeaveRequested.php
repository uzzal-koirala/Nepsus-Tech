<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to the system email addresses when an employee asks for leave. */
class LeaveRequested extends Mailable
{
    public function __construct(public LeaveRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':name asked for leave', ['name' => $this->request->employee->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.leave-requested');
    }
}
