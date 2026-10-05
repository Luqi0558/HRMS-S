<?php

namespace App\Services;

use App\Exceptions\Domain\InvalidInvitationException;
use App\Models\Employee;
use App\Models\Invitation;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\InvitationRepositoryInterface;
use App\Repositories\Contracts\OnboardingRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;

class OnboardingService
{
    public function __construct(
        private readonly InvitationRepositoryInterface $invitations,
        private readonly EmployeeRepositoryInterface $employees,
        private readonly UserRepositoryInterface $users,
        private readonly OnboardingRepositoryInterface $onboarding,
    ) {
    }

    /**
     * Resolves a raw invite token to its employee, for prefilling the wizard.
     * The frontend currently guesses the invitee by scanning for any employee
     * with status 'invited', which picks the wrong person once two invitations
     * are outstanding — this replaces that.
     *
     * @throws InvalidInvitationException
     */
    public function resolveInvitation(string $rawToken): Invitation
    {
        $invitation = $this->invitations->findByTokenHash(hash('sha256', $rawToken));

        if (! $invitation) {
            throw InvalidInvitationException::notFound();
        }

        // Distinguished from each other so the UI can offer the right remedy —
        // but only after the token itself proved valid, so neither case leaks
        // information to someone without a token.
        if ($invitation->accepted_at !== null) {
            throw InvalidInvitationException::alreadyAccepted();
        }

        if ($invitation->expires_at->isPast()) {
            throw InvalidInvitationException::expired();
        }

        return $invitation;
    }

    /**
     * Completes onboarding: writes the profile, creates or activates the login,
     * and burns the invitation. All in one transaction — a half-applied
     * onboarding would leave an employee with a password but no profile, or an
     * invitation consumed with nothing to show for it.
     *
     * @throws InvalidInvitationException
     */
    public function completeOnboarding(string $rawToken, array $payload): Employee
    {
        return DB::transaction(function () use ($rawToken, $payload) {
            // Re-resolved inside the transaction and locked, so two submissions
            // of the same link cannot both pass the redeemable check.
            $invitation = $this->resolveInvitation($rawToken);
            $invitation = $invitation->newQuery()
                ->lockForUpdate()
                ->find($invitation->id);

            if ($invitation->accepted_at !== null) {
                throw InvalidInvitationException::alreadyAccepted();
            }

            $employee = $invitation->employee;

            $this->onboarding->savePersonal($employee, $payload['personal'] ?? []);

            $technical = $payload['technical'] ?? [];
            $technical['submittedAt'] = now();
            $this->onboarding->saveTechnical($employee, $technical);

            $this->onboarding->syncStacks($employee, $technical['stacks'] ?? []);
            $this->onboarding->syncSkills(
                $employee,
                $technical['skills'] ?? [],
                $technical['skillLevels'] ?? [],
                $technical['currentlyLearning'] ?? [],
            );

            // The employee's own designation is updated from what they
            // submitted only if HR left it blank — otherwise HR's value stands.
            $employeeUpdates = [
                'status' => 'active',
                // A new or corrected submission needs a fresh HR decision.
                'onboarding_review_status' => 'pending',
                'onboarding_reviewed_by' => null,
                'onboarding_reviewed_at' => null,
                'onboarding_correction_note' => null,
            ];
            if (blank($employee->designation) && filled($technical['designation'] ?? null)) {
                $employeeUpdates['designation'] = $technical['designation'];
            }
            $this->employees->update($employee, $employeeUpdates);

            // Inside the transaction so a later failure does not leave document
            // rows pointing at an onboarding that was rolled back. The files
            // themselves are already on disk at this point — orphaned blobs are
            // harmless, orphaned rows are not.
            $this->onboarding->saveDocuments($employee, $payload['documents'] ?? []);

            $this->attachLogin($employee, $payload['password']);

            $this->invitations->markAccepted($invitation);

            return $this->employees->loadFullProfile($employee->refresh());
        });
    }

    /**
     * An employee row may predate its login (HR prepares the record, the
     * invitee sets the password later), so this handles both cases.
     */
    private function attachLogin(Employee $employee, string $plainPassword): void
    {
        $user = $employee->user_id
            ? $this->users->findById($employee->user_id)
            : $this->users->findActiveByEmail($employee->email);

        if (! $user) {
            $user = $this->users->create([
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => 'employee',
                'status' => 'invited',
            ]);
        }

        $this->users->activateWithPassword($user, $plainPassword);

        if ($employee->user_id !== $user->id) {
            $this->employees->update($employee, ['user_id' => $user->id]);
        }
    }
}
