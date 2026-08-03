<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a permission row's `scope` into actual query constraints.
 *
 * This is the server-side counterpart to matchesScope() in src/store/index.js.
 * The frontend version decides what to render; this one decides what the API
 * will return, and it is the only one that matters for security — a hidden
 * button is not a permission.
 *
 * The four scopes:
 *   all   - every record in the company
 *   owned - records the user manages (project.manager_id / module.lead_id)
 *   team  - records of a project or module they belong to
 *   self  - their own records only
 */
class ScopeResolver
{
    /**
     * Employee ids visible to this user under the given scope.
     *
     * Returns null for 'all', meaning "no constraint" — callers must treat
     * null and an empty array differently, since an empty array legitimately
     * means "nobody", not "everybody".
     *
     * @return array<int, int>|null
     */
    public function visibleEmployeeIds(User $user, string $scope): ?array
    {
        $employee = $this->employeeFor($user);

        return match ($scope) {
            'all' => null,
            'owned' => $this->managedEmployeeIds($employee),
            'team' => $this->teammateIds($employee),
            'self' => $employee ? [$employee->id] : [],
            default => [],
        };
    }

    /**
     * Project ids visible under the given scope.
     *
     * @return array<int, int>|null
     */
    public function visibleProjectIds(User $user, string $scope): ?array
    {
        $employee = $this->employeeFor($user);
        if (! $employee) {
            return [];
        }

        return match ($scope) {
            'all' => null,
            'owned' => $this->managedProjectIds($employee),
            'team' => $this->memberProjectIds($employee),
            // An employee with 'self' on Projects still needs to see the
            // projects they work on, or their own tickets have no context.
            'self' => $this->memberProjectIds($employee),
            default => [],
        };
    }

    /**
     * Everyone reporting into this person: members of projects they manage,
     * plus members of modules they lead. Includes themselves — a manager
     * appears in their own team list.
     *
     * @return array<int, int>
     */
    public function managedEmployeeIds(?Employee $employee): array
    {
        if (! $employee) {
            return [];
        }

        $projectIds = $this->managedProjectIds($employee);
        $ledModuleIds = $this->ledModuleIds($employee);

        $ids = collect([$employee->id]);

        if ($projectIds !== []) {
            $ids = $ids
                ->merge(DB::table('project_members')
                    ->whereIn('project_id', $projectIds)
                    ->pluck('employee_id'))
                // Someone on a module of a managed project reports in even if
                // they were never added to the project itself.
                ->merge(DB::table('project_module_members')
                    ->join('project_modules', 'project_modules.id', '=', 'project_module_members.project_module_id')
                    ->whereIn('project_modules.project_id', $projectIds)
                    ->pluck('project_module_members.employee_id'));
        }

        if ($ledModuleIds !== []) {
            $ids = $ids->merge(DB::table('project_module_members')
                ->whereIn('project_module_id', $ledModuleIds)
                ->pluck('employee_id'));
        }

        return $ids->unique()->values()->all();
    }

    /**
     * Everyone this person shares a project or module with.
     *
     * @return array<int, int>
     */
    public function teammateIds(?Employee $employee): array
    {
        if (! $employee) {
            return [];
        }

        $projectIds = $this->memberProjectIds($employee);
        $moduleIds = $this->memberModuleIds($employee);

        $ids = collect([$employee->id]);

        if ($projectIds !== []) {
            $ids = $ids->merge(DB::table('project_members')
                ->whereIn('project_id', $projectIds)
                ->pluck('employee_id'));
        }

        if ($moduleIds !== []) {
            $ids = $ids->merge(DB::table('project_module_members')
                ->whereIn('project_module_id', $moduleIds)
                ->pluck('employee_id'));
        }

        return $ids->unique()->values()->all();
    }

    /** @return array<int, int> */
    public function managedProjectIds(?Employee $employee): array
    {
        if (! $employee) {
            return [];
        }

        // Projects managed directly, plus the parents of modules they lead —
        // a team lead needs their project's context without managing it.
        return DB::table('projects')
            ->where('manager_id', $employee->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->merge(
                DB::table('project_modules')
                    ->where('lead_id', $employee->id)
                    ->whereNull('deleted_at')
                    ->pluck('project_id')
            )
            ->unique()->values()->all();
    }

    /** @return array<int, int> */
    public function ledModuleIds(?Employee $employee): array
    {
        if (! $employee) {
            return [];
        }

        return DB::table('project_modules')
            ->where('lead_id', $employee->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();
    }

    /** Projects they belong to, manage, or lead a module within. */
    private function memberProjectIds(Employee $employee): array
    {
        return DB::table('project_members')
            ->where('employee_id', $employee->id)
            ->pluck('project_id')
            ->merge($this->managedProjectIds($employee))
            ->merge(
                DB::table('project_module_members')
                    ->join('project_modules', 'project_modules.id', '=', 'project_module_members.project_module_id')
                    ->where('project_module_members.employee_id', $employee->id)
                    ->pluck('project_modules.project_id')
            )
            ->unique()->values()->all();
    }

    private function memberModuleIds(Employee $employee): array
    {
        return DB::table('project_module_members')
            ->where('employee_id', $employee->id)
            ->pluck('project_module_id')
            ->merge($this->ledModuleIds($employee))
            ->unique()->values()->all();
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
