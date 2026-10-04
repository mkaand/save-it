<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class IssueReport extends Mailable
{
    /** @param array<string, string> $report */
    public function __construct(public readonly array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Save It - Issue Report',
            replyTo: [new Address($this->report['email'])],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.issue-report', text: 'mail.issue-report-text');
    }
}
