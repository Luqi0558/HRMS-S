<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

/**
 * Creating and editing modules.
 *
 * These endpoints did not exist: the client built a module with a Date.now()
 * id, pushed it into local state and logged an activity, so every module
 * anyone created disappeared on the next refresh — super admins included.
 */
class ProjectModuleTest extends TenantTestCase
{
    private Employee $manager;
    private Employee $lead;
    private Employee $worker;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->lead = $this->makeEmployee('lead@test.com', 'team_lead');
        $this->worker = $this->makeEmployee('worker@test.com', 'employee');

        $this->project = Project::create([
            'name' => 'Internship',
            'slug' => 'internship',
            'manager_id' => $this->manager->id,
        ]);
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

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    public function test_a_module_survives_the_request_that_created_it(): void
    {
        $this->postJson('/api/projects/internship/modules', [
            'name' => 'Onboarding Tasks',
        ], $this->auth('manager@test.com'))
            ->assertCreated()
            ->assertJsonPath('module.name', 'Onboarding Tasks')
            ->assertJsonPath('module.slug', 'onboarding-tasks');

        // The point of the whole endpoint: it is in the database, not in a
        // component's local state.
        $this->assertDatabaseHas('project_modules', [
            'project_id' => $this->project->id,
            'name' => 'Onboarding Tasks',
        ]);
    }

    /**
     * A lead runs training work, so they need to be able to set it up. Scope
     * still bounds them to projects they lead.
     */
    public function test_a_team_lead_can_create_a_module_in_a_project_they_lead(): void
    {
        // Leading a module of the project is what makes it theirs.
        ProjectModule::create([
            'project_id' => $this->project->id,
            'name' => 'Existing',
            'slug' => 'existing',
            'lead_id' => $this->lead->id,
        ]);

        $this->postJson('/api/projects/internship/modules', [
            'name' => 'Hamza Training',
        ], $this->auth('lead@test.com'))->assertCreated();
    }

    public function test_an_employee_cannot_create_a_module(): void
    {
        $this->postJson('/api/projects/internship/modules', [
            'name' => 'Sneaky',
        ], $this->auth('worker@test.com'))->assertForbidden();

        $this->assertDatabaseMissing('project_modules', ['name' => 'Sneaky']);
    }

    /**
     * Scoped to the project, not global: two projects may each hold an
     * "Attendance System", and the URL carries the project slug as well.
     */
    public function test_module_slugs_are_unique_within_a_project(): void
    {
        $headers = $this->auth('manager@test.com');

        $this->postJson('/api/projects/internship/modules', ['name' => 'Training'], $headers)
            ->assertCreated()
            ->assertJsonPath('module.slug', 'training');

        $this->postJson('/api/projects/internship/modules', ['name' => 'Training'], $headers)
            ->assertCreated()
            ->assertJsonPath('module.slug', 'training-2');
    }

    public function test_editing_a_module_persists(): void
    {
        $module = ProjectModule::create([
            'project_id' => $this->project->id,
            'name' => 'Draft',
            'slug' => 'draft',
        ]);

        $this->patchJson("/api/modules/{$module->id}", [
            'name' => 'Onboarding Tasks',
            'budget' => 5000,
        ], $this->auth('manager@test.com'))->assertOk();

        $module->refresh();
        $this->assertSame('Onboarding Tasks', $module->name);
        // The slug follows a rename, since the board's URL uses it.
        $this->assertSame('onboarding-tasks', $module->slug);
        $this->assertSame('5000.00', $module->budget);
    }

    public function test_module_membership_can_be_set(): void
    {
        $module = ProjectModule::create([
            'project_id' => $this->project->id,
            'name' => 'Training',
            'slug' => 'training',
        ]);

        $this->putJson("/api/modules/{$module->id}/members", [
            'employeeIds' => [$this->worker->id],
        ], $this->auth('manager@test.com'))->assertOk();

        $this->assertSame([$this->worker->id], $module->members()->pluck('employees.id')->all());
    }
}
