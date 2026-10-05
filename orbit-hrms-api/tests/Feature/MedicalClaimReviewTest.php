<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MedicalClaim;
use App\Models\Notification;
use App\Models\User;
use App\Services\MedicalClaimService;
use Database\Seeders\RolePermissionSeeder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TenantTestCase;

/**
 * The review steps before a claim is decided.
 *
 * 'view' and 'processing' were tracked only in the reviewer's browser, so a
 * claim marked processing was pending again on the next load and its owner
 * never heard that anyone had looked at it.
 */
class MedicalClaimReviewTest extends TenantTestCase
{
    private Employee $hr;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
        $this->employee = $this->makeEmployee('worker@test.com', 'employee');
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

    private function userOf(Employee $employee): User
    {
        return User::findOrFail($employee->user_id);
    }

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        $this->app['auth']->forgetGuards();

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function claimBy(Employee $employee): MedicalClaim
    {
        return MedicalClaim::create([
            'employee_id' => $employee->id,
            'type' => 'Consultation',
            'amount' => 4000,
            'incurred_on' => now()->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function test_a_review_stage_survives_a_reload(): void
    {
        $claim = $this->claimBy($this->employee);
        $headers = $this->auth('hr@test.com');

        $this->postJson("/api/medical-claims/{$claim->id}/stage", ['stage' => 'view'], $headers)
            ->assertOk()
            ->assertJsonPath('claim.reviewStage', 'view')
            ->assertJsonPath('claim.status', 'pending')
            ->assertJsonPath('claim.viewedByName', 'hr@test.com');

        $listed = collect($this->getJson('/api/medical-claims', $headers)->assertOk()->json('data'))
            ->firstWhere('id', $claim->id);

        $this->assertSame('view', $listed['reviewStage']);
        $this->assertNotNull($listed['viewedAt']);
    }

    /**
     * Status stays pending through review: annual usage, reports and payroll
     * all filter on it, and none of them should treat a claim as settled
     * because someone opened it.
     */
    public function test_processing_leaves_status_pending_and_records_the_view_too(): void
    {
        $claim = $this->claimBy($this->employee);

        $reviewed = app(MedicalClaimService::class)->advanceStage($this->userOf($this->hr), $claim->id, 'processing');

        $this->assertSame('pending', $reviewed->status);
        $this->assertSame('processing', $reviewed->reviewStage());
        $this->assertNotNull($reviewed->viewed_at);
        $this->assertSame($this->hr->id, $reviewed->processing_by);
    }

    public function test_processing_tells_the_employee_but_opening_does_not(): void
    {
        $claim = $this->claimBy($this->employee);
        $claims = app(MedicalClaimService::class);

        $claims->advanceStage($this->userOf($this->hr), $claim->id, 'view');
        $this->assertSame(0, Notification::where('employee_id', $this->employee->id)->count());

        $claims->advanceStage($this->userOf($this->hr), $claim->id, 'processing');
        $this->assertSame(
            ['Medical claim in processing'],
            Notification::where('employee_id', $this->employee->id)->pluck('title')->all(),
        );
    }

    public function test_a_claim_in_processing_can_still_be_decided(): void
    {
        $claim = $this->claimBy($this->employee);
        $claims = app(MedicalClaimService::class);

        $claims->advanceStage($this->userOf($this->hr), $claim->id, 'processing');
        $decided = $claims->decide($this->userOf($this->hr), $claim->id, 'approved');

        $this->assertSame('approved', $decided->status);
        // A decision supersedes the stage.
        $this->assertNull($decided->reviewStage());
    }

    public function test_a_stage_cannot_be_repeated_or_set_after_a_decision(): void
    {
        $claim = $this->claimBy($this->employee);
        $claims = app(MedicalClaimService::class);
        $claims->advanceStage($this->userOf($this->hr), $claim->id, 'view');

        try {
            $claims->advanceStage($this->userOf($this->hr), $claim->id, 'view');
            $this->fail('The same stage was recorded twice.');
        } catch (ConflictHttpException) {
            // expected
        }

        $claims->decide($this->userOf($this->hr), $claim->id, 'rejected', null, 'Not covered');

        $this->expectException(ConflictHttpException::class);
        $claims->advanceStage($this->userOf($this->hr), $claim->id, 'processing');
    }

    /** The same rule as deciding: nobody moves their own claim along. */
    public function test_a_reviewer_cannot_advance_their_own_claim(): void
    {
        $claim = $this->claimBy($this->hr);

        $this->expectException(AccessDeniedHttpException::class);
        app(MedicalClaimService::class)->advanceStage($this->userOf($this->hr), $claim->id, 'view');
    }

    public function test_an_employee_cannot_advance_a_claim(): void
    {
        $claim = $this->claimBy($this->employee);

        $this->postJson("/api/medical-claims/{$claim->id}/stage", ['stage' => 'processing'], $this->auth('worker@test.com'))
            ->assertForbidden();

        $this->assertNull($claim->fresh()->processing_at);
    }
}
