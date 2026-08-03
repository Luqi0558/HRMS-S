<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
    private function scopeFor(User $user, string $module, string $action): string
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', $module);

        if (! $row || ! ($row[$action] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        return $row['scope'];
    }
}
