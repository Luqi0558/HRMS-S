<?php

namespace App\Repositories\Contracts;

use App\Models\Employee;
use App\Models\Invitation;

interface InvitationRepositoryInterface
{
    /**
     * Looks up by hash, never by raw token — the raw value is not stored.
     * Returns the row even if expired or already accepted; callers decide,
     * so the reason for refusal can be distinguished.
     */
    public function findByTokenHash(string $tokenHash): ?Invitation;

    /** @return array{0: Invitation, 1: string} The row and the raw token to email. */
    public function issueFor(Employee $employee, ?int $invitedBy, int $validDays): array;

    public function markAccepted(Invitation $invitation): Invitation;

    /** Invalidates any outstanding invitations before a new one is issued. */
    public function revokeOutstandingFor(Employee $employee): void;
}
