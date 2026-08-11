<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\User;
use App\Services\CompanyHubService;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

class CompanyHubTest extends TenantTestCase
{
    private Employee $hr;
    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
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

    private function aPolicy(array $overrides = []): Policy
    {
        return Policy::create(array_merge([
            'title' => 'Remote work policy',
            'category' => 'HR',
            'version' => '1.0',
            'summary' => 'Guidelines for remote attendance.',
            'acknowledgment_required' => true,
        ], $overrides));
    }

    // --- announcements ---------------------------------------------------

    public function test_hr_can_publish_an_announcement(): void
    {
        $this->postJson('/api/company-hub/announcements', [
            'title' => 'Friday town hall',
            'body' => 'A short company update at 4pm.',
            'priority' => 'high',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('announcement.priority', 'high');
    }

    public function test_an_employee_may_not_publish(): void
    {
        $this->postJson('/api/company-hub/announcements', [
            'title' => 'Unofficial notice',
            'body' => 'Nope.',
        ], $this->auth('worker@test.com'))->assertStatus(403);
    }

    /** An announcement nobody is told about is a page nobody visits. */
    public function test_publishing_notifies_the_company(): void
    {
        app(CompanyHubService::class)->publish(
            User::where('email', 'hr@test.com')->firstOrFail(),
            ['title' => 'Office closed Monday', 'body' => 'Public holiday.'],
        );

        $this->assertDatabaseHas('notifications', [
            'employee_id' => $this->worker->id,
            'type' => 'announcement',
        ]);
    }

    public function test_an_employee_can_read_the_hub(): void
    {
        $this->aPolicy();

        $this->getJson('/api/company-hub', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('policies.0.title', 'Remote work policy');
    }

    // --- acknowledgements ------------------------------------------------

    public function test_an_employee_can_acknowledge_a_policy(): void
    {
        $policy = $this->aPolicy();

        $this->postJson("/api/company-hub/policies/{$policy->id}/acknowledge", [],
            $this->auth('worker@test.com'))->assertOk();

        $this->assertDatabaseHas('policy_acknowledgements', [
            'policy_id' => $policy->id,
            'employee_id' => $this->worker->id,
            'version' => '1.0',
        ]);
    }

    /** Re-reading a policy must not create a second row. */
    public function test_acknowledging_twice_is_refused(): void
    {
        $policy = $this->aPolicy();
        $headers = $this->auth('worker@test.com');

        $this->postJson("/api/company-hub/policies/{$policy->id}/acknowledge", [], $headers)->assertOk();
        $this->postJson("/api/company-hub/policies/{$policy->id}/acknowledge", [], $headers)->assertStatus(409);

        $this->assertSame(1, PolicyAcknowledgement::count());
    }

    /**
     * The rule the whole design turns on: agreeing to v1.0 is not agreeing to
     * v2.0, so reissuing a policy makes every prior acknowledgement stale.
     */
    public function test_a_new_version_needs_acknowledging_again(): void
    {
        $policy = $this->aPolicy();
        $service = app(CompanyHubService::class);
        $worker = User::where('email', 'worker@test.com')->firstOrFail();

        $service->acknowledge($worker, $policy->id);

        // Acknowledged, as it stands.
        $before = collect($service->policies($worker))->firstWhere('id', $policy->id);
        $this->assertTrue($before->getAttribute('acknowledged_by_me'));

        // HR reissues it.
        $service->savePolicy(
            User::where('email', 'hr@test.com')->firstOrFail(),
            ['title' => $policy->title, 'summary' => $policy->summary, 'version' => '2.0'],
            $policy->id,
        );

        // The old acknowledgement no longer counts.
        $after = collect($service->policies($worker))->firstWhere('id', $policy->id);
        $this->assertFalse($after->getAttribute('acknowledged_by_me'));

        // And they can acknowledge the new one.
        $service->acknowledge($worker, $policy->id);
        $this->assertSame(2, PolicyAcknowledgement::count());
    }

    /** The compliance question: who still has to read this? */
    public function test_hr_can_see_who_has_not_acknowledged(): void
    {
        $policy = $this->aPolicy();
        app(CompanyHubService::class)->acknowledge(
            User::where('email', 'worker@test.com')->firstOrFail(),
            $policy->id,
        );

        $status = $this->getJson("/api/company-hub/policies/{$policy->id}/acknowledgements",
            $this->auth('hr@test.com'))->assertOk()->json();

        $this->assertSame('1.0', $status['version']);
        $this->assertSame(['worker@test.com'], array_column($status['acknowledged'], 'name'));
        // HR has not acknowledged their own policy.
        $this->assertContains('hr@test.com', array_column($status['outstanding'], 'name'));
    }

    public function test_an_employee_may_not_see_the_compliance_list(): void
    {
        $policy = $this->aPolicy();

        $this->getJson("/api/company-hub/policies/{$policy->id}/acknowledgements",
            $this->auth('worker@test.com'))->assertStatus(403);
    }

    /** Hub changes belong in the audit trail. */
    public function test_hub_changes_are_recorded(): void
    {
        app(CompanyHubService::class)->publish(
            User::where('email', 'hr@test.com')->firstOrFail(),
            ['title' => 'Notice', 'body' => 'Body.'],
        );

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'announcements',
            'action' => 'announcement published',
        ]);
    }
}
