<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EmployeeService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
    ) {
    }

    /**
     * The employee list this user may see.
     *
     * The route middleware has already confirmed they hold Employees.view;
     * this decides *which rows*, which is the part that differs per role —
     * HR sees everyone, a manager only their own people.
     */
    public function list(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $scope = $this->scopeFor($user, 'Employees', 'view');

        return $this->employees->paginateScoped(
            $this->scopes->visibleEmployeeIds($user, $scope),
            $filters,
            $perPage,
        );
    }

    /**
     * One employee, with the full onboarding profile.
     *
     * A user may always read their own record regardless of scope — otherwise
     * an employee could not open their own profile page.
     */
    public function find(User $user, int $employeeId): Employee
    {
        $employee = $this->employees->findById($employeeId);

        if (! $employee) {
            throw new NotFoundHttpException('Employee not found.');
        }

        if (! $this->canSee($user, $employee)) {
            // 404 rather than 403: telling an unauthorised caller that a record
            // exists but is off-limits confirms the person is on staff.
            throw new NotFoundHttpException('Employee not found.');
        }

        return $this->employees->loadFullProfile($employee);
    }

    /**
     * HR-level edits (designation, department, dates). A user editing their
     * own personal details goes through the profile endpoints instead — this
     * is the employment record, not the person's own data.
     */
    public function update(User $user, int $employeeId, array $attributes): Employee
    {
        $employee = $this->employees->findById($employeeId);

        if (! $employee) {
            throw new NotFoundHttpException('Employee not found.');
        }

        $scope = $this->scopeFor($user, 'Employees', 'create');
        $visible = $this->scopes->visibleEmployeeIds($user, $scope);

        if ($visible !== null && ! in_array($employee->id, $visible, true)) {
            throw new AccessDeniedHttpException('You may not edit this employee.');
        }

        return $this->employees->update($employee, $attributes);
    }

    /**
     * Blocking is an access-control action, not a presentational employee
     * status. Both status columns must agree and every issued Sanctum token is
     * revoked so the person is signed out on their next request.
     */
    public function setBlocked(User $actor, int $employeeId, bool $blocked): Employee
    {
        $employee = $this->employees->findById($employeeId);

        if (! $employee) {
            throw new NotFoundHttpException('Employee not found.');
        }

        $scope = $this->scopeFor($actor, 'Employees', 'create');
        $visible = $this->scopes->visibleEmployeeIds($actor, $scope);
        if ($visible !== null && ! in_array($employee->id, $visible, true)) {
            throw new AccessDeniedHttpException('You may not change this employee access.');
        }

        $employee->loadMissing('user');
        if (! $employee->user || ! in_array($employee->status, ['active', 'blocked'], true)) {
            throw new ConflictHttpException('Only active employee profiles can be blocked or restored.');
        }

        if ($employee->user_id === $actor->id || ! in_array($employee->user->role, ['employee', 'manager'], true)) {
            throw new AccessDeniedHttpException('This profile cannot be blocked from the employee directory.');
        }

        return DB::transaction(function () use ($employee, $blocked) {
            $status = $blocked ? 'blocked' : 'active';

            $employee->user->forceFill(['status' => $status])->save();
            if ($blocked) {
                $employee->user->tokens()->delete();
            }

            $this->employees->update($employee, ['status' => $status]);

            return $employee->refresh()->load('user');
        });
    }

    /**
     * Persists HR's onboarding-document decision separately from the employee
     * record. This must not be a client-only flag: it controls a compliance
     * workflow and must survive refreshes and be attributable to its reviewer.
     */
    public function reviewOnboardingDocuments(User $user, int $employeeId, string $action, ?string $note): Employee
    {
        $employee = $this->employees->findById($employeeId);

        if (! $employee) {
            throw new NotFoundHttpException('Employee not found.');
        }

        $scope = $this->scopeFor($user, 'Employees', 'create');
        $visible = $this->scopes->visibleEmployeeIds($user, $scope);
        if ($visible !== null && ! in_array($employee->id, $visible, true)) {
            throw new AccessDeniedHttpException('You may not review this employee.');
        }

        $attributes = [
            'onboarding_review_status' => $action,
            'onboarding_reviewed_by' => $user->id,
            'onboarding_reviewed_at' => now(),
            'onboarding_correction_note' => $action === 'correction_requested' ? $note : null,
        ];

        if ($action === 'verified') {
            $employee->documents()
                ->where('status', 'submitted')
                ->update([
                    'status' => 'verified',
                    'verified_by' => $user->id,
                    'verified_at' => now(),
                    'rejection_reason' => null,
                ]);
        } else {
            $employee->documents()
                ->whereIn('status', ['submitted', 'verified'])
                ->update([
                    'status' => 'rejected',
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => $note,
                ]);
        }

        $this->employees->update($employee, $attributes);

        return $this->employees->loadFullProfile($employee->refresh());
    }

    private function canSee(User $user, Employee $employee): bool
    {
        // Own record is always readable, and is checked before the permission
        // lookup: an ordinary employee has no Employees row at all, so going
        // through scopeFor() would throw before reaching this.
        if ($employee->user_id === $user->id) {
            return true;
        }

        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Employees');
        if (! $row || ! ($row['view'] ?? false)) {
            return false;
        }

        $visible = $this->scopes->visibleEmployeeIds($user, $row['scope']);

        return $visible === null || in_array($employee->id, $visible, true);
    }

    /**
     * The scope this user holds for an action, or throws if they hold the
     * module at all but not this action.
     */
    /**
     * An employee document this user may download: their own, or one in the
     * scope of their Employees.view grant. 404 either way when not allowed,
     * so the endpoint cannot be used to probe which documents exist.
     *
     * The Employees page "downloaded" documents by building a Blob in the
     * browser that said "Demo document" — HR could never open the real file.
     */
    public function documentForDownload(User $user, int $employeeId, string $key): \App\Models\EmployeeDocument
    {
        $type = \App\Models\EmployeeDocument::TYPE_MAP[$key] ?? null;
        $employee = $this->employees->findById($employeeId);

        if (! $type || ! $employee) {
            throw new NotFoundHttpException('Document not found.');
        }

        $isOwner = (int) $employee->user_id === (int) $user->id;

        if (! $isOwner) {
            $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Employees');
            $visible = ($row['view'] ?? false)
                ? $this->scopes->visibleEmployeeIds($user, $row['scope'])
                : [];

            if ($visible !== null && ! in_array($employee->id, $visible, true)) {
                throw new NotFoundHttpException('Document not found.');
            }
        }

        $document = $employee->documents()->where('type', $type)->first();

        if (! $document || ! filled($document->path)) {
            throw new NotFoundHttpException('Document not found.');
        }

        return $document;
    }

    private function scopeFor(User $user, string $module, string $action): string
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', $module);

        if (! $row || ! ($row[$action] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        return $row['scope'];
    }
}
