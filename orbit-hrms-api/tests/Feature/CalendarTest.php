<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

class CalendarTest extends TenantTestCase
{
    private Employee $hr;
    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->hr = $this->makeEmployee('hr-calendar@test.com', 'hr');
        $this->worker = $this->makeEmployee('worker-calendar@test.com', 'employee');
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
            'employee_code' => 'CAL-'.substr(md5($email), 0, 6),
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

    public function test_calendar_returns_only_approved_leave_in_the_requested_range(): void
    {
        $from = now()->addDays(2)->startOfDay();
        $to = now()->addDays(8)->startOfDay();
        $this->worker->update(['birthday' => $from->copy()->addDay()->toDateString()]);

        $approved = LeaveRequest::create([
            'employee_id' => $this->worker->id,
            'type' => 'Annual leave',
            'from_date' => $from->copy()->addDays(2)->toDateString(),
            'to_date' => $from->copy()->addDays(4)->toDateString(),
            'days' => 3,
            'status' => 'approved',
        ]);
        $pending = LeaveRequest::create([
            'employee_id' => $this->worker->id,
            'type' => 'Sick leave',
            'from_date' => $from->copy()->addDay()->toDateString(),
            'to_date' => $from->copy()->addDay()->toDateString(),
            'days' => 1,
            'status' => 'pending',
        ]);

        $this->getJson('/api/calendar-events?from='.$from->toDateString().'&to='.$to->toDateString(), $this->auth('hr-calendar@test.com'))
            ->assertOk()
            ->assertJsonFragment([
                'id' => "leave-{$approved->id}",
                'startDate' => $approved->from_date->toDateString(),
                'endDate' => $approved->to_date->toDateString(),
            ])
            ->assertJsonMissing(['id' => "leave-{$pending->id}"]);
    }

    public function test_employee_cannot_open_the_company_calendar_feed(): void
    {
        $this->getJson('/api/calendar-events?from=2026-09-01&to=2026-09-30', $this->auth('worker-calendar@test.com'))
            ->assertStatus(403);
    }

    public function test_ticket_deadlines_are_not_a_calendar_event_type(): void
    {
        $this->getJson('/api/calendar-events?from=2026-09-01&to=2026-09-30&types[]=ticket', $this->auth('hr-calendar@test.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('types.0');
    }
}
