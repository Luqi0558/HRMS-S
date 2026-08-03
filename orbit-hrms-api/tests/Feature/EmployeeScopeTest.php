<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

/**
 * The scope rules are the app's authorization model, so these are the tests
 * that matter most: a mistake here leaks staff records across teams.
 */
class EmployeeScopeTest extends TenantTestCase
{
    private Employee $hr;
    private Employee $manager;
    private Employee $lead;
    private Employee $memberA;
    private Employee $memberB;
    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
        $this->manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->lead = $this->makeEmployee('lead@test.com', 'team_lead');
        $this->memberA = $this->makeEmployee('a@test.com', 'employee');
        $this->memberB = $this->makeEmployee('b@test.com', 'employee');
        // On no project at all — the control for every scope assertion.
        $this->outsider = $this->makeEmployee('outsider@test.com', 'employee');

        $project = Project::create([
            'name' => 'Internal',
            'slug' => 'internal',
            'manager_id' => $this->manager->id,
        ]);
        $project->members()->sync([$this->memberA->id]);

        $module = ProjectModule::create([
            'project_id' => $project->id,
            'name' => 'Attendance',
            'slug' => 'attendance',
            'lead_id' => $this->lead->id,
        ]);
        $module->members()->sync([$this->memberB->id]);
    }

    private function makeEmployee(string $email, string $role): Employee
    {
        $user = new User();
        $user->fill(['name' => $email, 'email' => $email, 'password' => 'password123']);
        $user->role = $role;
        $user->status = 'active';
        $user->save();

        return Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ORB-'.substr(md5($email), 0, 6),
            'name' => $email,
            'email' => $email,
            'status' => 'active',
        ]);
    }

    private function tokenFor(string $email): string
    {
        return $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password123',
        ], $this->tenantHeaders())->json('token');
    }

    private function listAs(string $email): array
    {
        return $this->getJson('/api/employees', $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor($email),
        ]))->assertOk()->json('data');
    }

    public function test_hr_sees_every_employee(): void
    {
        $emails = collect($this->listAs('hr@test.com'))->pluck('email');

        $this->assertCount(6, $emails);
        $this->assertTrue($emails->contains('outsider@test.com'));
    }

    /**
     * A manager sees their own people — members of projects they manage and of
     * modules within them — and nobody else.
     */
    public function test_a_manager_sees_only_their_own_people(): void
    {
        $emails = collect($this->listAs('manager@test.com'))->pluck('email');

        $this->assertTrue($emails->contains('manager@test.com'), 'should include themselves');
        $this->assertTrue($emails->contains('a@test.com'), 'project member');
        $this->assertTrue($emails->contains('b@test.com'), 'member of a module in their project');

        $this->assertFalse($emails->contains('outsider@test.com'), 'must not leak unrelated staff');
        $this->assertFalse($emails->contains('hr@test.com'));
    }

    public function test_an_employee_cannot_list_employees_at_all(): void
    {
        $this->getJson('/api/employees', $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor('a@test.com'),
        ]))
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /** Otherwise nobody could open their own profile page. */
    public function test_an_employee_may_read_their_own_record(): void
    {
        $this->getJson("/api/employees/{$this->memberA->id}", $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor('a@test.com'),
        ]))
            ->assertOk()
            ->assertJsonPath('employee.email', 'a@test.com');
    }

    /**
     * 404 rather than 403: a 403 would confirm the record exists, which tells
     * an unauthorised caller that person is on staff.
     */
    public function test_reading_someone_elses_record_is_indistinguishable_from_a_missing_one(): void
    {
        $auth = $this->tenantHeaders(['Authorization' => 'Bearer '.$this->tokenFor('a@test.com')]);

        $forbidden = $this->getJson("/api/employees/{$this->outsider->id}", $auth);
        $missing = $this->getJson('/api/employees/999999', $auth);

        $forbidden->assertStatus(404);
        $missing->assertStatus(404);
        $this->assertSame($missing->json('message'), $forbidden->json('message'));
    }

    public function test_an_employee_cannot_edit_anyone(): void
    {
        $this->patchJson("/api/employees/{$this->outsider->id}", [
            'designation' => 'CEO',
        ], $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor('a@test.com'),
        ]))->assertStatus(403);

        $this->assertNotSame('CEO', $this->outsider->fresh()->designation);
    }

    public function test_hr_can_edit_an_employee(): void
    {
        $this->patchJson("/api/employees/{$this->memberA->id}", [
            'designation' => 'Senior Software Engineer',
            'department' => 'Engineering',
        ], $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor('hr@test.com'),
        ]))->assertOk();

        $this->assertSame('Senior Software Engineer', $this->memberA->fresh()->designation);
    }

    /**
     * Email and status are not editable through this endpoint: an email change
     * would orphan the login it is tied to, and status transitions are audited
     * actions rather than field edits.
     */
    public function test_email_and_status_cannot_be_changed_here(): void
    {
        $this->patchJson("/api/employees/{$this->memberA->id}", [
            'email' => 'hijacked@test.com',
            'status' => 'blocked',
        ], $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor('hr@test.com'),
        ]))->assertOk();

        $fresh = $this->memberA->fresh();
        $this->assertSame('a@test.com', $fresh->email);
        $this->assertSame('active', $fresh->status);
    }

    public function test_the_list_can_be_searched(): void
    {
        $rows = $this->getJson('/api/employees?search=outsider', $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor('hr@test.com'),
        ]))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('outsider@test.com', $rows[0]['email']);
    }

    public function test_the_list_is_paginated_with_a_capped_page_size(): void
    {
        $meta = $this->getJson('/api/employees?per_page=500', $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor('hr@test.com'),
        ]))->assertOk()->json('meta');

        // An unbounded per_page is a trivial way to make the server
        // materialise the whole table.
        $this->assertSame(100, $meta['perPage']);
        $this->assertSame(6, $meta['total']);
    }
}
