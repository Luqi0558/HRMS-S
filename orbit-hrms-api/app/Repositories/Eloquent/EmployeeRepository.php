<?php

namespace App\Repositories\Eloquent;

use App\Models\Employee;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    public function paginateScoped(
        ?array $visibleIds,
        array $filters = [],
        int $perPage = 25
    ): LengthAwarePaginator {
        $query = $this->model->newQuery()->with('user:id,role,status');
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhere('designation', 'like', $term);
            });
        }

        foreach (['status', 'department', 'designation'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    public function loadFullProfile(Employee $employee): Employee
    {
        return $employee->load([
            'user',
            'profile',
            'technicalProfile.stacks',
            'technicalProfile.skills',
            'documents',
        ]);
    }
}
