<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Invitation;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\InvitationRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InvitationService
{
    /** How long an invite link stays usable. */
    private const VALID_DAYS = 7;

    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
        private readonly InvitationRepositoryInterface $invitations,
    ) {
    }

    /**
     * Creates the employment record and its first invitation together.
     *
     * @return array{employee: Employee, invitation: Invitation, token: string}
     */
    public function inviteEmployee(array $attributes, ?int $invitedBy): array
    {
        // Retried because nextEmployeeCode() reads-then-increments: two
        // concurrent invites can compute the same code, and the unique index
        // rejects the loser rather than allowing a duplicate.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $invitedBy) {
                    $employee = $this->employees->create([
                        'employee_code' => $this->employees->nextEmployeeCode(),
                        'name' => $attributes['name'],
                        'email' => $attributes['email'],
                        'designation' => $attributes['designation'] ?? null,
                        'department' => $attributes['department'] ?? null,
                        'joining_date' => $attributes['joiningDate'] ?? null,
                        'employment_type' => $attributes['employmentType'] ?? 'Full-time',
                        'status' => 'invited',
                    ]);

                    [$invitation, $token] = $this->invitations->issueFor(
                        $employee,
                        $invitedBy,
                        self::VALID_DAYS,
                    );

                    return compact('employee', 'invitation', 'token');
                });
            } catch (QueryException $e) {
                // 23000 = integrity constraint violation, i.e. the code
                // collision above. Anything else is a real fault, and after
                // three attempts so is this.
                if ($e->getCode() !== '23000' || $attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Issues a fresh link, invalidating any still outstanding so an old email
     * cannot be used after HR has reissued.
     *
     * @return array{invitation: Invitation, token: string}
     */
    public function resendInvitation(Employee $employee, ?int $invitedBy): array
    {
        return DB::transaction(function () use ($employee, $invitedBy) {
            $this->invitations->revokeOutstandingFor($employee);

            [$invitation, $token] = $this->invitations->issueFor(
                $employee,
                $invitedBy,
                self::VALID_DAYS,
            );

            return compact('invitation', 'token');
        });
    }
}
