<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

class ProjectTicketTest extends TenantTestCase
{
    private Employee $manager;
    private Employee $member;
    private Employee $outsider;
    private Project $project;
    private ProjectModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->member = $this->makeEmployee('member@test.com', 'employee');
        $this->outsider = $this->makeEmployee('outsider@test.com', 'employee');

        $this->project = Project::create([
            'name' => 'Internal',
            'slug' => 'internal',
            'manager_id' => $this->manager->id,
        ]);
        $this->project->members()->sync([$this->member->id]);

        $this->module = ProjectModule::create([
            'project_id' => $this->project->id,
            'name' => 'Attendance',
            'slug' => 'attendance',
        ]);
        $this->module->members()->sync([$this->member->id]);
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

    // --- projects --------------------------------------------------------

    public function test_a_manager_sees_projects_they_manage(): void
    {
        $rows = $this->getJson('/api/projects', $this->auth('manager@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('internal', $rows[0]['slug']);
    }

    public function test_a_member_sees_projects_they_belong_to(): void
    {
        $rows = $this->getJson('/api/projects', $this->auth('member@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
    }

    public function test_an_outsider_sees_no_projects(): void
    {
        $rows = $this->getJson('/api/projects', $this->auth('outsider@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(0, $rows);
    }

    /** 404 rather than 403 — a 403 confirms the project exists. */
    public function test_an_outsider_cannot_open_a_project(): void
    {
        $this->getJson('/api/projects/internal', $this->auth('outsider@test.com'))
            ->assertStatus(404);
    }

    public function test_the_list_carries_ticket_counts(): void
    {
        Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Open work',
            'status' => 'todo',
        ]);
        Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Finished work',
            'status' => 'done',
        ]);

        $row = $this->getJson('/api/projects', $this->auth('manager@test.com'))
            ->json('data.0');

        $this->assertSame(2, $row['ticketCount']);
        $this->assertSame(1, $row['openTicketCount']);
    }

    public function test_an_employee_cannot_create_a_project(): void
    {
        $this->postJson('/api/projects', ['name' => 'Sneaky'], $this->auth('member@test.com'))
            ->assertStatus(403);
    }

    public function test_a_manager_can_create_a_project_and_gets_a_unique_slug(): void
    {
        $first = $this->postJson('/api/projects', ['name' => 'Internal'], $this->auth('manager@test.com'))
            ->assertCreated()->json('project.slug');

        // 'internal' is taken by the fixture, so the new one must not collide.
        $this->assertNotSame('internal', $first);
        $this->assertStringStartsWith('internal-', $first);
    }

    public function test_a_manager_can_replace_the_member_list(): void
    {
        $this->putJson('/api/projects/internal/members', [
            'employeeIds' => [$this->outsider->id],
        ], $this->auth('manager@test.com'))->assertOk();

        $ids = $this->project->fresh()->members->pluck('id');
        $this->assertTrue($ids->contains($this->outsider->id));
        $this->assertFalse($ids->contains($this->member->id), 'sync replaces rather than appends');
    }

    // --- tickets ---------------------------------------------------------

    public function test_a_member_sees_tickets_in_their_project(): void
    {
        Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Visible',
            'status' => 'todo',
        ]);

        $rows = $this->getJson('/api/tickets', $this->auth('member@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
    }

    public function test_an_outsider_sees_no_tickets(): void
    {
        Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Hidden',
            'status' => 'todo',
        ]);

        $rows = $this->getJson('/api/tickets', $this->auth('outsider@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_a_member_can_create_a_ticket_in_their_module(): void
    {
        $this->postJson('/api/tickets', [
            'moduleId' => $this->module->id,
            'title' => 'New work',
            'priority' => 'high',
        ], $this->auth('member@test.com'))
            ->assertCreated()
            ->assertJsonPath('ticket.title', 'New work')
            // project_id is denormalised from the module, so it must be filled
            // in by the server rather than trusted from the client.
            ->assertJsonPath('ticket.projectId', $this->project->id);
    }

    public function test_an_outsider_cannot_create_a_ticket(): void
    {
        $this->postJson('/api/tickets', [
            'moduleId' => $this->module->id,
            'title' => 'Intrusion',
        ], $this->auth('outsider@test.com'))->assertStatus(403);
    }

    public function test_a_delivered_module_rejects_new_tickets(): void
    {
        $this->module->update(['status' => 'completed']);

        $this->postJson('/api/tickets', [
            'moduleId' => $this->module->id,
            'title' => 'Late addition',
        ], $this->auth('member@test.com'))->assertStatus(403);
    }

    public function test_completing_a_ticket_stamps_completed_at(): void
    {
        $ticket = Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Work',
            'status' => 'todo',
            'assignee_id' => $this->member->id,
        ]);

        $this->patchJson("/api/tickets/{$ticket->id}", [
            'status' => 'done',
        ], $this->auth('member@test.com'))->assertOk();

        $this->assertNotNull($ticket->fresh()->completed_at);

        // ...and reopening clears it, so the two never disagree.
        $this->patchJson("/api/tickets/{$ticket->id}", [
            'status' => 'in_progress',
        ], $this->auth('member@test.com'))->assertOk();

        $this->assertNull($ticket->fresh()->completed_at);
    }

    /**
     * An assignee may work their own ticket, but reassigning it is a
     * supervisory act they do not hold.
     */
    public function test_an_assignee_cannot_reassign_their_own_ticket(): void
    {
        $ticket = Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Work',
            'status' => 'todo',
            'assignee_id' => $this->member->id,
        ]);

        $this->patchJson("/api/tickets/{$ticket->id}", [
            'assigneeId' => $this->outsider->id,
        ], $this->auth('member@test.com'))->assertOk();

        $this->assertSame($this->member->id, $ticket->fresh()->assignee_id);
    }

    /** The counterpart: the guard must not block those who should reassign. */
    public function test_a_manager_can_reassign_a_ticket(): void
    {
        $ticket = Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Work',
            'status' => 'todo',
            'assignee_id' => $this->member->id,
        ]);

        $this->patchJson("/api/tickets/{$ticket->id}", [
            'assigneeId' => $this->outsider->id,
        ], $this->auth('manager@test.com'))->assertOk();

        $this->assertSame($this->outsider->id, $ticket->fresh()->assignee_id);
    }

    public function test_starting_a_timer_stops_any_previous_one(): void
    {
        $first = Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'First', 'status' => 'todo',
        ]);
        $second = Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Second', 'status' => 'todo',
        ]);

        $auth = $this->auth('member@test.com');
        $this->postJson("/api/tickets/{$first->id}/start-timer", [], $auth)->assertOk();
        $this->postJson("/api/tickets/{$second->id}/start-timer", [], $auth)->assertOk();

        // One person cannot be working two tickets at once.
        $this->assertDatabaseCount('ticket_time_logs', 2);
        $running = \DB::table('ticket_time_logs')->whereNull('ended_at')->get();
        $this->assertCount(1, $running);
        $this->assertSame($second->id, (int) $running->first()->ticket_id);

        // The stopped one has its duration written for report aggregation.
        $stopped = \DB::table('ticket_time_logs')->whereNotNull('ended_at')->first();
        $this->assertNotNull($stopped->duration_seconds);
    }

    public function test_commenting_records_the_author(): void
    {
        $ticket = Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Work', 'status' => 'todo',
        ]);

        $this->postJson("/api/tickets/{$ticket->id}/comments", [
            'body' => 'Looks good to me.',
        ], $this->auth('member@test.com'))->assertCreated();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'author_id' => $this->member->id,
            'body' => 'Looks good to me.',
        ]);
    }

    // --- module delivery -------------------------------------------------

    /**
     * Delivery is gated on `approve`. A manager holds it on Projects; an
     * ordinary member does not, even though they can work the module.
     */
    public function test_a_member_cannot_mark_a_module_delivered(): void
    {
        $this->patchJson("/api/modules/{$this->module->id}/delivery", [
            'delivered' => true,
        ], $this->auth('member@test.com'))->assertStatus(403);

        $this->assertSame('active', $this->module->fresh()->status);
    }

    public function test_a_manager_can_mark_a_module_delivered_and_reopen_it(): void
    {
        $auth = $this->auth('manager@test.com');

        $this->patchJson("/api/modules/{$this->module->id}/delivery", [
            'delivered' => true,
        ], $auth)->assertOk()->assertJsonPath('module.status', 'completed');

        $delivered = $this->module->fresh();
        $this->assertSame('completed', $delivered->status);
        $this->assertNotNull($delivered->delivered_at, 'delivery date should be stamped');

        $this->patchJson("/api/modules/{$this->module->id}/delivery", [
            'delivered' => false,
        ], $auth)->assertOk()->assertJsonPath('module.status', 'active');

        $reopened = $this->module->fresh();
        $this->assertSame('active', $reopened->status);
        // A reopened module must not keep reporting a delivery date it no
        // longer has.
        $this->assertNull($reopened->delivered_at);
    }

    /** Holding `approve` does not grant it over an unrelated project. */
    public function test_a_manager_cannot_deliver_a_module_in_another_project(): void
    {
        $other = Project::create(['name' => 'Other', 'slug' => 'other']);
        $otherModule = ProjectModule::create([
            'project_id' => $other->id,
            'name' => 'Elsewhere',
            'slug' => 'elsewhere',
        ]);

        $this->patchJson("/api/modules/{$otherModule->id}/delivery", [
            'delivered' => true,
        ], $this->auth('manager@test.com'))->assertStatus(403);

        $this->assertSame('active', $otherModule->fresh()->status);
    }

    /** Delivery closes the module to new work — the two rules must agree. */
    public function test_delivering_a_module_then_blocks_new_tickets(): void
    {
        $this->patchJson("/api/modules/{$this->module->id}/delivery", [
            'delivered' => true,
        ], $this->auth('manager@test.com'))->assertOk();

        $this->postJson('/api/tickets', [
            'moduleId' => $this->module->id,
            'title' => 'Too late',
        ], $this->auth('member@test.com'))->assertStatus(403);
    }

    public function test_overdue_filtering_excludes_delivered_work(): void
    {
        Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Late', 'status' => 'todo',
            'deadline' => now()->subWeek()->toDateString(),
        ]);
        Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Late but finished', 'status' => 'done',
            'deadline' => now()->subWeek()->toDateString(),
        ]);

        $rows = $this->getJson('/api/tickets?overdue=1', $this->auth('manager@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Late', $rows[0]['title']);
        $this->assertTrue($rows[0]['isOverdue']);
    }
}
