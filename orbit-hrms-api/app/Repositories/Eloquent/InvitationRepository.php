<?php

namespace App\Repositories\Eloquent;

use App\Models\Employee;
use App\Models\Invitation;
use App\Repositories\Contracts\InvitationRepositoryInterface;

class InvitationRepository implements InvitationRepositoryInterface
{
    public function __construct(private readonly Invitation $model)
    {
    }

    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        return $this->model->newQuery()
            ->with('employee')
            ->where('token_hash', $tokenHash)
            ->first();
    }

    public function issueFor(Employee $employee, ?int $invitedBy, int $validDays): array
    {
        [$raw, $hash] = Invitation::issueToken();

        $invitation = $this->model->newQuery()->create([
            'employee_id' => $employee->id,
            // Pinned at send time: correcting a typo on the employee record
            // later must not silently retarget an already-sent link.
            'email' => $employee->email,
            'token_hash' => $hash,
            'expires_at' => now()->addDays($validDays),
            'invited_by' => $invitedBy,
        ]);

        // The raw token is returned once, for the email. It is not recoverable
        // afterwards — only its hash was persisted.
        return [$invitation, $raw];
    }

    public function markAccepted(Invitation $invitation): Invitation
    {
        $invitation->forceFill(['accepted_at' => now()])->save();

        return $invitation;
    }

    public function revokeOutstandingFor(Employee $employee): void
    {
        // Expiring rather than deleting keeps the audit trail of who was
        // invited and when, which HR needs for onboarding compliance.
        $this->model->newQuery()
            ->where('employee_id', $employee->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }
}
