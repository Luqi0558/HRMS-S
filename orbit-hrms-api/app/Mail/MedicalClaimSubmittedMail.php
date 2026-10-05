<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A private approval email with the claimant's annual medical context. */
class MedicalClaimSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $requesterName,
        public readonly string $claimType,
        public readonly string $incurredOn,
        public readonly string $claimAmount,
        public readonly ?string $description,
        public readonly string $annualAllowance,
        public readonly string $usedThisYear,
        public readonly string $remainingBeforeClaim,
        public readonly string $remainingIfApproved,
        public readonly bool $hasReceipt,
        public readonly string $reviewUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Medical claim: %s (%s)', $this->requesterName, $this->claimType),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.medical-claim-submitted');
    }
}
