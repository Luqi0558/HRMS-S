<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ports the permission grid from the Vue store (state.rolePermissions in
 * src/store/index.js) into the tenant database, row for row.
 *
 * Until this runs, role_permissions is empty and `can()` denies everything —
 * a user can sign in and then reach no page at all.
 *
 * Kept as a literal table rather than generated from role names, because the
 * grid IS the specification: reading which role holds which action on which
 * module should not require executing anything.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = collect(self::GRID)->map(fn (array $row) => [
            'role' => $row[0],
            'module' => $row[1],
            'view' => $row[2],
            'create' => $row[3],
            'approve' => $row[4],
            'finance' => $row[5],
            'scope' => $row[6],
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // upsert on the (role, module) unique index, so re-seeding an existing
        // tenant updates the grid instead of failing on duplicates.
        DB::table('role_permissions')->upsert(
            $rows,
            ['role', 'module'],
            ['view', 'create', 'approve', 'finance', 'scope', 'updated_at']
        );
    }

    /** [role, module, view, create, approve, finance, scope] */
    private const GRID = [
            ['employee', 'Dashboard', true, false, false, false, 'self'],
            ['employee', 'Projects', true, false, false, false, 'team'],
            ['employee', 'Tickets', true, true, false, false, 'team'],
            ['employee', 'Leave Request', true, true, false, false, 'self'],
            ['employee', 'Medical Request', true, true, false, false, 'self'],
            ['employee', 'Payroll', true, false, false, false, 'self'],
            ['employee', 'Messages', true, true, false, false, 'self'],
            ['employee', 'Company Hub', true, false, false, false, 'all'],
            ['employee', 'Profile', true, false, false, false, 'self'],
            ['manager', 'Dashboard', true, false, false, false, 'owned'],
            ['manager', 'Employees', true, false, false, false, 'owned'],
            ['manager', 'Projects', true, true, true, false, 'owned'],
            ['manager', 'Tickets', true, true, false, false, 'owned'],
            ['manager', 'Team Leaves', true, false, false, false, 'owned'],
            ['manager', 'Leave Request', true, true, false, false, 'self'],
            ['manager', 'Medical Request', true, true, false, false, 'self'],
            ['manager', 'Finance', true, false, false, true, 'owned'],
            ['manager', 'Payroll', true, false, false, false, 'self'],
            ['manager', 'Reports', true, false, false, false, 'owned'],
            ['manager', 'Messages', true, true, false, false, 'owned'],
            ['manager', 'Company Hub', true, false, false, false, 'all'],
            ['manager', 'Profile', true, false, false, false, 'self'],
            ['hr', 'Dashboard', true, false, false, false, 'all'],
            ['hr', 'Employees', true, true, false, false, 'all'],
            ['hr', 'Attendance', true, false, false, false, 'all'],
            ['hr', 'Leave Request', true, true, true, false, 'all'],
            ['hr', 'Medical Request', true, false, true, false, 'all'],
            ['hr', 'Payroll', true, true, false, false, 'all'],
            ['hr', 'Assets', true, true, false, false, 'all'],
            ['hr', 'Petty Cash', true, true, false, true, 'all'],
            ['hr', 'Calendar', true, true, false, false, 'all'],
            ['hr', 'Reports', true, false, false, false, 'all'],
            ['hr', 'Messages', true, true, false, false, 'all'],
            ['hr', 'Company Hub', true, true, false, false, 'all'],
            ['hr', 'Profile', true, false, false, false, 'self'],
            ['team_lead', 'Dashboard', true, false, false, false, 'owned'],
            ['team_lead', 'Projects', true, false, true, false, 'owned'],
            ['team_lead', 'Tickets', true, true, true, false, 'owned'],
            ['team_lead', 'Team Leaves', true, false, true, false, 'owned'],
            ['team_lead', 'Leave Request', true, true, false, false, 'self'],
            ['team_lead', 'Medical Request', true, true, false, false, 'self'],
            ['team_lead', 'Payroll', true, false, false, false, 'self'],
            ['team_lead', 'Reports', true, false, false, false, 'owned'],
            ['team_lead', 'Messages', true, true, false, false, 'owned'],
            ['team_lead', 'Company Hub', true, false, false, false, 'all'],
            ['team_lead', 'Profile', true, false, false, false, 'self'],
            ['super_admin', 'All modules', true, true, true, true, 'all'],
            ['super_admin', 'Leave Request', true, false, true, false, 'all'],
            ['super_admin', 'Medical Request', true, false, true, false, 'all'],
            ['super_admin', 'Tickets', true, false, false, false, 'all'],
    ];
}
