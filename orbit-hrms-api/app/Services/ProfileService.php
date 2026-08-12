<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\OnboardingRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Self-service edits to one's own employee record, behind /api/profile.
 *
 * Separate from EmployeeService::update because that one answers "HR is
 * editing somebody" and is gated on the Employees module, which an ordinary
 * employee does not hold. The subject here is never taken from input — it is
 * resolved from the bearer token, so there is no id to tamper with and no way
 * to address another employee's row.
 */
class ProfileService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
        private readonly OnboardingRepositoryInterface $onboarding,
    ) {
    }

    /**
     * The signed-in user's own employee row, with the onboarding relations the
     * Profile screen reads.
     */
    public function findOwn(User $user): Employee
    {
        return $this->employees->loadFullProfile($this->resolve($user));
    }

    /**
     * Resolved from the token rather than from input — this is what makes the
     * endpoint safe without a module permission check.
     */
    private function resolve(User $user): Employee
    {
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            // A user with no employee row is a broken account rather than a
            // permission problem — surfacing it as 404 keeps it distinct from
            // the 403s the module guards produce.
            throw new ModelNotFoundException('No employee record for this account.');
        }

        return $employee;
    }

    /**
     * Changes the signed-in user's own password.
     *
     * The current password is required and verified here rather than trusted
     * from the client: without it, anyone who reached an unlocked screen or a
     * borrowed session could lock the real owner out of their account.
     *
     * Other sessions are revoked on success. A password change is what somebody
     * does when they think their account is compromised, and leaving the other
     * device signed in defeats the point.
     */
    public function changePassword(User $user, string $current, string $next): void
    {
        if (! Hash::check($current, (string) $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'That is not your current password.',
            ]);
        }

        if (Hash::check($next, (string) $user->password)) {
            throw ValidationException::withMessages([
                'newPassword' => 'Choose a password you have not used here before.',
            ]);
        }

        DB::transaction(function () use ($user, $next) {
            // The model casts `password` to 'hashed', so this is not plaintext.
            $user->forceFill(['password' => $next])->save();

            // Every token except the one making this request.
            $user->tokens()
                ->where('id', '!=', $user->currentAccessToken()?->id)
                ->delete();
        });
    }

    /**
     * @param array<string, mixed> $payload already filtered by UpdateProfileRequest
     */
    public function updateOwn(User $user, array $payload): Employee
    {
        $employee = $this->resolve($user);

        return DB::transaction(function () use ($employee, $payload) {
            $employeeUpdates = [];

            if (array_key_exists('name', $payload)) {
                $employeeUpdates['name'] = $payload['name'];
            }

            if (array_key_exists('birthday', $payload)) {
                $employeeUpdates['birthday'] = $payload['birthday'];
            }

            if ($employeeUpdates !== []) {
                $this->employees->update($employee, $employeeUpdates);
            }

            if (array_key_exists('personal', $payload)) {
                // Every column savePersonal writes is self-editable, so the
                // submitted set can go straight through.
                $this->onboarding->savePersonal($employee, $payload['personal']);
            }

            if (array_key_exists('technical', $payload)) {
                $this->saveTechnical($employee, $payload['technical']);
            }

            return $this->employees->loadFullProfile($employee->refresh());
        });
    }

    /**
     * saveTechnical() writes every column with `?? null`, which is correct for
     * the invite wizard — it submits the whole form at once. Here it is a trap:
     * the profile screen sends only the self-editable subset, so passing that
     * straight through would blank the designation, employment type and
     * probation status HR had set.
     *
     * Those three are therefore read back off the existing row and re-sent
     * unchanged. An employee cannot alter them (UpdateProfileRequest drops them
     * from the input entirely) and cannot erase them either.
     *
     * @param array<string, mixed> $technical
     */
    private function saveTechnical(Employee $employee, array $technical): void
    {
        $existing = $employee->technicalProfile;

        $this->onboarding->saveTechnical($employee, array_merge($technical, [
            'designation' => $existing?->designation,
            'employmentType' => $existing?->employment_type,
            'probationStatus' => $existing?->probation_status,
            // Preserved for the same reason: it records when onboarding was
            // submitted, and editing a phone number years later must not
            // restamp it.
            'submittedAt' => $existing?->submitted_at,
        ]));

        if (array_key_exists('stacks', $technical)) {
            $this->onboarding->syncStacks($employee, $technical['stacks'] ?? []);
        }

        if (array_key_exists('skills', $technical)) {
            $this->onboarding->syncSkills(
                $employee,
                $technical['skills'] ?? [],
                $technical['skillLevels'] ?? [],
                $technical['currentlyLearning'] ?? [],
            );
        }
    }
}
