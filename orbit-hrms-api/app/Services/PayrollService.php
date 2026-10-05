<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Salary;
use App\Models\SalarySlip;
use App\Models\SalarySlipRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly MessageService $messages,
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

        $query = SalarySlipRequest::with([
            'employee:id,name,department', 'reviewer:id,name', 'payrollRun:id,period,status', 'slip',
            // Named on the Payroll page's audit timeline.
            'slip.issuer:id,name', 'slip.approver:id,name', 'slip.payer:id,name',
        ]);

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
        // Payroll is a company run, not a request employees have to raise in
        // order to be paid. HR prepares each eligible employee's record.
        $this->assertMayManagePay($user);
        $employee = Employee::find($attributes['employeeId'] ?? null);
        if (! $employee) {
            throw new NotFoundHttpException('Employee not found.');
        }

        // Normalised to the first of the month: the period is a month, and two
        // requests differing only by day-of-month would defeat the unique key.
        $period = Carbon::parse($attributes['period'])->startOfMonth();

        if ($period->isAfter(now()->startOfMonth())) {
            throw new ConflictHttpException('That pay period has not started yet.');
        }

        $run = $this->openRunForPeriod($user, $period);

        if (SalarySlipRequest::where('employee_id', $employee->id)
            ->whereDate('period', $period->toDateString())->exists()) {
            throw new ConflictHttpException('You have already requested a slip for that month.');
        }

        return SalarySlipRequest::create([
            'employee_id' => $employee->id,
            'payroll_run_id' => $run->id,
            'period' => $period->toDateString(),
            'status' => 'pending',
        ]);
    }

    // --- monthly payroll runs ------------------------------------------

    /**
     * The monthly register visible to the two payroll control roles. It never
     * exposes salary figures itself; its only job is to show where each month
     * is in the workflow.
     */
    public function listRuns(User $user): Collection
    {
        $this->assertMaySeeCompanyPayroll($user);

        return PayrollRun::with([
            'preparer:id,name', 'submitter:id,name', 'releaser:id,name',
            'slips:id,payroll_run_id,status',
        ])->orderByDesc('period')->get();
    }

    /** HR opens a draft monthly cycle before preparing its employee slips. */
    public function openRun(User $user, string $period, ?string $notes = null): PayrollRun
    {
        $this->assertMayManagePay($user);

        $month = Carbon::parse($period)->startOfMonth();
        if ($month->isAfter(now()->startOfMonth())) {
            throw new ConflictHttpException('That pay period has not started yet.');
        }

        $run = $this->openRunForPeriod($user, $month);

        if ($notes !== null && $run->status === 'draft') {
            $run->update(['notes' => $notes]);
        }

        return $run->refresh()->load([
            'preparer:id,name', 'submitter:id,name', 'releaser:id,name',
            'slips:id,payroll_run_id,status',
        ]);
    }

    /**
     * HR submits a complete, generated month. This is intentionally distinct
     * from creating individual slips: the Super Admin signs off a register,
     * not a succession of unrelated employee rows.
     */
    public function submitRun(User $user, int $runId): PayrollRun
    {
        $this->assertMayManagePay($user);

        return DB::transaction(function () use ($user, $runId) {
            $run = PayrollRun::lockForUpdate()->find($runId);
            if (! $run) {
                throw new NotFoundHttpException('Payroll run not found.');
            }
            if ($run->status !== 'draft') {
                throw new ConflictHttpException('Only a draft payroll run can be submitted.');
            }

            $slips = SalarySlip::where('payroll_run_id', $run->id)->lockForUpdate()->get();
            if ($slips->isEmpty()) {
                throw new ConflictHttpException('Generate at least one payslip before submitting this run.');
            }
            if (SalarySlipRequest::where('payroll_run_id', $run->id)->where('status', 'pending')->exists()) {
                throw new ConflictHttpException('Resolve every prepared payroll record before submitting this run.');
            }
            if ($slips->contains(fn (SalarySlip $slip) => $slip->status !== 'generated')) {
                throw new ConflictHttpException('Only generated slips can be submitted for release.');
            }

            $run->update([
                'status' => 'submitted',
                'submitted_by' => $this->employeeFor($user)?->id,
                'submitted_at' => now(),
            ]);

            return $run->refresh()->load([
                'preparer:id,name', 'submitter:id,name', 'releaser:id,name',
                'slips:id,payroll_run_id,status',
            ]);
        });
    }

    /**
     * Super Admin releases every generated slip in the submitted register in
     * one atomic action. This makes it impossible to accidentally pay only a
     * selectively authorised subset of a monthly run.
     */
    public function releaseRun(User $user, int $runId): PayrollRun
    {
        $this->assertMayReleasePayroll($user);

        return DB::transaction(function () use ($user, $runId) {
            $run = PayrollRun::lockForUpdate()->find($runId);
            if (! $run) {
                throw new NotFoundHttpException('Payroll run not found.');
            }
            if ($run->status !== 'submitted') {
                throw new ConflictHttpException('Only a submitted payroll run can be released.');
            }

            $slips = SalarySlip::where('payroll_run_id', $run->id)->lockForUpdate()->get();
            if ($slips->isEmpty() || $slips->contains(fn (SalarySlip $slip) => $slip->status !== 'generated')) {
                throw new ConflictHttpException('This payroll run is not ready to release.');
            }

            $releaserId = $this->employeeFor($user)?->id;
            SalarySlip::where('payroll_run_id', $run->id)->update([
                'status' => 'approved',
                'approved_by' => $releaserId,
                'approved_at' => now(),
            ]);
            $run->update([
                'status' => 'released',
                'released_by' => $releaserId,
                'released_at' => now(),
            ]);

            return $run->refresh()->load([
                'preparer:id,name', 'submitter:id,name', 'releaser:id,name',
                'slips:id,payroll_run_id,status',
            ]);
        });
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
        $run = $request->payrollRun ?? $this->openRunForPeriod($user, $request->period);

        if ($run->status !== 'draft') {
            throw new ConflictHttpException('Payroll records can only be changed while their monthly run is a draft.');
        }

        $decided = DB::transaction(function () use ($request, $run, $decision, $overrides, $comment, $reviewer) {
            $request->update([
                'payroll_run_id' => $run->id,
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

        $this->messages->notify([$decided->employee_id], [
            'title' => $decision === 'approved' ? 'Payslip ready' : 'Payslip request rejected',
            'body' => sprintf('Your payslip for %s was %s.', $decided->period->format('F Y'), $decision),
            'type' => 'salary',
            'routeName' => 'payroll',
        ]);

        return $decided;
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

        // Retain the existing endpoint for older clients, while making a
        // run-owned slip follow the safer register-level release action.
        if ($slip->payroll_run_id) {
            $this->releaseRun($user, $slip->payroll_run_id);

            return $slip->refresh()->load(['employee:id,name', 'approver:id,name']);
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

        $this->markRunPaidIfSettled($slip->payroll_run_id, $this->employeeFor($user)?->id);

        // The one payroll event an employee genuinely wants to hear about.
        $this->messages->notify([$slip->employee_id], [
            'title' => 'Salary paid',
            'body' => sprintf('Your %s salary has been paid.', $slip->period->format('F Y')),
            'type' => 'salary',
            'routeName' => 'payroll',
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
    /**
     * Corrects the figures on a slip that is open for correction.
     *
     * reopenSlip() returns a released slip to 'generated' so it can be fixed,
     * but nothing could then change it: the Payroll page "saved" corrections
     * in the browser only, so the screen showed one net pay and the slip the
     * employee downloads another. Only a generated slip in a draft run may be
     * amended — released or paid figures are an authorisation already given.
     *
     * The attendance deduction is not editable here: it is calculated from
     * attendance records and stored with its summary so it stays auditable.
     */
    public function amendSlip(User $user, int $slipId, array $changes): SalarySlip
    {
        $this->assertMayManagePay($user);

        $slip = SalarySlip::with('payrollRun')->find($slipId);

        if (! $slip) {
            throw new NotFoundHttpException('Slip not found.');
        }

        if ($slip->status !== 'generated') {
            throw new ConflictHttpException(
                $slip->status === 'paid'
                    ? 'A paid slip cannot be changed.'
                    : 'Reopen this slip for correction before changing it.'
            );
        }

        if ($slip->payrollRun && $slip->payrollRun->status !== 'draft') {
            throw new ConflictHttpException('Payroll records can only be changed while their monthly run is a draft.');
        }

        $components = [
            'base_salary' => (float) ($changes['baseSalary'] ?? $slip->base_salary),
            'house_allowance' => (float) ($changes['houseAllowance'] ?? $slip->house_allowance),
            'medical_allowance' => (float) ($changes['medicalAllowance'] ?? $slip->medical_allowance),
            'bonus' => (float) ($changes['bonus'] ?? $slip->bonus),
            'tax_deduction' => (float) ($changes['taxDeduction'] ?? $slip->tax_deduction),
            'attendance_deduction' => (float) $slip->attendance_deduction,
            'advance_deduction' => (float) ($changes['advanceDeduction'] ?? $slip->advance_deduction),
        ];

        if ($components['base_salary'] <= 0) {
            throw ValidationException::withMessages(['baseSalary' => 'Base salary must be greater than zero.']);
        }

        $netPay = $this->netPay($components);

        if ($netPay < 0) {
            throw ValidationException::withMessages(['taxDeduction' => 'Total deductions cannot exceed total earnings.']);
        }

        $before = (float) $slip->net_pay;

        $slip->update(array_merge($components, [
            'net_pay' => $netPay,
            'notes' => array_key_exists('notes', $changes) ? $changes['notes'] : $slip->notes,
        ]));

        // The employee may already have downloaded this slip; a changed net
        // pay is something they should hear about, an unchanged one is not.
        if (round($before, 2) !== round($netPay, 2)) {
            $this->messages->notify([$slip->employee_id], [
                'title' => 'Payslip corrected',
                'body' => sprintf(
                    'Your payslip for %s was corrected. Net pay is now %s.',
                    $slip->period->format('F Y'),
                    number_format($netPay, 2),
                ),
                'type' => 'salary',
                'routeName' => 'payroll',
            ]);
        }

        return $slip->refresh()->load('employee:id,name');
    }

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

        if ($slip->payroll_run_id) {
            return DB::transaction(function () use ($slip) {
                $run = PayrollRun::lockForUpdate()->find($slip->payroll_run_id);

                if ($run?->status === 'paid') {
                    throw new ConflictHttpException('A paid payroll run cannot be reopened.');
                }

                // A release is an authorisation of the whole register. Editing
                // a row therefore returns every released row to generated, so
                // the corrected monthly total must be submitted and released
                // again as one coherent record.
                SalarySlip::where('payroll_run_id', $slip->payroll_run_id)
                    ->where('status', 'approved')
                    ->update([
                        'status' => 'generated',
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);

                if ($run) {
                    $run->update([
                        'status' => 'draft',
                        'released_by' => null,
                        'released_at' => null,
                        'submitted_by' => null,
                        'submitted_at' => null,
                    ]);
                }

                return $slip->refresh()->load('employee:id,name');
            });
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
        $this->assertMaySeeCompanyPayroll($user);

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

    /** Finds the company month, creating its HR-owned draft if needed. */
    private function openRunForPeriod(User $user, Carbon $period): PayrollRun
    {
        $month = $period->copy()->startOfMonth()->toDateString();

        return PayrollRun::firstOrCreate(
            ['period' => $month],
            [
                'status' => 'draft',
                'prepared_by' => $this->employeeFor($user)?->id,
            ],
        );
    }

    /** Marks the run paid only after every released item is paid. */
    private function markRunPaidIfSettled(?int $runId, ?int $payerId): void
    {
        if (! $runId) {
            return;
        }

        $run = PayrollRun::find($runId);
        if (! $run || $run->status !== 'released') {
            return;
        }

        if (! SalarySlip::where('payroll_run_id', $run->id)->where('status', '!=', 'paid')->exists()) {
            $run->update([
                'status' => 'paid',
                'paid_by' => $payerId,
                'paid_at' => now(),
            ]);
        }
    }

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

        $attendance = $this->attendanceAdjustment($employee, $request->period, (float) $salary->base_salary);

        $components = array_merge($this->componentsFor($salary), [
            'attendance_deduction' => $attendance['deduction'],
        ], array_filter(
            [
                'base_salary' => $overrides['baseSalary'] ?? null,
                'house_allowance' => $overrides['houseAllowance'] ?? null,
                'medical_allowance' => $overrides['medicalAllowance'] ?? null,
                'bonus' => $overrides['bonus'] ?? null,
                'tax_deduction' => $overrides['taxDeduction'] ?? null,
                'advance_deduction' => $overrides['advanceDeduction'] ?? null,
            ],
            fn ($value) => $value !== null,
        ));

        return SalarySlip::create(array_merge($components, [
            'employee_id' => $request->employee_id,
            'payroll_run_id' => $request->payroll_run_id,
            'salary_slip_request_id' => $request->id,
            'period' => $request->period->toDateString(),
            'net_pay' => $this->netPay($components),
            'notes' => $overrides['notes'] ?? null,
            'attendance_summary' => $attendance['summary'],
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
            'attendance_deduction' => 0.0,
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

    /**
     * A missing punch is not automatically unpaid leave: it may be a public
     * holiday or incomplete sync. Only explicit absent and half-day records
     * become a deduction; the stored summary makes the calculation auditable.
     *
     * @return array{deduction: float, summary: array<string, int|float|string>}
     */
    private function attendanceAdjustment(Employee $employee, Carbon $period, float $baseSalary): array
    {
        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();
        $records = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['status']);

        $absentDays = $records->where('status', 'absent')->count();
        $halfDays = $records->where('status', 'half_day')->count();
        $lateDays = $records->where('status', 'late')->count();
        $paidLeaveDays = $records->where('status', 'on_leave')->count();
        $unpaidDays = $absentDays + ($halfDays * 0.5);
        $dailyRate = round($baseSalary / max(1, $start->daysInMonth), 2);

        return [
            'deduction' => round($dailyRate * $unpaidDays, 2),
            'summary' => [
                'period' => $start->toDateString(),
                'absentDays' => $absentDays,
                'halfDays' => $halfDays,
                'lateDays' => $lateDays,
                'paidLeaveDays' => $paidLeaveDays,
                'unpaidDays' => $unpaidDays,
                'dailyRate' => $dailyRate,
                'rule' => 'Explicit absence and half-day records only',
            ],
        ];
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
     * HR prepares the run and records its payment. Super Admin is deliberately
     * excluded here: its role is the independent release step in approveSlip.
     */
    private function assertMayManagePay(User $user): void
    {
        if ($user->role !== 'hr' || ! $this->permissions->can($user, 'Payroll', 'create')) {
            throw new AccessDeniedHttpException('You may not manage payroll.');
        }
    }

    /** Super Admin is the independent authoriser of a submitted payroll run. */
    private function assertMayReleasePayroll(User $user): void
    {
        if ($user->role !== 'super_admin') {
            throw new AccessDeniedHttpException('You may not release payroll for payment.');
        }
    }

    /** HR prepares company payroll; Super Admin sees it to release it. */
    private function assertMaySeeCompanyPayroll(User $user): void
    {
        if ($user->role === 'hr' && $this->permissions->can($user, 'Payroll', 'view')) {
            return;
        }

        if ($user->role === 'super_admin'
            && $this->permissions->can($user, 'Payroll Approval', 'approve')) {
            return;
        }

        throw new AccessDeniedHttpException('You may not view company payroll.');
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
