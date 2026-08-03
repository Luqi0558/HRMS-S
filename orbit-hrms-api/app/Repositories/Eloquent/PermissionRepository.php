<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the role_permissions grid. No model: the table is a lookup with no
 * behaviour of its own, and every consumer wants plain rows.
 */
class PermissionRepository implements PermissionRepositoryInterface
{
    public function all(): Collection
    {
        return DB::table('role_permissions')
            ->orderBy('role')
            ->orderBy('module')
            ->get();
    }

    public function forRole(string $role): Collection
    {
        return DB::table('role_permissions')
            ->where('role', $role)
            ->orderBy('module')
            ->get();
    }
}
