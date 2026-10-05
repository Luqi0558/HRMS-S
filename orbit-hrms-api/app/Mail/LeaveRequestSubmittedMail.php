<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The off-app counterpart to the Leave Requests bell notification. */
class LeaveRequestSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $requesterName,
        public readonly string $leaveType,
        public readonly string $fromDate,
        public readonly string $toDate,
        public readonly string $days,
        public readonly ?string $reason,
        public readonly string $reviewUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Leave request: %s (%s)', $this->requesterName, $this->leaveType),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.leave-request-submitted');
    }
}
