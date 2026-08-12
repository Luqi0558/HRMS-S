<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

/**
 * QA owns the Review column.
 *
 * The capability that matters is acting on somebody else's ticket: QA is never
 * the assignee, so if the permission grid did not mark them as a reviewer they
 * could see the queue and do nothing about it.
 */
class QaReviewTest extends TenantTestCase
{
    private Employee $developer;
    private Employee $qa;
    private Employee $intern;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->developer = $this->makeEmployee('dev@test.com', 'employee');
        $this->qa = $this->makeEmployee('qa@test.com', 'qa');
        $this->intern = $this->makeEmployee('intern@test.com', 'intern');

        $project = Project::create(['name' => 'Internal', 'slug' => 'internal']);
        $project->members()->sync([$this->developer->id, $this->qa->id, $this->intern->id]);

        $module = ProjectModule::create([
            'project_id' => $project->id,
            'name' => 'Attendance System',
            'slug' => 'attendance-system',
        ]);
        $module->members()->sync([$this->developer->id, $this->qa->id, $this->intern->id]);

        $this->ticket = Ticket::create([
            'project_id' => $project->id,
            'project_module_id' => $module->id,
            'title' => 'Camera permission fallback',
            'status' => 'review',
            'assignee_id' => $this->developer->id,
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

    public function test_qa_can_pass_a_ticket_they_do_not_own(): void
    {
        $this->patchJson("/api/tickets/{$this->ticket->id}", ['status' => 'done'], $this->auth('qa@test.com'))
            ->assertOk()
            ->assertJsonPath('ticket.status', 'done');

        // Passing does not reassign — the work stays credited to whoever did it.
        $this->assertSame($this->developer->id, $this->ticket->fresh()->assignee_id);
    }

    public function test_qa_can_send_a_ticket_back(): void
    {
        $this->patchJson("/api/tickets/{$this->ticket->id}", ['status' => 'in_progress'], $this->auth('qa@test.com'))
            ->assertOk()
            ->assertJsonPath('ticket.status', 'in_progress');

        $this->assertSame($this->developer->id, $this->ticket->fresh()->assignee_id);
    }

    public function test_qa_sees_the_review_queue_for_their_modules(): void
    {
        $rows = $this->getJson('/api/tickets?status=review', $this->auth('qa@test.com'))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->ticket->id, $rows[0]['id']);
    }

    /**
     * The gate, now that it is real: an ordinary teammate cannot sign work off.
     *
     * Every role holds create on Tickets — that is how an employee raises their
     * own — so before this, anyone on the board could mark anything done and QA
     * was decorative.
     */
    public function test_an_ordinary_employee_cannot_sign_off_someone_elses_ticket(): void
    {
        $other = $this->makeEmployee('other@test.com', 'employee');
        $this->ticket->project->members()->attach($other->id);
        $this->ticket->module->members()->attach($other->id);

        $this->patchJson("/api/tickets/{$this->ticket->id}", ['status' => 'done'], $this->auth('other@test.com'))
            ->assertForbidden();

        $this->assertSame('review', $this->ticket->fresh()->status);
    }

    /**
     * The assignee's half of the handover still works: they move their own work
     * to review and stop there.
     */
    public function test_an_assignee_can_still_hand_their_own_work_to_review(): void
    {
        $this->ticket->update(['status' => 'in_progress']);

        $this->patchJson("/api/tickets/{$this->ticket->id}", ['status' => 'review'], $this->auth('dev@test.com'))
            ->assertOk()
            ->assertJsonPath('ticket.status', 'review');
    }

    /**
     * Managers qualify on scope rather than on 'approve', which they do not
     * hold — requiring the flag alone would have locked them out.
     */
    public function test_a_manager_can_sign_work_off(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->ticket->project->update(['manager_id' => $manager->id]);

        $this->patchJson("/api/tickets/{$this->ticket->id}", ['status' => 'done'], $this->auth('manager@test.com'))
            ->assertOk()
            ->assertJsonPath('ticket.status', 'done');
    }

    public function test_an_intern_holds_no_medical_claim_access(): void
    {
        // The one thing that separates the intern grid from the employee one.
        $this->getJson('/api/medical-claims', $this->auth('intern@test.com'))
            ->assertForbidden();

        // While the rest of their day still works.
        $this->getJson('/api/tickets', $this->auth('intern@test.com'))->assertOk();
        $this->getJson('/api/leave-requests', $this->auth('intern@test.com'))->assertOk();
    }
}
