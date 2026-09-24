<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OperationalReport extends Mailable
{
    /** @param array<string, mixed> $report */
    public function __construct(public readonly array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Save It operational report');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.operational-report', text: 'mail.operational-report-text');
    }
}
