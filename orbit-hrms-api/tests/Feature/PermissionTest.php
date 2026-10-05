<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

class PermissionTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function signIn(string $role): string
    {
        $user = new User();
        $user->fill([
            'name' => 'Test '.$role,
            'email' => $role.'@test.com',
            'password' => 'password123',
        ]);
        $user->role = $role;
        $user->status = 'active';
        $user->save();

        return $this->postJson('/api/login', [
            'email' => $role.'@test.com',
            'password' => 'password123',
        ], $this->tenantHeaders())->json('token');
    }

    public function test_permissions_require_authentication(): void
    {
        $this->getJson('/api/me/permissions', $this->tenantHeaders())
            ->assertStatus(401);
    }

    public function test_an_employee_receives_only_their_own_roles_rules(): void
    {
        $token = $this->signIn('employee');

        $rows = $this->getJson('/api/me/permissions', $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))->assertOk()->json('permissions');

        $this->assertNotEmpty($rows);

        $dashboard = collect($rows)->firstWhere('module', 'Dashboard');
        $this->assertTrue($dashboard['view']);
        $this->assertFalse($dashboard['create']);
        $this->assertSame('self', $dashboard['scope']);

        // Employees have no Employees row at all, so the module is absent
        // rather than present-and-denied.
        $this->assertNull(collect($rows)->firstWhere('module', 'Employees'));
    }

    /**
     * The response must not disclose other roles' rules — that is the app's
     * authorization model, and a client only needs its own.
     */
    public function test_the_grid_is_scoped_to_the_signed_in_role(): void
    {
        $token = $this->signIn('employee');

        $rows = $this->getJson('/api/me/permissions', $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))->json('permissions');

        // An HR-only module must not appear.
        $this->assertNull(collect($rows)->firstWhere('module', 'Petty Cash'));
    }

    /**
     * One authenticated request per test: within a single test process Laravel
     * keeps the user resolved by the first call on the auth guard, so a second
     * request with a different token still answers as the first user.
     */
    public function test_hr_may_create_employees(): void
    {
        $rows = $this->getJson('/api/me/permissions', $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->signIn('hr'),
        ]))->json('permissions');

        $employees = collect($rows)->firstWhere('module', 'Employees');
        $this->assertNotNull($employees);
        $this->assertTrue($employees['create']);
        $this->assertSame('all', $employees['scope']);
    }

    /**
     * Super admin holds a single "All modules" row. The API expands it to a
     * concrete row per module so the client does not have to know that
     * convention — and the excluded modules keep their explicit rows.
     */
    public function test_super_admin_receives_an_expanded_grid(): void
    {
        $rows = $this->getJson('/api/me/permissions', $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->signIn('super_admin'),
        ]))->json('permissions');

        $modules = collect($rows)->pluck('module');
        $this->assertFalse($modules->contains('All modules'), 'the wildcard row should be expanded away');
        $this->assertTrue($modules->contains('Employees'));
        $this->assertTrue(collect($rows)->firstWhere('module', 'Employees')['create']);

        // Super admin reviews leave but never files its own.
        $leave = collect($rows)->firstWhere('module', 'Leave Request');
        $this->assertTrue($leave['approve']);
        $this->assertFalse($leave['create'], 'super admin should not create its own leave requests');

        // ...and does not work tickets.
        $tickets = collect($rows)->firstWhere('module', 'Tickets');
        $this->assertFalse($tickets['create']);
    }

    public function test_only_super_admin_may_read_the_company_permission_matrix(): void
    {
        $token = $this->signIn('employee');

        $this->getJson('/api/access-control/permissions', $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))->assertForbidden();
    }

    public function test_super_admin_receives_the_stored_matrix_for_every_role(): void
    {
        $rows = $this->getJson('/api/access-control/permissions', $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->signIn('super_admin'),
        ]))->assertOk()->json('permissions');

        $this->assertTrue(collect($rows)->pluck('role')->contains('employee'));
        $this->assertTrue(collect($rows)->pluck('role')->contains('hr'));
        $this->assertTrue(collect($rows)->pluck('role')->contains('super_admin'));
    }

    public function test_super_admin_can_update_an_existing_non_admin_row_and_it_is_audited(): void
    {
        $token = $this->signIn('super_admin');

        $this->putJson('/api/access-control/permissions', [
            'permissions' => [[
                'role' => 'employee',
                'module' => 'Projects',
                'view' => false,
                'create' => false,
                'approve' => false,
                'finance' => false,
                'scope' => 'team',
            ]],
        ], $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))->assertOk()
            ->assertJsonPath('message', 'Role permissions saved.');

        $this->assertDatabaseHas('role_permissions', [
            'role' => 'employee',
            'module' => 'Projects',
            'view' => false,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'module' => 'access_control',
            'action' => 'permission updated',
            'target_type' => 'role_permission',
        ]);
    }

    public function test_matrix_rejects_actions_when_view_is_disabled(): void
    {
        $token = $this->signIn('super_admin');

        $this->putJson('/api/access-control/permissions', [
            'permissions' => [[
                'role' => 'employee',
                'module' => 'Projects',
                'view' => false,
                'create' => true,
                'approve' => false,
                'finance' => false,
                'scope' => 'team',
            ]],
        ], $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0.view');
    }

    public function test_matrix_rejects_changes_to_the_protected_super_admin_rows(): void
    {
        $token = $this->signIn('super_admin');

        $this->putJson('/api/access-control/permissions', [
            'permissions' => [[
                'role' => 'super_admin',
                'module' => 'All modules',
                'view' => true,
                'create' => false,
                'approve' => true,
                'finance' => true,
                'scope' => 'all',
            ]],
        ], $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0.role');
    }
}
