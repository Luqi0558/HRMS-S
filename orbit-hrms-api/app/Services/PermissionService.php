<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PermissionService
{
    public const SCOPES = ['all', 'owned', 'team', 'self'];

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
     * The complete stored matrix is intentionally separate from forUser().
     * The former is a Super Admin configuration view; the latter is the
     * smallest effective grid that an ordinary signed-in client needs.
     */
    public function matrix(User $user): array
    {
        $this->assertMayManageMatrix($user);

        return $this->normaliseRows($this->permissions->all());
    }

    /** The role profiles available to the Access Management workspace. */
    public function roles(User $user): array
    {
        $this->assertMayManageMatrix($user);

        return $this->permissions->all()
            ->pluck('role')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Persists selected rows atomically. The client may submit the whole
     * matrix, but omitted rows are deliberately left unchanged so a paginated
     * or filtered UI can never silently revoke unrelated access.
     *
     * @param array<int, array{role:string,module:string,view:bool,create:bool,approve:bool,finance:bool,scope:string}> $rows
     */
    public function updateMatrix(User $user, array $rows): array
    {
        $this->assertMayManageMatrix($user);

        return DB::transaction(function () use ($user, $rows) {
            $current = $this->permissions->allForUpdate()
                ->keyBy(fn ($row) => $row->role.'|'.$row->module);
            $seen = [];

            foreach ($rows as $index => $row) {
                $key = $row['role'].'|'.$row['module'];
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages([
                        "permissions.$index" => 'Each role and module may appear only once.',
                    ]);
                }
                $seen[$key] = true;

                $stored = $current->get($key);
                if (! $stored) {
                    throw ValidationException::withMessages([
                        "permissions.$index.module" => 'That role/module permission does not exist.',
                    ]);
                }

                $next = $this->normaliseRow($row);
                $before = $this->normaliseRow((array) $stored);

                if (! $next['view'] && ($next['create'] || $next['approve'] || $next['finance'])) {
                    throw ValidationException::withMessages([
                        "permissions.$index.view" => 'View access is required when another permission is enabled.',
                    ]);
                }

                // This is the emergency administrator account. Its blanket
                // grant must remain intact so an ordinary matrix edit cannot
                // lock the company out of Access Control.
                if ($next['role'] === 'super_admin') {
                    if ($next !== $before) {
                        throw ValidationException::withMessages([
                            "permissions.$index.role" => 'Super Admin system permissions cannot be changed here.',
                        ]);
                    }
                    continue;
                }

                if ($next === $before) continue;

                $this->permissions->update($next['role'], $next['module'], [
                    'view' => $next['view'],
                    'create' => $next['create'],
                    'approve' => $next['approve'],
                    'finance' => $next['finance'],
                    'scope' => $next['scope'],
                    'updated_at' => now(),
                ]);

                $this->recordAudit($user, $before, $next);
            }

            return $this->normaliseRows($this->permissions->all());
        });
    }

    /**
     * The effective grid for a user, resolved so the client does not have to
     * reimplement the super-admin fallthrough.
     *
     * @return array<int, array{module: string, view: bool, create: bool, approve: bool, finance: bool, scope: string}>
     */
    public function can(User $user, string $module, string $action = 'view'): bool
    {
        $row = collect($this->forUser($user))->firstWhere('module', $module);

        // Super admin's blanket grant covers modules no role names explicitly.
        // withSuperAdminGrant() can only expand over modules already in the
        // grid, so a module gated by route middleware but absent from the
        // seed — Settings is one — would otherwise 403 for everyone. The
        // frontend's permissionRow() falls through the same way; the two must
        // agree or the UI offers actions the API refuses.
        if (! $row
            && $user->role === 'super_admin'
            && ! in_array($module, self::SUPER_ADMIN_EXCLUDED, true)) {
            return (bool) (self::SUPER_ADMIN_GRANT[$action] ?? false);
        }

        return (bool) ($row[$action] ?? false);
    }

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

    private function assertMayManageMatrix(User $user): void
    {
        if ($user->role !== 'super_admin') {
            throw new AccessDeniedHttpException('Only Super Admin may manage role permissions.');
        }
    }

    private function normaliseRows(Collection $rows): array
    {
        return $rows
            ->map(fn ($row) => $this->normaliseRow((array) $row))
            ->values()
            ->all();
    }

    private function normaliseRow(array $row): array
    {
        return [
            'role' => $row['role'],
            'module' => $row['module'],
            'view' => (bool) $row['view'],
            'create' => (bool) $row['create'],
            'approve' => (bool) $row['approve'],
            'finance' => (bool) $row['finance'],
            'scope' => $row['scope'],
        ];
    }

    private function describeRow(array $row): string
    {
        return sprintf(
            'view:%s, create:%s, approve:%s, finance:%s, scope:%s',
            $row['view'] ? 'on' : 'off',
            $row['create'] ? 'on' : 'off',
            $row['approve'] ? 'on' : 'off',
            $row['finance'] ? 'on' : 'off',
            $row['scope'],
        );
    }

    private function recordAudit(User $user, array $before, array $next): void
    {
        $employee = Employee::where('user_id', $user->id)->first();

        ActivityLog::create([
            'actor_id' => $employee?->id,
            'actor_name' => $employee?->name ?? $user->name,
            'actor_role' => $user->role,
            'module' => 'access_control',
            'action' => 'permission updated',
            'details' => sprintf(
                '%s / %s changed from [%s] to [%s].',
                $this->roleLabel($next['role']),
                $next['module'],
                $this->describeRow($before),
                $this->describeRow($next),
            ),
            'target_type' => 'role_permission',
        ]);
    }

    private function roleLabel(string $role): string
    {
        return $role === 'super_admin'
            ? 'Super Admin'
            : ucwords(str_replace('_', ' ', $role));
    }
}
