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
}
