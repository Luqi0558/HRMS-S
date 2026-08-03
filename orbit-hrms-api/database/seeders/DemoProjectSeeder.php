<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use Illuminate\Database\Seeder;

/**
 * The project structure from src/data/mock.js, so scope: 'owned' and 'team'
 * have real membership to resolve through.
 *
 * Employees are looked up by email rather than id: DemoUserSeeder assigns ids
 * in insertion order, and hardcoding them here would break the moment that
 * list is reordered.
 */
class DemoProjectSeeder extends Seeder
{
    public function run(): void
    {
        $byEmail = Employee::pluck('id', 'email');

        $id = fn (string $email) => $byEmail[$email] ?? null;

        $internal = Project::updateOrCreate(
            ['slug' => 'internal'],
            [
                'name' => 'Internal',
                'description' => 'Company-owned internal products and operational tools.',
                'manager_id' => $id('zara.nadeem@company.com'),
                'status' => 'active',
            ]
        );

        $freelance = Project::updateOrCreate(
            ['slug' => 'freelance'],
            [
                'name' => 'Freelance',
                'description' => 'Client projects received through freelance work.',
                'manager_id' => $id('zara.nadeem@company.com'),
                'status' => 'active',
            ]
        );

        $internal->members()->sync(array_filter([
            $id('ayesha.khan@company.com'),
            $id('bilal.raza@company.com'),
            $id('usman.tariq@company.com'),
            $id('imran.shah@company.com'),
        ]));

        $freelance->members()->sync(array_filter([
            $id('bilal.raza@company.com'),
            $id('usman.tariq@company.com'),
        ]));

        $hrms = $this->module($internal, 'orbit-hrms', 'Orbit HRMS', [
            'description' => 'Internal HR management system.',
            'budget' => 1800000,
            'commission_type' => 'percent',
            'commission_value' => 8,
            'commission_status' => 'approved',
        ], [$id('ayesha.khan@company.com'), $id('bilal.raza@company.com')]);

        $attendance = $this->module($internal, 'attendance-system', 'Attendance System', [
            'description' => 'Check-in, WebAuthn, camera capture, and punch ledger work.',
            'lead_id' => $id('imran.shah@company.com'),
        ], [$id('ayesha.khan@company.com'), $id('imran.shah@company.com')]);

        $workspace = $this->module($internal, 'project-management-workspace', 'Project Management Workspace', [
            'description' => 'Project boards, ticketing, timers, and delivery workflow.',
            'lead_id' => $id('imran.shah@company.com'),
        ], [$id('usman.tariq@company.com'), $id('imran.shah@company.com')]);

        $this->ticket($hrms, 'Invitation email template', 'in_progress', 'medium', $id('bilal.raza@company.com'), '2026-07-18');
        $this->ticket($attendance, 'WebAuthn checkin flow', 'review', 'high', $id('ayesha.khan@company.com'), '2026-07-16');
        $this->ticket($attendance, 'Camera permission fallback', 'todo', 'medium', $id('ayesha.khan@company.com'), '2026-07-20');
        $this->ticket($workspace, 'Kanban drag and drop', 'todo', 'medium', $id('usman.tariq@company.com'), '2026-07-25');
        $this->ticket($workspace, 'Timer accuracy audit', 'done', 'low', $id('usman.tariq@company.com'), '2026-07-10');
    }

    private function module(Project $project, string $slug, string $name, array $attributes, array $memberIds): ProjectModule
    {
        $module = ProjectModule::updateOrCreate(
            ['project_id' => $project->id, 'slug' => $slug],
            array_merge(['name' => $name, 'status' => 'active'], $attributes)
        );

        $module->members()->sync(array_filter($memberIds));

        return $module;
    }

    private function ticket(ProjectModule $module, string $title, string $status, string $priority, ?int $assigneeId, string $deadline): void
    {
        Ticket::updateOrCreate(
            ['project_module_id' => $module->id, 'title' => $title],
            [
                // Denormalised from the module — see the note on the tickets
                // migration.
                'project_id' => $module->project_id,
                'status' => $status,
                'priority' => $priority,
                'assignee_id' => $assigneeId,
                'deadline' => $deadline,
                'completed_at' => $status === 'done' ? now() : null,
            ]
        );
    }
}
