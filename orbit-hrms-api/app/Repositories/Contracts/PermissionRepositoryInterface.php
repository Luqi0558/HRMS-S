<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface PermissionRepositoryInterface
{
    /** Every row in the grid, for an admin editing it. */
    public function all(): Collection;

    /** The rows that apply to one role. */
    public function forRole(string $role): Collection;
}
