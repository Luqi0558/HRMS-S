<?php

namespace App\Repositories\Eloquent;

use App\Models\Employee;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

class EmployeeRepository implements EmployeeRepositoryInterface
{
    public function __construct(private readonly Employee $model)
    {
    }

    public function findById(int $id): ?Employee
    {
        return $this->model->newQuery()->find($id);
    }

    public function findByEmail(string $email): ?Employee
    {
        return $this->model->newQuery()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();
    }

    public function findByUserId(int $userId): ?Employee
    {
        return $this->model->newQuery()->where('user_id', $userId)->first();
    }

    public function create(array $attributes): Employee
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function update(Employee $employee, array $attributes): Employee
    {
        $employee->fill($attributes)->save();

        return $employee->refresh();
    }

    /**
     * Read-then-increment, so two invitations created in the same instant can
     * compute the same code. The unique index on employee_code is what makes
     * that safe: the loser gets a QueryException rather than a duplicate, and
     * InvitationService retries. Callers must therefore run this inside the
     * same transaction as the insert.
     */
    public function nextEmployeeCode(): string
    {
        // withTrashed(): a soft-deleted employee still owns its code, so
        // ignoring them would hand the same code to two people.
        $last = $this->model->newQuery()
            ->withTrashed()
            ->orderByDesc('id')
            ->value('employee_code');

        $next = 1;
        if ($last && preg_match('/(\d+)$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return sprintf('ORB-%04d', $next);
    }

    public function loadFullProfile(Employee $employee): Employee
    {
        // technicalProfile.stacks/skills are keyed on employee_id, so loading
        // them through the technical profile keeps the resource able to read
        // them without reaching back to the parent employee.
        return $employee->load([
            'user',
            'profile',
            'technicalProfile.stacks',
            'technicalProfile.skills',
            'documents',
        ]);
    }
}
