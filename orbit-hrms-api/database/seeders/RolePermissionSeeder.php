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
            /*
             * QA owns the Review column: developers hand work over, QA either
             * passes it to Done or sends it back. 'approve' is what marks them
             * as a reviewer rather than someone who merely works the board —
             * TicketService reads it as supervisory, which is what lets them
             * act on a ticket that is not assigned to them.
             */
            ['qa', 'Dashboard', true, false, false, false, 'team'],
            ['qa', 'Projects', true, false, false, false, 'team'],
            ['qa', 'Tickets', true, true, true, false, 'team'],
            ['qa', 'Leave Request', true, true, false, false, 'self'],
            ['qa', 'Medical Request', true, true, false, false, 'self'],
            ['qa', 'Payroll', true, false, false, false, 'self'],
            ['qa', 'Messages', true, true, false, false, 'self'],
            ['qa', 'Company Hub', true, false, false, false, 'all'],
            ['qa', 'Profile', true, false, false, false, 'self'],

            /*
             * An intern is an employee with a narrower remit: no medical claims
             * and no petty cash, since neither applies to them. Everything else
             * — their tickets, leave, messages, own payslip — is unchanged.
             */
            ['intern', 'Dashboard', true, false, false, false, 'self'],
            ['intern', 'Projects', true, false, false, false, 'team'],
            ['intern', 'Tickets', true, true, false, false, 'team'],
            ['intern', 'Leave Request', true, true, false, false, 'self'],
            ['intern', 'Payroll', true, false, false, false, 'self'],
            ['intern', 'Messages', true, true, false, false, 'self'],
            ['intern', 'Company Hub', true, false, false, false, 'all'],
            ['intern', 'Profile', true, false, false, false, 'self'],

            ['manager', 'Dashboard', true, false, false, false, 'owned'],
            ['manager', 'Employees', true, false, false, false, 'owned'],
            // Their own people's punches. Without this the dashboard's team
            // attendance list showed only the viewer's own row — the API scoped
            // it to 'self' because the role held no Attendance permission at all.
            ['manager', 'Attendance', true, false, false, false, 'owned'],
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
            // 'create' so a lead can set up modules — training work for an
            // intern, or a new workstream in a project they already run. Scope
            // 'owned' bounds it to projects they lead; they cannot touch
            // anybody else's.
            ['team_lead', 'Projects', true, true, true, false, 'owned'],
            // Their squad's punches, for the Squad Today card. This is not a
            // route to the attendance ledger: /attendance is gated on
            // company-wide scope, which 'owned' is not — a lead reads their own
            // people through the API and nothing more.
            ['team_lead', 'Attendance', true, false, false, false, 'owned'],
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
