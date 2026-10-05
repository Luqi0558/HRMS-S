<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Administration of people-to-role assignments. PermissionService owns the
 * role policies themselves; this service owns which authenticated person is
 * assigned to which policy.
 */
class AccessControlService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ActivityLogService $activity,
    ) {
    }

    public function people(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->assertMayManage($actor);

        return Employee::query()
            ->with('user:id,role,status')
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $query->where(function ($nested) use ($term) {
                    $nested->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('employee_code', 'like', $term)
                        ->orWhereHas('user', fn ($users) => $users->where('role', 'like', $term));
                });
            })
            ->when(filled($filters['role'] ?? null), fn ($query) =>
                $query->whereHas('user', fn ($users) => $users->where('role', $filters['role']))
            )
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function assignRole(User $actor, int $employeeId, string $role, ?string $reason = null): Employee
    {
        $this->assertMayManage($actor);

        return DB::transaction(function () use ($actor, $employeeId, $role, $reason) {
            $employee = Employee::query()->with('user')->lockForUpdate()->find($employeeId);
            if (! $employee) {
                throw new NotFoundHttpException('Employee not found.');
            }
            if (! $employee->user) {
                throw ValidationException::withMessages([
                    'role' => 'This employee does not have a sign-in account yet.',
                ]);
            }
            if ($employee->user->id === $actor->id) {
                throw new AccessDeniedHttpException('Assign another Super Admin to change your own access.');
            }
            if (! in_array($role, $this->permissions->roles($actor), true)) {
                throw ValidationException::withMessages([
                    'role' => 'Select an existing role profile.',
                ]);
            }

            $previousRole = $employee->user->role;
            if ($previousRole === $role) return $employee;

            if ($previousRole === 'super_admin'
                && User::query()->where('role', 'super_admin')->where('status', 'active')->count() <= 1) {
                throw new ConflictHttpException('Assign another active Super Admin before changing the final Super Admin.');
            }

            $employee->user->role = $role;
            $employee->user->save();
            // Role checks are already server-side on every request. Revoking
            // tokens also prevents a stale browser menu from being mistaken for
            // a valid session after a sensitive access change.
            $employee->user->tokens()->delete();

            $this->activity->record($actor, [
                'module' => 'access_control',
                'action' => 'role assigned',
                'details' => sprintf(
                    '%s access changed from %s to %s.%s',
                    $employee->name,
                    $this->label($previousRole),
                    $this->label($role),
                    $reason ? ' Reason: '.$reason : '',
                ),
                'targetId' => $employee->id,
                'targetType' => 'employee',
            ]);

            return $employee->fresh('user');
        });
    }

    private function assertMayManage(User $user): void
    {
        if ($user->role !== 'super_admin') {
            throw new AccessDeniedHttpException('Only Super Admin may manage access.');
        }
    }

    private function label(string $role): string
    {
        return $role === 'super_admin' ? 'Super Admin' : ucwords(str_replace('_', ' ', $role));
    }
}
