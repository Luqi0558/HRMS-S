<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TicketReferenceGenerator;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

/**
 * References read LPS-Internal-AS-1: company initials, project, module
 * initials, and a number within that module.
 */
class TicketReferenceTest extends TenantTestCase
{
    private ProjectModule $module;
    private Employee $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        app(SettingsService::class)->putGroup('company', ['name' => 'Logic Powered Solutions']);

        $this->lead = $this->makeEmployee('lead@test.com', 'manager');

        $project = Project::create([
            'name' => 'Internal',
            'slug' => 'internal',
            'manager_id' => $this->lead->id,
        ]);

        $this->module = ProjectModule::create([
            'project_id' => $project->id,
            'name' => 'Attendance System',
            'slug' => 'attendance-system',
        ]);
        $this->module->members()->sync([$this->lead->id]);
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

    public function test_a_new_ticket_is_referenced_from_company_project_and_module(): void
    {
        $this->postJson('/api/tickets', [
            'moduleId' => $this->module->id,
            'title' => 'Camera permission fallback',
        ], $this->auth('lead@test.com'))
            ->assertCreated()
            ->assertJsonPath('ticket.reference', 'LPS-Internal-AS-1');
    }

    public function test_the_number_counts_within_the_module(): void
    {
        $headers = $this->auth('lead@test.com');

        foreach (['First', 'Second', 'Third'] as $title) {
            $this->postJson('/api/tickets', [
                'moduleId' => $this->module->id, 'title' => $title,
            ], $headers)->assertCreated();
        }

        $this->assertSame(
            ['LPS-Internal-AS-1', 'LPS-Internal-AS-2', 'LPS-Internal-AS-3'],
            Ticket::orderBy('id')->pluck('reference')->all()
        );
    }

    /**
     * Two modules each start at 1 — the counter is per module, which is what
     * keeps the numbers short enough to say out loud.
     */
    public function test_each_module_numbers_independently(): void
    {
        $other = ProjectModule::create([
            'project_id' => $this->module->project_id,
            'name' => 'Project Management Workspace',
            'slug' => 'pmw',
        ]);
        $other->members()->sync([$this->lead->id]);

        $headers = $this->auth('lead@test.com');

        $this->postJson('/api/tickets', ['moduleId' => $this->module->id, 'title' => 'A'], $headers)
            ->assertJsonPath('ticket.reference', 'LPS-Internal-AS-1');
        $this->postJson('/api/tickets', ['moduleId' => $other->id, 'title' => 'B'], $headers)
            ->assertJsonPath('ticket.reference', 'LPS-Internal-PMW-1');
    }

    /**
     * A deleted ticket must not hand its number to the next one, or two
     * different tickets end up sharing a reference in whatever was written
     * down.
     */
    public function test_a_deleted_ticket_does_not_release_its_number(): void
    {
        $headers = $this->auth('lead@test.com');

        $first = $this->postJson('/api/tickets', ['moduleId' => $this->module->id, 'title' => 'A'], $headers)
            ->json('ticket.id');
        $this->postJson('/api/tickets', ['moduleId' => $this->module->id, 'title' => 'B'], $headers);

        Ticket::find($first)->delete();

        $this->postJson('/api/tickets', ['moduleId' => $this->module->id, 'title' => 'C'], $headers)
            ->assertJsonPath('ticket.reference', 'LPS-Internal-AS-3');
    }

    /**
     * References are quoted in Teams messages and conversations, so a rename
     * must not rewrite what is already recorded.
     */
    public function test_renaming_a_module_leaves_existing_references_alone(): void
    {
        $headers = $this->auth('lead@test.com');

        $id = $this->postJson('/api/tickets', ['moduleId' => $this->module->id, 'title' => 'A'], $headers)
            ->json('ticket.id');

        $this->module->update(['name' => 'Time Tracking']);

        $this->patchJson("/api/tickets/{$id}", ['title' => 'A renamed'], $headers)->assertOk();

        $this->assertSame('LPS-Internal-AS-1', Ticket::find($id)->reference);
    }

    public function test_the_client_cannot_choose_its_own_reference(): void
    {
        $this->postJson('/api/tickets', [
            'moduleId' => $this->module->id,
            'title' => 'Spoofed',
            'reference' => 'TOTALLY-MADE-UP',
        ], $this->auth('lead@test.com'))
            ->assertCreated()
            ->assertJsonPath('ticket.reference', 'LPS-Internal-AS-1');
    }

    public function test_initials_cope_with_punctuation_and_single_words(): void
    {
        $generator = app(TicketReferenceGenerator::class);

        $module = ProjectModule::create([
            'project_id' => $this->module->project_id,
            'name' => 'Front-end / QA',
            'slug' => 'front-end-qa',
        ]);

        // Split on punctuation as well as spaces: F, Q.
        $this->assertSame('LPS-Internal-FQ-1', $generator->for($module));
    }
}
