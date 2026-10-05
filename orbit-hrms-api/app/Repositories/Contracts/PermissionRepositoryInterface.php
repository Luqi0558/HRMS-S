<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface PermissionRepositoryInterface
{
    /** Every row in the grid, for an admin editing it. */
    public function all(): Collection;

    /** Every row, locked while a permission-matrix update is in progress. */
    public function allForUpdate(): Collection;

    /** The rows that apply to one role. */
    public function forRole(string $role): Collection;

    /** Updates an existing role/module row. */
    public function update(string $role, string $module, array $attributes): void;
}
