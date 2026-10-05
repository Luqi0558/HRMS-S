<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The employee's secure first-login invitation. */
class EmployeeInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $employeeName,
        public readonly string $inviteUrl,
        public readonly string $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are invited to Orbit HRMS');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.employee-invitation');
    }
}
