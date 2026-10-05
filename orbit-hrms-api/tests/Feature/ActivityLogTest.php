<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogService;
use Database\Seeders\RolePermissionSeeder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TenantTestCase;

class ActivityLogTest extends TenantTestCase
{
    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->worker = $this->makeEmployee('worker@test.com', 'employee');
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

    // --- writing ---------------------------------------------------------

    /**
     * Audit entries only come from trusted server-side domain operations.
     *
     * 405 rather than 404: the write route is gone, but the same path still
     * answers GET for reading the log, so the method is what is refused.
     */
    public function test_a_signed_in_user_cannot_create_an_audit_entry_over_http(): void
    {
        $this->postJson('/api/activity-logs', [
            'module' => 'leave',
            'action' => 'leave requested',
            'details' => 'Annual leave for two days.',
        ], $this->auth('worker@test.com'))->assertStatus(405);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * An offboarded employee's entries stay intact.
     *
     * Employee soft-deletes, so the row survives and the foreign key still
     * resolves — which is the better outcome for an audit trail than nulling
     * the link.
     */
    public function test_entries_survive_their_actor_being_offboarded(): void
    {
        app(ActivityLogService::class)->record(
            User::where('email', 'worker@test.com')->firstOrFail(),
            ['module' => 'assets', 'action' => 'asset assigned'],
        );

        $this->worker->delete();

        $log = ActivityLog::firstOrFail();
        $this->assertSame($this->worker->id, $log->actor_id);
        $this->assertSame('worker@test.com', $log->actor_name);
        $this->assertSame('asset assigned', $log->action);
    }

    /**
     * And they stay READABLE even if the employee is purged outright.
     *
     * This is why the name and role are copied in rather than only referenced:
     * a hard delete nulls the foreign key, and "someone released a payslip" is
     * not an audit trail.
     */
    public function test_an_entry_still_names_its_actor_after_a_hard_delete(): void
    {
        app(ActivityLogService::class)->record(
            User::where('email', 'worker@test.com')->firstOrFail(),
            ['module' => 'payroll', 'action' => 'payroll released'],
        );

        $this->worker->forceDelete();

        $log = ActivityLog::firstOrFail();
        // The link is gone...
        $this->assertNull($log->actor_id);
        // ...but the entry still says who did it.
        $this->assertSame('worker@test.com', $log->actor_name);
        $this->assertSame('employee', $log->actor_role);
    }

    // --- reading ---------------------------------------------------------

    /** Reading the trail is administrative — it records what everyone did. */
    public function test_an_employee_cannot_read_the_trail(): void
    {
        $this->getJson('/api/activity-logs', $this->auth('worker@test.com'))
            ->assertStatus(403);
    }

    public function test_a_super_admin_can_read_the_trail(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');

        app(ActivityLogService::class)->record(
            User::where('email', 'worker@test.com')->firstOrFail(),
            ['module' => 'leave', 'action' => 'leave requested'],
        );

        $this->getJson('/api/activity-logs', $this->auth('boss@test.com'))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'leave requested')
            ->assertJsonPath('data.0.actorName', 'worker@test.com');
    }

    /** Newest first: an audit trail is read from the top. */
    public function test_the_trail_is_newest_first(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $service = app(ActivityLogService::class);
        $user = User::where('email', 'worker@test.com')->firstOrFail();

        $service->record($user, ['module' => 'leave', 'action' => 'first']);
        $service->record($user, ['module' => 'leave', 'action' => 'second']);

        $this->getJson('/api/activity-logs', $this->auth('boss@test.com'))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'second');
    }

    public function test_the_trail_can_be_filtered_by_module(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $service = app(ActivityLogService::class);
        $user = User::where('email', 'worker@test.com')->firstOrFail();

        $service->record($user, ['module' => 'leave', 'action' => 'leave requested']);
        $service->record($user, ['module' => 'payroll', 'action' => 'payroll released']);

        $response = $this->getJson('/api/activity-logs?module=payroll', $this->auth('boss@test.com'))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('payroll released', $response->json('data.0.action'));
        // The filter list offers only modules that actually appear.
        $this->assertEqualsCanonicalizing(['leave', 'payroll'], $response->json('modules'));
    }

    /**
     * This table grows with every action anyone takes, so it is the one
     * collection guaranteed to outgrow a single response.
     */
    public function test_the_trail_is_paginated(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $service = app(ActivityLogService::class);
        $user = User::where('email', 'worker@test.com')->firstOrFail();

        for ($i = 0; $i < 60; $i++) {
            $service->record($user, ['module' => 'leave', 'action' => "action {$i}"]);
        }

        $response = $this->getJson('/api/activity-logs?per_page=25', $this->auth('boss@test.com'))
            ->assertOk();

        $this->assertCount(25, $response->json('data'));
        $this->assertSame(60, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.lastPage'));
    }
}
