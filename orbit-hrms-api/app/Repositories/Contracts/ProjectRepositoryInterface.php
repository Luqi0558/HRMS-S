<?php

namespace App\Repositories\Contracts;

use App\Models\Project;
use Illuminate\Support\Collection;

interface ProjectRepositoryInterface
{
    /**
     * @param array<int, int>|null $visibleIds null = no restriction ('all')
     */
    public function listScoped(?array $visibleIds, array $filters = []): Collection;

    public function findBySlug(string $slug): ?Project;

    public function findById(int $id): ?Project;

    public function create(array $attributes): Project;

    public function update(Project $project, array $attributes): Project;

    /** Replaces the member set with exactly these employees. */
    public function syncMembers(Project $project, array $employeeIds): void;

    /** Eager-loads modules, members and ticket counts for the detail screen. */
    public function loadDetail(Project $project): Project;

    public function findModule(int $moduleId): ?\App\Models\ProjectModule;

    public function updateModule(\App\Models\ProjectModule $module, array $attributes): \App\Models\ProjectModule;
}
