<?php

namespace App\Repositories\Contracts;

use App\Models\Employee;

interface EmployeeRepositoryInterface
{
    public function findById(int $id): ?Employee;

    public function findByEmail(string $email): ?Employee;

    public function findByUserId(int $userId): ?Employee;

    public function create(array $attributes): Employee;

    public function update(Employee $employee, array $attributes): Employee;

    /**
     * Sequential per-tenant code (ORB-0001). Separate from the surrogate key so
     * it can be reformatted without touching foreign keys.
     */
    public function nextEmployeeCode(): string;

    /** Eager-loads the onboarding relations so the profile endpoint is one query set. */
    public function loadFullProfile(Employee $employee): Employee;

    /**
     * Paginated list, optionally restricted to a set of ids.
     *
     * A null $visibleIds means no restriction ('all' scope). An empty array
     * means "nobody" — the two must not be conflated, or a scope that resolves
     * to nothing would silently return everyone.
     *
     * @param array<int, int>|null $visibleIds
     */
    public function paginateScoped(
        ?array $visibleIds,
        array $filters = [],
        int $perPage = 25
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator;
}
