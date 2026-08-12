<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\MedicalClaim;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\MedicalClaimService;
use App\Services\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TenantTestCase;

class AttendanceLeaveClaimTest extends TenantTestCase
{
    private Employee $employee;
    private Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->employee = $this->makeEmployee('worker@test.com', 'employee');
        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
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

    /**
     * A team lead whose 'owned' scope actually reaches $member.
     *
     * ScopeResolver derives a lead's reports from project and module
     * leadership, not from a reporting column — so a bare team_lead user has
     * an empty squad and could approve nothing. This builds the project and
     * module that make the relationship real.
     */
    private function makeLeadOver(Employee $member): Employee
    {
        $lead = $this->makeEmployee('lead@test.com', 'team_lead');

        $project = Project::create([
            'name' => 'Delivery', 'slug' => 'delivery',
            'manager_id' => $this->hr->id, 'status' => 'active',
        ]);

        $module = ProjectModule::create([
            'project_id' => $project->id, 'name' => 'API', 'slug' => 'api',
            'lead_id' => $lead->id, 'status' => 'active',
        ]);

        DB::table('project_module_members')->insert([
            'project_module_id' => $module->id,
            'employee_id' => $member->id,
        ]);

        return $lead;
    }

    private function pendingLeave(Employee $employee): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'type' => 'Annual leave',
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'status' => 'pending',
        ]);
    }

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    // --- attendance ------------------------------------------------------

    public function test_checking_in_records_the_time_and_status(): void
    {
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('attendance.employeeId', $this->employee->id);

        $this->assertDatabaseHas('attendance', [
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
        ]);
    }

    /**
     * A double-tap must not overwrite the real arrival time, which is what an
     * updateOrCreate without this guard would do.
     */
    public function test_checking_in_twice_is_refused(): void
    {
        $auth = $this->auth('worker@test.com');

        $this->postJson('/api/attendance/check-in', [], $auth)->assertCreated();
        $this->postJson('/api/attendance/check-in', [], $auth)->assertStatus(409);

        $this->assertDatabaseCount('attendance', 1);
    }

    public function test_checking_out_without_checking_in_is_refused(): void
    {
        $this->postJson('/api/attendance/check-out', [], $this->auth('worker@test.com'))
            ->assertStatus(409);
    }

    public function test_late_arrival_is_derived_from_the_grace_period(): void
    {
        // Start 09:30 + 10 minutes grace: 09:45 is late.
        Carbon::setTestNow(Carbon::parse(now()->toDateString().' 09:45:00'));

        $this->postJson('/api/attendance/check-in', [], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('attendance.status', 'late');

        Carbon::setTestNow();
    }

    public function test_arrival_within_grace_is_present(): void
    {
        // Pinned so the frozen clock below is also the office clock. Punches are
        // stamped in the company's timezone, which defaults to Asia/Karachi —
        // without this, 09:38 UTC arrives as 14:38 locally and is late, which
        // would fail this test for a reason that has nothing to do with grace.
        app(SettingsService::class)->putGroup('company', ['timezone' => 'UTC']);

        Carbon::setTestNow(Carbon::parse(now()->toDateString().' 09:38:00'));

        $this->postJson('/api/attendance/check-in', [], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('attendance.status', 'present');

        Carbon::setTestNow();
    }

    /**
     * The stored status must not move when policy changes — otherwise last
     * month's records silently rewrite themselves.
     */
    public function test_changing_the_grace_period_does_not_rewrite_history(): void
    {
        Carbon::setTestNow(Carbon::parse(now()->toDateString().' 09:45:00'));
        $this->postJson('/api/attendance/check-in', [], $this->auth('worker@test.com'))
            ->assertJsonPath('attendance.status', 'late');
        Carbon::setTestNow();

        app(SettingsService::class)->put('attendance', 'graceMinutes', 60);

        $this->assertDatabaseHas('attendance', [
            'employee_id' => $this->employee->id,
            'status' => 'late',
        ]);
    }

    /** An employee holds no Attendance row but must see their own history. */
    public function test_an_employee_sees_only_their_own_attendance(): void
    {
        $this->postJson('/api/attendance/check-in', [], $this->auth('worker@test.com'))->assertCreated();

        \App\Models\Attendance::create([
            'employee_id' => $this->hr->id,
            'date' => now()->toDateString(),
            'check_in' => '09:00',
            'status' => 'present',
        ]);

        $rows = $this->getJson('/api/attendance', $this->auth('worker@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->employee->id, $rows[0]['employeeId']);
    }

    // --- leave -----------------------------------------------------------

    public function test_an_employee_can_request_leave(): void
    {
        $this->postJson('/api/leave-requests', [
            'type' => 'Annual leave',
            'fromDate' => now()->addWeek()->toDateString(),
            'toDate' => now()->addWeek()->addDays(2)->toDateString(),
            'days' => 3,
            'reason' => 'Family trip',
        ], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.days', 3);
    }

    /** Two requests for the same dates would double-count against the balance. */
    public function test_overlapping_leave_is_refused(): void
    {
        $auth = $this->auth('worker@test.com');
        $from = now()->addWeek()->toDateString();
        $to = now()->addWeek()->addDays(2)->toDateString();

        $this->postJson('/api/leave-requests', [
            'type' => 'Annual leave', 'fromDate' => $from, 'toDate' => $to, 'days' => 3,
        ], $auth)->assertCreated();

        $this->postJson('/api/leave-requests', [
            'type' => 'Sick leave', 'fromDate' => $from, 'toDate' => $to, 'days' => 3,
        ], $auth)->assertStatus(409);
    }

    public function test_leave_beyond_the_annual_allowance_is_refused(): void
    {
        // Annual allowance defaults to 18 days.
        $this->postJson('/api/leave-requests', [
            'type' => 'Annual leave',
            'fromDate' => now()->addWeek()->toDateString(),
            'toDate' => now()->addWeek()->addDays(24)->toDateString(),
            'days' => 25,
        ], $this->auth('worker@test.com'))
            ->assertStatus(409);
    }

    /** Unpaid leave has no ceiling by definition. */
    public function test_unpaid_leave_has_no_allowance_limit(): void
    {
        $this->postJson('/api/leave-requests', [
            'type' => 'Unpaid leave',
            'fromDate' => now()->addWeek()->toDateString(),
            'toDate' => now()->addWeek()->addDays(40)->toDateString(),
            'days' => 41,
        ], $this->auth('worker@test.com'))->assertCreated();
    }

    /**
     * With twoStepApproval on, HR's approval alone leaves the request pending
     * until a lead has also signed off.
     *
     * The lead must exist for the rule to bite: an employee nobody leads has
     * no lead stage to wait on, so HR settles theirs alone.
     */
    public function test_two_step_approval_keeps_a_request_pending_after_one_stage(): void
    {
        $this->makeLeadOver($this->employee);

        $request = LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'type' => 'Annual leave',
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'status' => 'pending',
        ]);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))->assertOk();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $request->id,
            'stage' => 'hr',
            'decision' => 'approved',
        ]);
    }

    /** A rejection at any stage is final, regardless of two-step. */
    public function test_a_rejection_decides_the_request_immediately(): void
    {
        $request = LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'type' => 'Annual leave',
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'status' => 'pending',
        ]);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'rejected',
            'comment' => 'Peak delivery week.',
        ], $this->auth('hr@test.com'))->assertOk();

        $this->assertSame('rejected', $request->fresh()->status);
    }

    /**
     * A lead approves at the 'lead' stage via 'Team Leaves'.approve — they do
     * not hold 'Leave Request'.approve, which is HR's stage.
     */
    public function test_a_lead_approves_at_the_lead_stage(): void
    {
        $lead = $this->makeLeadOver($this->employee);
        $request = $this->pendingLeave($this->employee);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth($lead->email))->assertOk();

        // Still pending: HR has not signed off yet.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $request->id,
            'stage' => 'lead',
            'decision' => 'approved',
        ]);
    }

    /**
     * Both stages approving is what finally settles the request.
     *
     * Driven through the service rather than two HTTP calls: within one test
     * process the auth guard caches the first resolved user, so a second
     * request with HR's token would still act as the lead and re-record the
     * lead stage. The HTTP layer is already covered by the single-stage tests
     * above; what needs proving here is the two-stage resolution itself.
     */
    public function test_both_stages_approving_completes_the_request(): void
    {
        $lead = $this->makeLeadOver($this->employee);
        $request = $this->pendingLeave($this->employee);
        $service = app(LeaveService::class);

        $service->decide(User::where('email', $lead->email)->firstOrFail(), $request->id, 'approved');
        $this->assertSame('pending', $request->fresh()->status);

        $service->decide(User::where('email', 'hr@test.com')->firstOrFail(), $request->id, 'approved');

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(2, LeaveApproval::where('leave_request_id', $request->id)->count());
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $request->id, 'stage' => 'lead', 'decision' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $request->id, 'stage' => 'hr', 'decision' => 'approved',
        ]);
    }

    /**
     * 'owned' scope means the lead's own reports. Someone outside the module
     * they lead is not theirs to approve.
     */
    public function test_a_lead_cannot_approve_outside_their_squad(): void
    {
        $lead = $this->makeLeadOver($this->employee);
        $outsider = $this->makeEmployee('outsider@test.com', 'employee');
        $request = $this->pendingLeave($outsider);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth($lead->email))->assertStatus(403);

        $this->assertSame('pending', $request->fresh()->status);
    }

    /**
     * HR holds approve on every leave request, including their own. Without an
     * explicit check they could sign off their own absence outright.
     */
    public function test_nobody_can_approve_their_own_leave(): void
    {
        $request = $this->pendingLeave($this->hr);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))->assertStatus(403);

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('leave_approvals', 0);
    }

    /**
     * HR has no lead above them, so the lead stage can never be recorded for
     * their leave. Super admin's escalation stage is what settles it — before
     * this the request sat at pending permanently.
     */
    public function test_super_admin_escalation_settles_leave_with_no_lead(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $request = $this->pendingLeave($this->hr);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('boss@test.com'))->assertOk();

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $request->id,
            'stage' => 'escalation',
            'decision' => 'approved',
        ]);
    }

    /**
     * Super admin and HR must not share a stage: the unique index is on
     * (request, stage), so both writing 'hr' meant the second silently
     * overwrote the first's decision.
     */
    public function test_super_admin_and_hr_record_separate_stages(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $lead = $this->makeLeadOver($this->employee);
        $request = $this->pendingLeave($this->employee);

        $service = app(LeaveService::class);
        $service->decide(User::where('email', 'hr@test.com')->firstOrFail(), $request->id, 'approved');
        $service->decide(User::where('email', 'boss@test.com')->firstOrFail(), $request->id, 'approved');

        $stages = LeaveApproval::where('leave_request_id', $request->id)
            ->pluck('stage')->sort()->values()->all();

        $this->assertSame(['escalation', 'hr'], $stages);
        $this->assertNotNull($lead);
    }

    /**
     * An employee nobody leads still needs their leave settled. Requiring a
     * lead stage that cannot be recorded would strand it at pending.
     */
    public function test_hr_alone_settles_leave_for_an_employee_with_no_lead(): void
    {
        $request = $this->pendingLeave($this->employee);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))->assertOk();

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_an_employee_cannot_approve_leave(): void
    {
        $request = LeaveRequest::create([
            'employee_id' => $this->hr->id,
            'type' => 'Annual leave',
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'status' => 'pending',
        ]);

        $this->postJson("/api/leave-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('worker@test.com'))->assertStatus(403);

        $this->assertSame('pending', $request->fresh()->status);
    }

    // --- medical claims --------------------------------------------------

    public function test_an_employee_can_file_a_claim(): void
    {
        $this->postJson('/api/medical-claims', [
            'type' => 'Pharmacy claim',
            'amount' => 4200,
        ], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('claim.status', 'pending');
    }

    /** Receipts above the threshold are policy, enforced server-side. */
    public function test_a_large_claim_without_a_receipt_is_refused(): void
    {
        $this->postJson('/api/medical-claims', [
            'type' => 'Hospital bill',
            // Threshold defaults to 5000.
            'amount' => 9000,
        ], $this->auth('worker@test.com'))->assertStatus(409);
    }

    public function test_a_claim_beyond_the_annual_limit_is_refused(): void
    {
        // Limit defaults to 100,000; this one is already approved.
        MedicalClaim::create([
            'employee_id' => $this->employee->id,
            'type' => 'Earlier claim',
            'amount' => 95000,
            'incurred_on' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $this->postJson('/api/medical-claims', [
            'type' => 'Another claim',
            'amount' => 20000,
        ], $this->auth('worker@test.com'))->assertStatus(409);
    }

    /**
     * A partial approval settles less than claimed, and it is the authorised
     * figure that consumes the allowance.
     */
    public function test_a_claim_can_be_partially_approved(): void
    {
        $claim = MedicalClaim::create([
            'employee_id' => $this->employee->id,
            'type' => 'Dental',
            'amount' => 10000,
            'incurred_on' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/medical-claims/{$claim->id}/decision", [
            'decision' => 'approved',
            'approvedAmount' => 6000,
        ], $this->auth('hr@test.com'))->assertOk();

        $this->assertSame('6000.00', $claim->fresh()->approved_amount);

        // Asserted through the service rather than a second HTTP call: within
        // one test process Laravel keeps the user resolved by the first
        // authenticated request, so hitting /usage after approving as HR would
        // answer as HR (whose usage is legitimately zero).
        $usage = app(\App\Services\MedicalClaimService::class)->annualUsage($this->employee);

        $this->assertSame(6000.0, $usage['used'], 'the authorised amount consumes the allowance');
        $this->assertSame(94000.0, $usage['remaining']);
    }

    /** The claimed amount consumes the allowance when no partial figure is set. */
    public function test_a_fully_approved_claim_consumes_its_full_amount(): void
    {
        $claim = MedicalClaim::create([
            'employee_id' => $this->employee->id,
            'type' => 'Dental',
            'amount' => 7500,
            'incurred_on' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/medical-claims/{$claim->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))->assertOk();

        $usage = app(\App\Services\MedicalClaimService::class)->annualUsage($this->employee);
        $this->assertSame(7500.0, $usage['used']);
    }

    public function test_approving_more_than_the_claim_is_refused(): void
    {
        $claim = MedicalClaim::create([
            'employee_id' => $this->employee->id,
            'type' => 'Dental',
            'amount' => 5000,
            'incurred_on' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/medical-claims/{$claim->id}/decision", [
            'decision' => 'approved',
            'approvedAmount' => 9000,
        ], $this->auth('hr@test.com'))->assertStatus(409);
    }

    // --- settings --------------------------------------------------------

    public function test_a_receipt_is_stored_and_readable_by_its_owner(): void
    {
        Storage::fake('local');

        $this->postJson('/api/medical-claims', [
            'type' => 'Surgery',
            'amount' => 8000,
            'incurredOn' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('bill.png'),
        ], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('claim.hasReceipt', true);

        $claim = MedicalClaim::firstOrFail();
        Storage::disk('local')->assertExists($claim->receipt_path);

        $this->get('/api/medical-claims/'.$claim->id.'/receipt', $this->auth('worker@test.com'))
            ->assertOk();
    }

    /**
     * Receipts are medical records. Someone outside the claimant's scope is
     * refused with 404 rather than 403 — a 403 would confirm the claim exists.
     *
     * Asserted through the service: within one test process the auth guard
     * caches the first resolved user, so a second HTTP call with the other
     * employee's token would still act as the claimant and wrongly pass.
     */
    public function test_a_receipt_is_hidden_from_an_unrelated_employee(): void
    {
        Storage::fake('local');

        $this->postJson('/api/medical-claims', [
            'type' => 'Surgery',
            'amount' => 8000,
            'incurredOn' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('bill.png'),
        ], $this->auth('worker@test.com'))->assertCreated();

        $this->makeEmployee('nosy@test.com', 'employee');
        $claim = MedicalClaim::firstOrFail();
        $service = app(MedicalClaimService::class);

        // The owner may read it.
        $this->assertSame(
            $claim->id,
            $service->findForReceipt(User::where('email', 'worker@test.com')->firstOrFail(), $claim->id)->id,
        );

        $this->expectException(NotFoundHttpException::class);
        $service->findForReceipt(User::where('email', 'nosy@test.com')->firstOrFail(), $claim->id);
    }

    public function test_settings_are_readable_and_persist(): void
    {
        $this->getJson('/api/settings', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('settings.attendance.graceMinutes', 10);

        app(SettingsService::class)->put('attendance', 'graceMinutes', 25);

        $this->getJson('/api/settings', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('settings.attendance.graceMinutes', 25);
    }

    /**
     * No role names 'Settings' in the grid, so super admin reaches it only
     * through the blanket grant. withSuperAdminGrant() expands over modules
     * the grid already mentions, which would leave this route 403 for
     * everyone — including the one role that is meant to own it.
     */
    public function test_super_admin_may_write_a_module_no_role_names(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');

        $this->putJson('/api/settings/attendance', [
            'values' => ['startTime' => '10:00', 'graceMinutes' => 20],
        ], $this->auth('boss@test.com'))->assertOk();

        $this->assertSame(20, app(SettingsService::class)->get('attendance', 'graceMinutes'));
    }

    public function test_an_employee_may_not_write_settings(): void
    {
        $this->putJson('/api/settings/attendance', [
            'values' => ['graceMinutes' => 99],
        ], $this->auth('worker@test.com'))->assertStatus(403);

        $this->assertSame(10, app(SettingsService::class)->get('attendance', 'graceMinutes'));
    }
}
