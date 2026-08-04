<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\MedicalClaim;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MedicalClaimService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly SettingsService $settings,
    ) {
    }

    public function list(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Medical Request');

        if (! $row || ! ($row['view'] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        $query = MedicalClaim::with(['employee:id,name,department', 'reviewer:id,name']);

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

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function create(User $user, array $attributes): MedicalClaim
    {
        $employee = $this->requireEmployee($user);
        $amount = (float) $attributes['amount'];

        $claims = $this->settings->group('claims');

        // Receipts above the threshold are a policy requirement, enforced here
        // rather than only in the form — money leaving the company cannot
        // depend on a client-side check.
        $threshold = (float) ($claims['receiptRequiredAbove'] ?? 0);
        if ($threshold > 0 && $amount > $threshold && blank($attributes['receiptPath'] ?? null)) {
            throw new ConflictHttpException(sprintf(
                'A receipt is required for claims above %s.',
                number_format($threshold),
            ));
        }

        $this->assertWithinAnnualLimit($employee, $amount, $claims);

        return MedicalClaim::create([
            'employee_id' => $employee->id,
            'type' => $attributes['type'],
            'amount' => $amount,
            'description' => $attributes['description'] ?? null,
            'incurred_on' => $attributes['incurredOn'] ?? now()->toDateString(),
            'receipt_path' => $attributes['receiptPath'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function decide(
        User $user,
        int $claimId,
        string $decision,
        ?float $approvedAmount = null,
        ?string $comment = null
    ): MedicalClaim {
        $claim = MedicalClaim::find($claimId);

        if (! $claim) {
            throw new NotFoundHttpException('Claim not found.');
        }

        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Medical Request');

        if (! $row || ! ($row['approve'] ?? false)) {
            throw new AccessDeniedHttpException('You may not review claims.');
        }

        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        if ($visibleIds !== null && ! in_array($claim->employee_id, $visibleIds, true)) {
            throw new AccessDeniedHttpException('You may not review this claim.');
        }

        if ($claim->status !== 'pending') {
            throw new ConflictHttpException('This claim has already been reviewed.');
        }

        // A partial approval settles less than claimed; without an explicit
        // amount the full claim is authorised.
        $authorised = $decision === 'approved'
            ? ($approvedAmount ?? (float) $claim->amount)
            : null;

        if ($authorised !== null && $authorised > (float) $claim->amount) {
            throw new ConflictHttpException('The approved amount cannot exceed the claim.');
        }

        $reviewer = $this->employeeFor($user);

        $claim->update([
            'status' => $decision,
            'approved_amount' => $authorised,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
            'review_comment' => $comment,
        ]);

        return $claim->refresh()->load('reviewer:id,name');
    }

    /**
     * A claim whose receipt this user may read.
     *
     * The owner always may; anyone else needs Medical Request.view and the
     * claim within their scope. 404 rather than 403 for a claim they cannot
     * see — a 403 would confirm it exists.
     */
    public function findForReceipt(User $user, int $claimId): MedicalClaim
    {
        $claim = MedicalClaim::find($claimId);

        if (! $claim || blank($claim->receipt_path)) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        $employee = $this->employeeFor($user);

        if ($employee && $claim->employee_id === $employee->id) {
            return $claim;
        }

        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Medical Request');

        if (! $row || ! ($row['view'] ?? false)) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        if ($visibleIds !== null && ! in_array($claim->employee_id, $visibleIds, true)) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        return $claim;
    }

    /** Claimed and remaining for the year, for the allowance widget. */
    public function annualUsage(Employee $employee): array
    {
        $limit = (float) $this->settings->get('claims', 'annualMedicalLimit', 0);

        // Counts approved and paid claims by the year the cost was incurred,
        // not when it was filed — a December treatment claimed in January
        // belongs to December's allowance.
        //
        // COALESCE because a partial approval settles less than claimed, and
        // it is the authorised figure that consumes the allowance.
        $used = (float) MedicalClaim::where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'paid'])
            ->whereYear('incurred_on', now()->year)
            ->sum(DB::raw('COALESCE(approved_amount, amount)'));

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
        ];
    }

    private function assertWithinAnnualLimit(Employee $employee, float $amount, array $claims): void
    {
        $limit = (float) ($claims['annualMedicalLimit'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $usage = $this->annualUsage($employee);

        if ($amount > $usage['remaining']) {
            throw new ConflictHttpException(sprintf(
                'That exceeds your annual medical allowance — %s of %s remaining.',
                number_format($usage['remaining']),
                number_format($limit),
            ));
        }
    }

    private function requireEmployee(User $user): Employee
    {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can file claims.');
        }

        return $employee;
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
