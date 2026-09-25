<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class PublicIssueReport extends Mailable
{
    /** @param array<string, mixed> $report */
    public function __construct(public readonly array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Save It issue report', replyTo: [$this->report['email']]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.public-issue-report', text: 'mail.public-issue-report-text');
    }
}
