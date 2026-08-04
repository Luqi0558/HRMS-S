<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LeaveService
{
    /** settings.leave key holding each type's annual allowance. */
    private const ALLOWANCE_KEYS = [
        'Annual leave' => 'annualDays',
        'Sick leave' => 'sickDays',
        'Casual leave' => 'casualDays',
        // Unpaid leave has no allowance by definition.
        'Unpaid leave' => null,
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly SettingsService $settings,
    ) {
    }

    public function list(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $employee = $this->employeeFor($user);
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Leave Request');

        if (! $row || ! ($row['view'] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        $query = LeaveRequest::with(['employee:id,name,department', 'approvals.approver:id,name']);

        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        if ($visibleIds !== null) {
            $query->whereIn('employee_id', $visibleIds);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['employee_id'] ?? null)) {
            $query->where('employee_id', $filters['employee_id']);
        }

        return $query->orderByDesc('from_date')->paginate($perPage);
    }

    public function create(User $user, array $attributes): LeaveRequest
    {
        $employee = $this->requireEmployee($user);

        $from = $attributes['fromDate'];
        $to = $attributes['toDate'];
        $days = (float) $attributes['days'];

        if ($to < $from) {
            throw new ConflictHttpException('The end date cannot be before the start date.');
        }

        // Half days only when the policy allows them.
        if (fmod($days, 1.0) !== 0.0 && ! $this->settings->get('leave', 'halfDayEnabled', false)) {
            throw new ConflictHttpException('Half-day leave is not enabled.');
        }

        // Overlapping requests would double-count against the balance and
        // leave two approvers deciding the same absence.
        $overlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('from_date', '<=', $to)
            ->where('to_date', '>=', $from)
            ->exists();

        if ($overlap) {
            throw new ConflictHttpException('You already have leave requested for those dates.');
        }

        $this->assertWithinAllowance($employee, $attributes['type'], $days);

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'type' => $attributes['type'],
            'from_date' => $from,
            'to_date' => $to,
            'days' => $days,
            'reason' => $attributes['reason'] ?? null,
            'status' => 'pending',
        ]);
    }

    /**
     * Records one stage's decision.
     *
     * With twoStepApproval on, a lead's approval alone leaves the request
     * pending until HR also signs off; a rejection at either stage is final.
     */
    public function decide(User $user, int $requestId, string $decision, ?string $comment = null): LeaveRequest
    {
        $request = LeaveRequest::find($requestId);

        if (! $request) {
            throw new NotFoundHttpException('Leave request not found.');
        }

        // Two modules, two stages. 'Leave Request'.approve is the HR-stage
        // right; 'Team Leaves'.approve is the lead stage. This mirrors
        // LeaveRequests.vue, which gates its two approval buttons the same way
        // — the stage follows the permission held, not the role name, so a
        // company that renames or reassigns roles keeps working.
        $permissions = collect($this->permissions->forUser($user));
        $hrRow = $permissions->firstWhere('module', 'Leave Request');
        $leadRow = $permissions->firstWhere('module', 'Team Leaves');

        $canReviewHr = (bool) ($hrRow['approve'] ?? false);
        $canReviewLead = (bool) ($leadRow['approve'] ?? false);

        if (! $canReviewHr && ! $canReviewLead) {
            throw new AccessDeniedHttpException('You may not approve leave.');
        }

        $approver = $this->employeeFor($user);

        // Nobody signs off their own absence. HR holds approve on every
        // request including their own, so without this an HR user could
        // approve their own leave outright — the frontend blocks the button,
        // but a button is not a permission.
        if ($approver && $approver->id === $request->employee_id) {
            throw new AccessDeniedHttpException('You cannot decide your own leave request.');
        }

        // Someone holding both (super_admin) acts at the escalation stage: it
        // is the one that settles a request nobody else can, such as HR's own
        // leave, where there is no lead above them.
        $isEscalation = $canReviewHr && $canReviewLead;
        $stage = $isEscalation ? 'escalation' : ($canReviewHr ? 'hr' : 'lead');
        $row = $canReviewHr ? $hrRow : $leadRow;

        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        if ($visibleIds !== null && ! in_array($request->employee_id, $visibleIds, true)) {
            throw new AccessDeniedHttpException('You may not approve this request.');
        }

        if ($request->status !== 'pending') {
            throw new ConflictHttpException('This request has already been decided.');
        }

        return DB::transaction(function () use ($request, $stage, $approver, $decision, $comment) {
            LeaveApproval::updateOrCreate(
                ['leave_request_id' => $request->id, 'stage' => $stage],
                [
                    'approver_id' => $approver?->id,
                    'decision' => $decision,
                    'comment' => $comment,
                ],
            );

            $request->update(['status' => $this->resolveStatus($request, $decision)]);

            return $request->refresh()->load('approvals.approver:id,name');
        });
    }

    public function cancel(User $user, int $requestId): LeaveRequest
    {
        $employee = $this->requireEmployee($user);
        $request = LeaveRequest::find($requestId);

        if (! $request || $request->employee_id !== $employee->id) {
            // 404 for someone else's request: a 403 would confirm it exists.
            throw new NotFoundHttpException('Leave request not found.');
        }

        if ($request->status !== 'pending') {
            throw new ConflictHttpException('Only a pending request can be cancelled.');
        }

        $request->update(['status' => 'cancelled']);

        return $request->refresh();
    }

    /** Days taken and remaining per type, for the balance widget. */
    public function balance(Employee $employee): array
    {
        $year = now()->year;

        $used = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereYear('from_date', $year)
            ->selectRaw('type, SUM(days) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $leave = $this->settings->group('leave');

        $balance = [];
        foreach (self::ALLOWANCE_KEYS as $type => $settingKey) {
            $allowed = $settingKey ? (float) ($leave[$settingKey] ?? 0) : null;
            $taken = (float) ($used[$type] ?? 0);

            $balance[$type] = [
                'allowed' => $allowed,
                'taken' => $taken,
                'remaining' => $allowed === null ? null : max(0, $allowed - $taken),
            ];
        }

        return $balance;
    }

    private function assertWithinAllowance(Employee $employee, string $type, float $days): void
    {
        $balance = $this->balance($employee)[$type] ?? null;

        // Unpaid leave has no ceiling.
        if (! $balance || $balance['allowed'] === null) {
            return;
        }

        if ($days > $balance['remaining']) {
            throw new ConflictHttpException(sprintf(
                'That exceeds your %s balance — %s of %s days remaining.',
                strtolower($type),
                rtrim(rtrim(number_format($balance['remaining'], 1), '0'), '.'),
                rtrim(rtrim(number_format($balance['allowed'], 1), '0'), '.'),
            ));
        }
    }

    /**
     * A rejection at any stage is final. An approval only completes the
     * request once every required stage has approved.
     */
    private function resolveStatus(LeaveRequest $request, string $decision): string
    {
        if ($decision === 'rejected') {
            return 'rejected';
        }

        if (! $this->settings->get('leave', 'twoStepApproval', false)) {
            return 'approved';
        }

        $approvedStages = LeaveApproval::where('leave_request_id', $request->id)
            ->where('decision', 'approved')
            ->pluck('stage')
            ->all();

        // An escalation approval settles the request on its own. It is the
        // only way to clear a request that has no lead above it — HR's own
        // leave, for instance — which would otherwise wait forever on a lead
        // stage nobody can record.
        if (in_array('escalation', $approvedStages, true)) {
            return 'approved';
        }

        // Only require the lead stage when someone can actually give it.
        $needsLead = $this->hasSupervisor($request->employee_id);
        $required = $needsLead ? ['lead', 'hr'] : ['hr'];

        return count(array_intersect($required, $approvedStages)) === count($required)
            ? 'approved'
            : 'pending';
    }

    /**
     * Whether anyone holds the lead stage over this employee.
     *
     * ScopeResolver derives a lead's reports from project and module
     * leadership, so an employee on no project — or HR, who nobody leads —
     * has no lead stage available. Requiring one there would strand the
     * request at pending permanently.
     */
    private function hasSupervisor(int $employeeId): bool
    {
        return Employee::query()
            ->whereHas('user', fn ($query) => $query->where('role', 'team_lead'))
            ->get()
            ->contains(fn (Employee $lead) => in_array(
                $employeeId,
                $this->scopes->managedEmployeeIds($lead),
                true,
            ) && $lead->id !== $employeeId);
    }

    private function requireEmployee(User $user): Employee
    {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can request leave.');
        }

        return $employee;
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
