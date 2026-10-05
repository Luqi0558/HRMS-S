<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EffectivenessSnapshot;
use App\Models\EffectivenessSnapshotCycle;
use App\Models\Employee;
use App\Models\User;
use Tests\TenantTestCase;

class EffectivenessTest extends TenantTestCase
{
    private Employee $admin;
    private Employee $employee;
    private Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeEmployee('effectiveness-admin@test.com', 'super_admin');
        $this->employee = $this->makeEmployee('effectiveness-worker@test.com', 'employee');
        $this->hr = $this->makeEmployee('effectiveness-hr@test.com', 'hr');
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
            'employee_code' => 'EFF-'.substr(md5($email), 0, 6),
            'name' => $email,
            'email' => $email,
            'status' => 'active',
        ]);
    }

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    public function test_personal_effectiveness_excludes_approved_leave_from_attendance_evidence(): void
    {
        Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'check_in' => '09:00:00',
            'check_out' => '18:00:00',
            'status' => 'present',
        ]);
        Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => now()->subDay()->toDateString(),
            'status' => 'on_leave',
        ]);

        $response = $this->getJson('/api/effectiveness/me?range=30', $this->auth('effectiveness-worker@test.com'))
            ->assertOk()
            ->assertJsonPath('effectiveness.scope', 'personal')
            ->assertJsonPath('effectiveness.informationalOnly', true);

        $metrics = collect($response->json('effectiveness.metrics'));
        $attendance = $metrics->firstWhere('id', 'attendance');
        $this->assertEquals(100.0, $attendance['score']);
        $this->assertSame(1, $attendance['evidence']['attendanceDays']);
        $this->assertSame(1, $attendance['evidence']['approvedLeaveDaysExcluded']);
    }

    public function test_effectiveness_uses_the_selected_from_and_to_dates(): void
    {
        $from = now()->subDays(45)->startOfDay();
        $to = $from->copy()->addDays(2);
        Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => $from->copy()->addDay()->toDateString(),
            'check_in' => '09:00:00',
            'check_out' => '18:00:00',
            'status' => 'present',
        ]);

        $response = $this->getJson(
            '/api/effectiveness/me?from='.$from->toDateString().'&to='.$to->toDateString(),
            $this->auth('effectiveness-worker@test.com'),
        )->assertOk()
            ->assertJsonPath('effectiveness.period.start', $from->toDateString())
            ->assertJsonPath('effectiveness.period.end', $to->toDateString());

        $attendance = collect($response->json('effectiveness.metrics'))->firstWhere('id', 'attendance');
        $this->assertSame(1, $attendance['evidence']['attendanceDays']);
        $this->assertEquals(100.0, $attendance['score']);
    }

    public function test_employee_cannot_open_workforce_effectiveness(): void
    {
        $this->getJson('/api/effectiveness/workforce?range=30', $this->auth('effectiveness-worker@test.com'))
            ->assertStatus(403);
    }

    public function test_super_admin_can_open_workforce_effectiveness(): void
    {
        $this->getJson('/api/effectiveness/workforce?range=30', $this->auth('effectiveness-admin@test.com'))
            ->assertOk()
            ->assertJsonPath('effectiveness.scope', 'workforce')
            ->assertJsonPath('effectiveness.informationalOnly', true)
            ->assertJsonPath('effectiveness.evidence.activeEmployees', 2);
    }

    public function test_hr_can_review_a_draft_snapshot_cycle(): void
    {
        $cycle = EffectivenessSnapshotCycle::create([
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'status' => 'draft',
            'policy_version' => 'v1.0',
        ]);

        $this->postJson("/api/effectiveness/cycles/{$cycle->id}/review", ['note' => 'Evidence reviewed.'], $this->auth('effectiveness-hr@test.com'))
            ->assertOk()
            ->assertJsonPath('cycle.status', 'reviewed');

        $this->assertSame($this->hr->id, $cycle->fresh()->reviewed_by);
    }

    public function test_super_admin_finalizes_only_an_hr_reviewed_cycle_and_exposes_history(): void
    {
        $start = now()->subMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $cycle = EffectivenessSnapshotCycle::create([
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'status' => 'reviewed', 'policy_version' => 'v1.0',
            'reviewed_by' => $this->hr->id, 'reviewed_at' => now(),
        ]);
        EffectivenessSnapshot::create([
            'employee_id' => $this->employee->id, 'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(), 'role' => 'employee',
            'score' => 82.5, 'confidence' => 'medium', 'metrics' => [], 'evidence' => [],
            'policy_version' => 'v1.0', 'generated_at' => now(),
        ]);

        $this->postJson("/api/effectiveness/cycles/{$cycle->id}/finalize", [], $this->auth('effectiveness-admin@test.com'))
            ->assertOk()
            ->assertJsonPath('cycle.status', 'finalized');

        $this->assertNotNull(EffectivenessSnapshot::firstOrFail()->fresh()->finalized_at);

        $this->getJson('/api/effectiveness/history?scope=workforce&months=6', $this->auth('effectiveness-admin@test.com'))
            ->assertOk()
            ->assertJsonPath('history.0.score', 82.5);
    }
}
