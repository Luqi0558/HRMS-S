<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Salary;
use App\Models\SalarySlip;
use App\Models\SalarySlipRequest;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TenantTestCase;

class PayrollTest extends TenantTestCase
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

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function giveSalary(Employee $employee, float $base = 100000, ?string $from = null): Salary
    {
        return Salary::create([
            'employee_id' => $employee->id,
            'base_salary' => $base,
            'house_allowance' => round($base * 0.12),
            'medical_allowance' => round($base * 0.06),
            'effective_from' => $from ?? now()->subYear()->toDateString(),
        ]);
    }

    // --- salaries --------------------------------------------------------

    public function test_hr_can_record_a_salary(): void
    {
        $this->postJson("/api/employees/{$this->employee->id}/salaries", [
            'baseSalary' => 120000,
            'reason' => 'Annual review',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('salary.baseSalary', '120000')
            // Components default to their standard share of base pay.
            ->assertJsonPath('salary.houseAllowance', '14400')
            ->assertJsonPath('salary.medicalAllowance', '7200');
    }

    /** Pay is encrypted at rest — a database dump must not expose it. */
    public function test_salary_is_not_stored_in_plain_text(): void
    {
        $this->giveSalary($this->employee, 123456);

        $raw = \DB::table('salaries')->value('base_salary');

        $this->assertStringNotContainsString('123456', $raw);
        $this->assertSame(123456.0, (float) Salary::first()->base_salary);
    }

    /**
     * A raise is a new row, not an edit. Past slips have to stay explicable,
     * which they cannot be if the rate behind them was overwritten.
     */
    public function test_a_raise_does_not_overwrite_the_previous_rate(): void
    {
        $this->giveSalary($this->employee, 100000, '2026-01-01');

        $this->postJson("/api/employees/{$this->employee->id}/salaries", [
            'baseSalary' => 150000,
            'effectiveFrom' => '2026-07-01',
        ], $this->auth('hr@test.com'))->assertCreated();

        $this->assertSame(2, Salary::where('employee_id', $this->employee->id)->count());

        $service = app(PayrollService::class);
        $this->assertSame(100000.0, (float) $service->salaryOn($this->employee, Carbon::parse('2026-03-01'))->base_salary);
        $this->assertSame(150000.0, (float) $service->salaryOn($this->employee, Carbon::parse('2026-08-01'))->base_salary);
    }

    /** A raise dated next month must not apply to this month's slip. */
    public function test_a_future_rate_does_not_apply_yet(): void
    {
        $this->giveSalary($this->employee, 100000, now()->subMonths(6)->toDateString());
        $this->giveSalary($this->employee, 200000, now()->addMonth()->toDateString());

        $current = app(PayrollService::class)->salaryOn($this->employee);

        $this->assertSame(100000.0, (float) $current->base_salary);
    }

    public function test_an_employee_may_not_record_a_salary(): void
    {
        $this->postJson("/api/employees/{$this->employee->id}/salaries", [
            'baseSalary' => 999999,
        ], $this->auth('worker@test.com'))->assertStatus(403);

        $this->assertSame(0, Salary::count());
    }

    /**
     * Payroll scope is 'self' for everyone but HR, so an employee reading
     * someone else's history gets 404 — a 403 would confirm it exists.
     */
    public function test_an_employee_cannot_read_another_salary_history(): void
    {
        $other = $this->makeEmployee('other@test.com', 'employee');
        $this->giveSalary($other);

        $service = app(PayrollService::class);
        $worker = User::where('email', 'worker@test.com')->firstOrFail();

        $this->expectException(NotFoundHttpException::class);
        $service->salaryHistory($worker, $other->id);
    }

    public function test_an_employee_can_read_their_own_salary_history(): void
    {
        $this->giveSalary($this->employee, 111000);

        $this->getJson("/api/employees/{$this->employee->id}/salaries", $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('salaries.0.baseSalary', '111000');
    }

    // --- slip requests ---------------------------------------------------

    public function test_an_employee_can_request_a_slip(): void
    {
        $this->postJson('/api/salary-slip-requests', [
            'period' => now()->subMonth()->toDateString(),
        ], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending');
    }

    public function test_a_duplicate_request_for_the_same_month_is_refused(): void
    {
        $period = now()->subMonth()->startOfMonth()->toDateString();

        $this->postJson('/api/salary-slip-requests', ['period' => $period], $this->auth('worker@test.com'))
            ->assertCreated();

        // A different day in the same month is still the same pay period.
        $this->postJson('/api/salary-slip-requests', [
            'period' => now()->subMonth()->startOfMonth()->addDays(9)->toDateString(),
        ], $this->auth('worker@test.com'))->assertStatus(409);

        $this->assertSame(1, SalarySlipRequest::count());
    }

    public function test_a_slip_cannot_be_requested_for_a_future_month(): void
    {
        $this->postJson('/api/salary-slip-requests', [
            'period' => now()->addMonths(2)->toDateString(),
        ], $this->auth('worker@test.com'))->assertStatus(409);
    }

    // --- issuing ---------------------------------------------------------

    public function test_approving_a_request_issues_the_slip(): void
    {
        $this->giveSalary($this->employee, 100000);
        $request = SalarySlipRequest::create([
            'employee_id' => $this->employee->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('request.status', 'approved')
            // 100000 + 12000 + 6000 - 5000 - 3000 = 110000
            ->assertJsonPath('request.slip.netPay', '110000');

        $this->assertSame(1, SalarySlip::count());
    }

    public function test_rejecting_a_request_issues_no_slip(): void
    {
        $this->giveSalary($this->employee);
        $request = SalarySlipRequest::create([
            'employee_id' => $this->employee->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", [
            'decision' => 'rejected',
            'comment' => 'Period not closed.',
        ], $this->auth('hr@test.com'))->assertOk();

        $this->assertSame(0, SalarySlip::count());
    }

    /** A bonus applies to one month without altering the standing salary. */
    public function test_an_override_adjusts_only_that_slip(): void
    {
        $this->giveSalary($this->employee, 100000);
        $request = SalarySlipRequest::create([
            'employee_id' => $this->employee->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", [
            'decision' => 'approved',
            'bonus' => 25000,
        ], $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('request.slip.netPay', '135000');

        // The salary on record is untouched.
        $this->assertSame(100000.0, (float) Salary::first()->base_salary);
    }

    /**
     * A slip records what was paid at the time. Raising the salary afterwards
     * must not change a slip already issued.
     */
    public function test_a_later_raise_does_not_alter_an_issued_slip(): void
    {
        $this->giveSalary($this->employee, 100000, '2026-01-01');
        $request = SalarySlipRequest::create([
            'employee_id' => $this->employee->id,
            'period' => '2026-06-01',
            'status' => 'pending',
        ]);

        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))->assertOk();

        $before = (string) SalarySlip::first()->net_pay;

        app(PayrollService::class)->setSalary(
            User::where('email', 'hr@test.com')->firstOrFail(),
            $this->employee->id,
            ['baseSalary' => 500000, 'effectiveFrom' => '2026-07-01'],
        );

        $this->assertSame($before, (string) SalarySlip::first()->fresh()->net_pay);
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        $this->giveSalary($this->employee);
        $request = SalarySlipRequest::create([
            'employee_id' => $this->employee->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'status' => 'pending',
        ]);

        $headers = $this->auth('hr@test.com');
        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", ['decision' => 'approved'], $headers)
            ->assertOk();
        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", ['decision' => 'rejected'], $headers)
            ->assertStatus(409);

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_slip_cannot_be_issued_without_a_salary_on_record(): void
    {
        $request = SalarySlipRequest::create([
            'employee_id' => $this->employee->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))->assertStatus(409);

        // The whole decision rolls back — no slip, and the request stays open.
        $this->assertSame(0, SalarySlip::count());
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_an_employee_may_not_approve_their_own_slip_request(): void
    {
        $this->giveSalary($this->employee);
        $request = SalarySlipRequest::create([
            'employee_id' => $this->employee->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/salary-slip-requests/{$request->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('worker@test.com'))->assertStatus(403);

        $this->assertSame(0, SalarySlip::count());
    }

    // --- reading slips ---------------------------------------------------

    public function test_an_employee_sees_only_their_own_slips(): void
    {
        $other = $this->makeEmployee('other@test.com', 'employee');
        $this->giveSalary($this->employee);
        $this->giveSalary($other);

        foreach ([$this->employee, $other] as $subject) {
            SalarySlip::create([
                'employee_id' => $subject->id,
                'period' => '2026-06-01',
                'base_salary' => 100000, 'house_allowance' => 12000,
                'medical_allowance' => 6000, 'bonus' => 0,
                'tax_deduction' => 5000, 'attendance_deduction' => 3000,
                'advance_deduction' => 0, 'net_pay' => 110000,
                'issued_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/salary-slips', $this->auth('worker@test.com'))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->employee->id, $response->json('data.0.employeeId'));
    }

    public function test_hr_sees_every_slip(): void
    {
        $other = $this->makeEmployee('other@test.com', 'employee');

        foreach ([$this->employee, $other] as $subject) {
            SalarySlip::create([
                'employee_id' => $subject->id,
                'period' => '2026-06-01',
                'base_salary' => 100000, 'house_allowance' => 12000,
                'medical_allowance' => 6000, 'bonus' => 0,
                'tax_deduction' => 5000, 'attendance_deduction' => 3000,
                'advance_deduction' => 0, 'net_pay' => 110000,
                'issued_at' => now(),
            ]);
        }

        $this->assertCount(2, $this->getJson('/api/salary-slips', $this->auth('hr@test.com'))->json('data'));
    }

    // --- release and payment ---------------------------------------------

    private function issuedSlip(?Employee $for = null): SalarySlip
    {
        return SalarySlip::create([
            'employee_id' => ($for ?? $this->employee)->id,
            'period' => '2026-06-01',
            'status' => 'generated',
            'base_salary' => 100000, 'house_allowance' => 12000,
            'medical_allowance' => 6000, 'bonus' => 0,
            'tax_deduction' => 5000, 'attendance_deduction' => 3000,
            'advance_deduction' => 0, 'net_pay' => 110000,
            'issued_at' => now(),
        ]);
    }

    /**
     * Preparing payroll and authorising it are separate rights. HR compiles
     * the run; only 'Payroll Approval'.approve releases it.
     */
    public function test_hr_cannot_release_payroll_for_payment(): void
    {
        $slip = $this->issuedSlip();

        $this->postJson("/api/salary-slips/{$slip->id}/approve", [], $this->auth('hr@test.com'))
            ->assertStatus(403);

        $this->assertSame('generated', $slip->fresh()->status);
    }

    public function test_super_admin_can_release_payroll(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $slip = $this->issuedSlip();

        $this->postJson("/api/salary-slips/{$slip->id}/approve", [], $this->auth('boss@test.com'))
            ->assertOk()
            ->assertJsonPath('slip.status', 'approved');
    }

    /**
     * Paying an unreleased slip would skip authorisation entirely, so the
     * order is enforced rather than assumed.
     */
    public function test_an_unreleased_slip_cannot_be_marked_paid(): void
    {
        $slip = $this->issuedSlip();

        $this->postJson("/api/salary-slips/{$slip->id}/payment", [
            'method' => 'Bank transfer',
        ], $this->auth('hr@test.com'))->assertStatus(409);

        $this->assertSame('generated', $slip->fresh()->status);
    }

    public function test_a_released_slip_records_its_payment(): void
    {
        $slip = $this->issuedSlip();
        $slip->update(['status' => 'approved', 'approved_at' => now()]);

        $this->postJson("/api/salary-slips/{$slip->id}/payment", [
            'method' => 'Bank transfer',
            'reference' => 'TRX-9910',
            'paidDate' => '2026-07-02',
        ], $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('slip.status', 'paid')
            ->assertJsonPath('slip.payment.reference', 'TRX-9910');

        // The figures are untouched — payment records how it was settled,
        // not what was owed.
        $this->assertSame(110000.0, (float) $slip->fresh()->net_pay);
    }

    public function test_a_slip_cannot_be_paid_twice(): void
    {
        $slip = $this->issuedSlip();
        $slip->update(['status' => 'approved', 'approved_at' => now()]);

        $headers = $this->auth('hr@test.com');
        $this->postJson("/api/salary-slips/{$slip->id}/payment", ['method' => 'Bank transfer'], $headers)
            ->assertOk();
        $this->postJson("/api/salary-slips/{$slip->id}/payment", ['method' => 'Cash'], $headers)
            ->assertStatus(409);

        $this->assertSame('Bank transfer', $slip->fresh()->payment_method);
    }

    public function test_a_released_slip_can_be_reopened_for_correction(): void
    {
        $slip = $this->issuedSlip();
        $slip->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->hr->id]);

        $this->postJson("/api/salary-slips/{$slip->id}/reopen", [], $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('slip.status', 'generated');

        // The release is cleared so it must be authorised again.
        $this->assertNull($slip->fresh()->approved_at);
    }

    /** Money has already left, so a settled month is not rewritten. */
    public function test_a_paid_slip_cannot_be_reopened(): void
    {
        $slip = $this->issuedSlip();
        $slip->update(['status' => 'paid', 'paid_at' => now()]);

        $this->postJson("/api/salary-slips/{$slip->id}/reopen", [], $this->auth('hr@test.com'))
            ->assertStatus(409);

        $this->assertSame('paid', $slip->fresh()->status);
    }

    // --- payroll cost ----------------------------------------------------

    public function test_monthly_cost_sums_active_employees(): void
    {
        $second = $this->makeEmployee('second@test.com', 'employee');
        $this->giveSalary($this->employee, 100000);
        $this->giveSalary($second, 200000);

        // 110000 + 220000, and HR has no salary on record so is skipped.
        $this->getJson('/api/salary-slips/cost', $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('cost.employees', 2)
            ->assertJsonPath('cost.total', 330000);
    }

    public function test_an_employee_may_not_read_the_payroll_cost(): void
    {
        $service = app(PayrollService::class);
        $worker = User::where('email', 'worker@test.com')->firstOrFail();

        $this->expectException(AccessDeniedHttpException::class);
        $service->monthlyCost($worker);
    }
}
