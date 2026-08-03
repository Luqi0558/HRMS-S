<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Collection;

class PermissionService
{
    /**
     * Super admin oversees the company rather than participating in it: it
     * reviews leave and claims but never files its own, and it does not work
     * tickets. These modules fall through to their explicit rows instead of
     * the blanket grant below.
     *
     * Mirrors SUPER_ADMIN_EXCLUDED in src/store/index.js — the two must agree
     * or the UI will offer actions the API refuses.
     */
    private const SUPER_ADMIN_EXCLUDED = ['Leave Request', 'Medical Request', 'Tickets'];

    private const SUPER_ADMIN_GRANT = [
        'view' => true,
        'create' => true,
        'approve' => true,
        'finance' => true,
        'scope' => 'all',
    ];

    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
    ) {
    }

    /**
     * The effective grid for a user, resolved so the client does not have to
     * reimplement the super-admin fallthrough.
     *
     * @return array<int, array{module: string, view: bool, create: bool, approve: bool, finance: bool, scope: string}>
     */
    public function forUser(User $user): array
    {
        $rows = $this->permissions->forRole($user->role)
            ->map(fn ($row) => [
                'module' => $row->module,
                'view' => (bool) $row->view,
                'create' => (bool) $row->create,
                'approve' => (bool) $row->approve,
                'finance' => (bool) $row->finance,
                'scope' => $row->scope,
            ]);

        if ($user->role !== 'super_admin') {
            return $rows->values()->all();
        }

        return $this->withSuperAdminGrant($rows);
    }

    /**
     * Expands super admin's single "All modules" row into a concrete row per
     * module, so the client can look up any module by name without knowing the
     * wildcard convention.
     */
    private function withSuperAdminGrant(Collection $rows): array
    {
        $explicit = $rows->reject(fn ($row) => $row['module'] === 'All modules')
            ->keyBy('module');

        $modules = $this->permissions->all()
            ->pluck('module')
            ->reject(fn ($module) => $module === 'All modules')
            ->unique();

        return $modules->map(function (string $module) use ($explicit) {
            // An excluded module keeps whatever explicit row it has; anything
            // else gets the blanket grant.
            if (in_array($module, self::SUPER_ADMIN_EXCLUDED, true)) {
                return $explicit->get($module) ?? [
                    'module' => $module,
                    'view' => false,
                    'create' => false,
                    'approve' => false,
                    'finance' => false,
                    'scope' => 'self',
                ];
            }

            return array_merge(['module' => $module], self::SUPER_ADMIN_GRANT);
        })->values()->all();
    }
}
