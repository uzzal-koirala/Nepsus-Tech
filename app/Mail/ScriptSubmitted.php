<?php

namespace App\Mail;

use App\Models\Script;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to the system email addresses as soon as a Content Creator submits a script for approval. */
class ScriptSubmitted extends Mailable
{
    public function __construct(public Script $script, public User $by) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('New script to approve: :title', ['title' => $this->script->title]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.script-submitted');
    }
}
