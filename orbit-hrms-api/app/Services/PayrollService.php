<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Salary;
use App\Models\SalarySlip;
use App\Models\SalarySlipRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Salaries, slip requests and issued slips.
 *
 * Two rules shape everything here:
 *
 * Pay is private. Payroll scope is 'self' for every role except HR, so a
 * manager or team lead sees their own salary and nobody else's — unlike
 * leave, where a lead legitimately sees their team. Records outside scope are
 * 404, not 403: a 403 confirms the record exists, which for salary is itself
 * a disclosure.
 *
 * Issued slips are immutable. A slip stores every figure rather than pointing
 * at the salary table, so a later raise cannot rewrite what June says was
 * paid. Nothing in this service updates a slip after issue.
 */
class PayrollService
{
    /**
     * Default components as a share of base pay, mirroring
     * defaultPayrollDetails() in src/views/SalarySlips.vue. The two must agree
     * or a slip previewed in the browser would not match the one issued.
     */
    private const DEFAULT_RATES = [
        'house_allowance' => 0.12,
        'medical_allowance' => 0.06,
        'tax_deduction' => 0.05,
        'attendance_deduction' => 0.03,
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
    ) {
    }

    // --- salaries --------------------------------------------------------

    /**
     * The rate in force for an employee on a given date.
     *
     * The latest row whose effective_from has passed — a raise dated next
     * month must not apply to this month's slip.
     */
    public function salaryOn(Employee $employee, ?Carbon $date = null): ?Salary
    {
        return Salary::where('employee_id', $employee->id)
            ->where('effective_from', '<=', ($date ?? now())->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /** Full pay history for one employee, newest first. */
    public function salaryHistory(User $user, int $employeeId): array
    {
        $this->assertMaySee($user, $employeeId);

        return Salary::with('creator:id,name')
            ->where('employee_id', $employeeId)
            ->orderByDesc('effective_from')
            ->get()
            ->all();
    }

    /**
     * Records a new rate. Never updates the previous one — that is what keeps
     * past slips explicable.
     */
    public function setSalary(User $user, int $employeeId, array $attributes): Salary
    {
        $this->assertMayManagePay($user);

        $employee = Employee::find($employeeId);

        if (! $employee) {
            throw new NotFoundHttpException('Employee not found.');
        }

        $effectiveFrom = $attributes['effectiveFrom'] ?? now()->startOfMonth()->toDateString();

        if (Salary::where('employee_id', $employeeId)->where('effective_from', $effectiveFrom)->exists()) {
            throw new ConflictHttpException('A salary already starts on that date.');
        }

        $base = (float) $attributes['baseSalary'];

        return Salary::create([
            'employee_id' => $employeeId,
            'base_salary' => $base,
            // Absent components fall back to the standard share of base pay,
            // so HR can record a raise by changing one number.
            'house_allowance' => $attributes['houseAllowance']
                ?? round($base * self::DEFAULT_RATES['house_allowance']),
            'medical_allowance' => $attributes['medicalAllowance']
                ?? round($base * self::DEFAULT_RATES['medical_allowance']),
            'effective_from' => $effectiveFrom,
            'created_by' => $this->employeeFor($user)?->id,
            'reason' => $attributes['reason'] ?? null,
        ]);
    }

    // --- slip requests ---------------------------------------------------

    public function listRequests(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $row = $this->payrollRow($user);

        $query = SalarySlipRequest::with(['employee:id,name,department', 'reviewer:id,name', 'slip']);

        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        if ($visibleIds !== null) {
            $query->whereIn('employee_id', $visibleIds);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('period')->paginate($perPage);
    }

    public function requestSlip(User $user, array $attributes): SalarySlipRequest
    {
        $employee = $this->requireEmployee($user);

        // Normalised to the first of the month: the period is a month, and two
        // requests differing only by day-of-month would defeat the unique key.
        $period = Carbon::parse($attributes['period'])->startOfMonth();

        if ($period->isAfter(now()->startOfMonth())) {
            throw new ConflictHttpException('That pay period has not started yet.');
        }

        if (SalarySlipRequest::where('employee_id', $employee->id)
            ->whereDate('period', $period->toDateString())->exists()) {
            throw new ConflictHttpException('You have already requested a slip for that month.');
        }

        return SalarySlipRequest::create([
            'employee_id' => $employee->id,
            'period' => $period->toDateString(),
            'status' => 'pending',
        ]);
    }

    /**
     * Approves or rejects a request. Approval issues the slip in the same
     * transaction — an approved request with no slip would leave the employee
     * told yes with nothing to show.
     */
    public function decideRequest(
        User $user,
        int $requestId,
        string $decision,
        array $overrides = [],
        ?string $comment = null
    ): SalarySlipRequest {
        $this->assertMayManagePay($user);

        $request = SalarySlipRequest::find($requestId);

        if (! $request) {
            throw new NotFoundHttpException('Request not found.');
        }

        if ($request->status !== 'pending') {
            throw new ConflictHttpException('This request has already been decided.');
        }

        $reviewer = $this->employeeFor($user);

        return DB::transaction(function () use ($request, $decision, $overrides, $comment, $reviewer) {
            $request->update([
                'status' => $decision,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ]);

            if ($decision === 'approved') {
                $this->issueSlip($request, $overrides, $reviewer);
            }

            return $request->refresh()->load(['employee:id,name', 'reviewer:id,name', 'slip']);
        });
    }

    // --- slips -----------------------------------------------------------

    public function listSlips(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $row = $this->payrollRow($user);

        $query = SalarySlip::with([
            'employee:id,name,department', 'issuer:id,name',
            'approver:id,name', 'payer:id,name',
        ]);

        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        if ($visibleIds !== null) {
            $query->whereIn('employee_id', $visibleIds);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['period'] ?? null)) {
            $query->whereDate('period', Carbon::parse($filters['period'])->startOfMonth()->toDateString());
        }

        if (filled($filters['employee_id'] ?? null)) {
            $query->where('employee_id', $filters['employee_id']);
        }

        return $query->orderByDesc('period')->paginate($perPage);
    }

    public function findSlip(User $user, int $slipId): SalarySlip
    {
        $slip = SalarySlip::with(['employee:id,name,department', 'issuer:id,name'])->find($slipId);

        if (! $slip) {
            throw new NotFoundHttpException('Slip not found.');
        }

        $this->assertMaySee($user, $slip->employee_id);

        return $slip;
    }

    /**
     * Releases a generated slip for payment.
     *
     * Gated on 'Payroll Approval'.approve rather than Payroll.create: the
     * screen separates preparing payroll from authorising it, so the person
     * who compiles the run is not necessarily the one who releases it. Super
     * admin holds this through the blanket grant.
     */
    public function approveSlip(User $user, int $slipId): SalarySlip
    {
        if (! $this->permissions->can($user, 'Payroll Approval', 'approve')) {
            throw new AccessDeniedHttpException('You may not release payroll for payment.');
        }

        $slip = SalarySlip::find($slipId);

        if (! $slip) {
            throw new NotFoundHttpException('Slip not found.');
        }

        if ($slip->status !== 'generated') {
            throw new ConflictHttpException('Only a generated slip can be released.');
        }

        $slip->update([
            'status' => 'approved',
            'approved_by' => $this->employeeFor($user)?->id,
            'approved_at' => now(),
        ]);

        return $slip->refresh()->load(['employee:id,name', 'approver:id,name']);
    }

    /**
     * Records that a released slip has actually been paid.
     *
     * Needs Payroll.create — this is a bookkeeping entry made by whoever runs
     * the transfer, not a second authorisation. The figures on the slip are
     * untouched; only how and when it was settled is added.
     */
    public function markPaid(User $user, int $slipId, array $payment): SalarySlip
    {
        $this->assertMayManagePay($user);

        $slip = SalarySlip::find($slipId);

        if (! $slip) {
            throw new NotFoundHttpException('Slip not found.');
        }

        // Paying an unreleased slip would bypass the authorisation step
        // entirely, so the order is enforced rather than assumed.
        if ($slip->status !== 'approved') {
            throw new ConflictHttpException('Only a released slip can be marked paid.');
        }

        $slip->update([
            'status' => 'paid',
            'payment_method' => $payment['method'] ?? 'Bank transfer',
            'payment_reference' => $payment['reference'] ?? null,
            'paid_on' => $payment['paidDate'] ?? now()->toDateString(),
            'payment_notes' => $payment['notes'] ?? null,
            'paid_by' => $this->employeeFor($user)?->id,
            'paid_at' => now(),
        ]);

        return $slip->refresh()->load(['employee:id,name', 'payer:id,name']);
    }

    /**
     * Reopens a slip for correction.
     *
     * Deleting it would lose the record that a slip was issued at all, so the
     * slip stays and returns to 'generated'. A paid slip cannot be reopened:
     * money has left, and the correction belongs in the next run rather than
     * in rewriting a settled month.
     */
    public function reopenSlip(User $user, int $slipId): SalarySlip
    {
        $this->assertMayManagePay($user);

        $slip = SalarySlip::find($slipId);

        if (! $slip) {
            throw new NotFoundHttpException('Slip not found.');
        }

        if ($slip->status === 'paid') {
            throw new ConflictHttpException('A paid slip cannot be reopened.');
        }

        if ($slip->status === 'generated') {
            throw new ConflictHttpException('This slip is already open for correction.');
        }

        $slip->update([
            'status' => 'generated',
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $slip->refresh()->load('employee:id,name');
    }

    /**
     * The company's payroll cost for a month.
     *
     * Summed in PHP rather than SQL because the components are encrypted —
     * the database cannot add columns it cannot read.
     */
    public function monthlyCost(User $user, ?string $period = null): array
    {
        $this->assertMayManagePay($user);

        $month = Carbon::parse($period ?? now())->startOfMonth();

        $employees = Employee::where('status', 'active')->get();

        $total = 0.0;
        $counted = 0;

        foreach ($employees as $employee) {
            $salary = $this->salaryOn($employee, $month);

            if (! $salary) {
                continue;
            }

            $components = $this->componentsFor($salary);
            $total += $this->netPay($components);
            $counted++;
        }

        return [
            'period' => $month->toDateString(),
            'employees' => $counted,
            'total' => $total,
        ];
    }

    // --- internals -------------------------------------------------------

    /**
     * Builds and stores the slip for an approved request.
     *
     * Components come from the salary in force during that period, so a slip
     * approved late still reflects what the employee was earning then.
     */
    private function issueSlip(SalarySlipRequest $request, array $overrides, ?Employee $reviewer): SalarySlip
    {
        if (SalarySlip::where('employee_id', $request->employee_id)
            ->whereDate('period', $request->period->toDateString())->exists()) {
            throw new ConflictHttpException('A slip has already been issued for that month.');
        }

        $employee = $request->employee ?? Employee::findOrFail($request->employee_id);
        $salary = $this->salaryOn($employee, $request->period);

        if (! $salary) {
            throw new ConflictHttpException('No salary is on record for that employee.');
        }

        $components = array_merge($this->componentsFor($salary), array_filter(
            [
                'base_salary' => $overrides['baseSalary'] ?? null,
                'house_allowance' => $overrides['houseAllowance'] ?? null,
                'medical_allowance' => $overrides['medicalAllowance'] ?? null,
                'bonus' => $overrides['bonus'] ?? null,
                'tax_deduction' => $overrides['taxDeduction'] ?? null,
                'attendance_deduction' => $overrides['attendanceDeduction'] ?? null,
                'advance_deduction' => $overrides['advanceDeduction'] ?? null,
            ],
            fn ($value) => $value !== null,
        ));

        return SalarySlip::create(array_merge($components, [
            'employee_id' => $request->employee_id,
            'salary_slip_request_id' => $request->id,
            'period' => $request->period->toDateString(),
            'net_pay' => $this->netPay($components),
            'notes' => $overrides['notes'] ?? null,
            'issued_by' => $reviewer?->id,
            'issued_at' => now(),
        ]));
    }

    /** A salary expanded into the components a slip records. */
    private function componentsFor(Salary $salary): array
    {
        $base = (float) $salary->base_salary;

        return [
            'base_salary' => $base,
            'house_allowance' => (float) ($salary->house_allowance
                ?? round($base * self::DEFAULT_RATES['house_allowance'])),
            'medical_allowance' => (float) ($salary->medical_allowance
                ?? round($base * self::DEFAULT_RATES['medical_allowance'])),
            'bonus' => 0.0,
            'tax_deduction' => round($base * self::DEFAULT_RATES['tax_deduction']),
            'attendance_deduction' => round($base * self::DEFAULT_RATES['attendance_deduction']),
            'advance_deduction' => 0.0,
        ];
    }

    private function netPay(array $components): float
    {
        return (float) $components['base_salary']
            + (float) $components['house_allowance']
            + (float) $components['medical_allowance']
            + (float) $components['bonus']
            - (float) $components['tax_deduction']
            - (float) $components['attendance_deduction']
            - (float) $components['advance_deduction'];
    }

    /** The caller's Payroll row, or 403 if they hold none. */
    private function payrollRow(User $user): array
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Payroll');

        if (! $row || ! ($row['view'] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        return $row;
    }

    /**
     * Writing pay needs Payroll.create, which only HR holds. Super admin
     * reaches it through the blanket grant.
     */
    private function assertMayManagePay(User $user): void
    {
        if (! $this->permissions->can($user, 'Payroll', 'create')) {
            throw new AccessDeniedHttpException('You may not manage payroll.');
        }
    }

    /**
     * Whether this user may see one employee's pay. 404 rather than 403 —
     * confirming a salary record exists is itself a disclosure.
     */
    private function assertMaySee(User $user, int $employeeId): void
    {
        $row = $this->payrollRow($user);
        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);

        if ($visibleIds !== null && ! in_array($employeeId, $visibleIds, true)) {
            throw new NotFoundHttpException('Not found.');
        }
    }

    private function requireEmployee(User $user): Employee
    {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can request a salary slip.');
        }

        return $employee;
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
