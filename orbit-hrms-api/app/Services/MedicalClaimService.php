<?php

namespace App\Services;

use App\Mail\MedicalClaimSubmittedMail;
use App\Models\Employee;
use App\Models\MedicalClaim;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MedicalClaimService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly SettingsService $settings,
        private readonly MessageService $messages,
    ) {
    }

    public function list(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Medical Request');

        if (! $row || ! ($row['view'] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        $query = MedicalClaim::with(['employee:id,name,department', 'reviewer:id,name', 'viewer:id,name', 'processor:id,name']);

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
        $permission = collect($this->permissions->forUser($user))->firstWhere('module', 'Medical Request');
        if (! $permission || ! ($permission['create'] ?? false)) {
            throw new AccessDeniedHttpException('You may not submit a medical claim.');
        }

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

        $claim = MedicalClaim::create([
            'employee_id' => $employee->id,
            'type' => $attributes['type'],
            'amount' => $amount,
            'description' => $attributes['description'] ?? null,
            'incurred_on' => $attributes['incurredOn'] ?? now()->toDateString(),
            'receipt_path' => $attributes['receiptPath'] ?? null,
            'status' => 'pending',
        ]);

        $usage = $this->annualUsage($employee);
        // HR may claim its own reimbursement, but must not receive or act on
        // its own approval request. Those claims are an escalation for Super
        // Admin. Everyone else's claims remain visible to HR and Super Admin.
        $isHrOwnClaim = $user->role === 'hr';
        $recipientIds = $this->messages->employeeIdsForRoles(
            $isHrOwnClaim ? ['super_admin'] : ['hr', 'super_admin']
        );

        $this->messages->notify(
            $recipientIds,
            [
                'title' => $isHrOwnClaim ? 'HR medical claim submitted' : 'Medical claim submitted',
                'body' => sprintf('%s claimed %s for %s.', $employee->name, number_format($amount), $claim->type),
                'type' => 'medical',
                'routeName' => 'medical-claims',
            ],
        );

        $this->queueMedicalClaimEmails($recipientIds, $claim, $employee, $usage);

        return $claim;
    }

    public function decide(
        User $user,
        int $claimId,
        string $decision,
        ?float $approvedAmount = null,
        ?string $comment = null
    ): MedicalClaim {
        $claim = $this->claimUnderReview($user, $claimId);

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

        // A partial approval is the case worth spelling out — being told
        // "approved" when less than the claim was authorised is misleading.
        $settled = $authorised !== null && $authorised < (float) $claim->amount;

        $this->messages->notify([$claim->employee_id], [
            'title' => $decision === 'approved' ? 'Medical claim approved' : 'Medical claim rejected',
            'body' => $settled
                ? sprintf('%s of your %s claim was approved.', number_format($authorised), number_format((float) $claim->amount))
                : sprintf('Your %s claim was %s.', $claim->type, $decision),
            'type' => 'medical',
            'routeName' => 'medical-claims',
        ]);

        return $claim->refresh()->load(['reviewer:id,name', 'viewer:id,name', 'processor:id,name']);
    }

    /**
     * Records a review step short of a decision: the claim has been opened
     * ('view'), or is being processed ('processing').
     *
     * The same checks as a decision apply - whoever may not decide a claim
     * may not mark it in progress either. Steps only move forward, and
     * jumping straight to processing records the view too, so the timeline
     * never shows processing without someone having opened it.
     */
    public function advanceStage(User $user, int $claimId, string $stage): MedicalClaim
    {
        $claim = $this->claimUnderReview($user, $claimId);
        $reviewer = $this->employeeFor($user);

        if ($stage === 'view' && $claim->viewed_at) {
            throw new ConflictHttpException('This claim is already under review.');
        }

        if ($stage === 'processing' && $claim->processing_at) {
            throw new ConflictHttpException('This claim is already being processed.');
        }

        $now = now();
        $changes = $claim->viewed_at ? [] : ['viewed_at' => $now, 'viewed_by' => $reviewer?->id];

        if ($stage === 'processing') {
            $changes += ['processing_at' => $now, 'processing_by' => $reviewer?->id];
        }

        $claim->update($changes);

        // Processing is the step an employee wants to hear about: someone is
        // acting on their money. Merely opening it is not worth a ping.
        if ($stage === 'processing') {
            $this->messages->notify([$claim->employee_id], [
                'title' => 'Medical claim in processing',
                'body' => sprintf('Your %s claim for %s is being processed.', $claim->type, number_format((float) $claim->amount)),
                'type' => 'medical',
                'routeName' => 'medical-claims',
            ]);
        }

        return $claim->refresh()->load(['reviewer:id,name', 'viewer:id,name', 'processor:id,name']);
    }

    /**
     * A pending claim this user may act on as a reviewer.
     *
     * Permission, scope, and the rule that nobody reviews their own claim -
     * shared by every step of review so none of them can drift apart.
     */
    private function claimUnderReview(User $user, int $claimId): MedicalClaim
    {
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

        $reviewer = $this->employeeFor($user);

        if ($reviewer && $reviewer->id === $claim->employee_id) {
            throw new AccessDeniedHttpException('You cannot review your own medical claim. It requires Super Admin approval.');
        }

        return $claim;
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

    /**
     * Email each approver privately. The annual figures are calculated from
     * approved/paid claims only: a pending request does not consume allowance
     * until someone authorises it.
     *
     * @param array{limit: float, used: float, remaining: float} $usage
     */
    private function queueMedicalClaimEmails(array $recipientIds, MedicalClaim $claim, Employee $requester, array $usage): void
    {
        $currency = (string) $this->settings->get('company', 'currency', 'PKR');
        $format = fn (float $amount): string => sprintf('%s %s', $currency, number_format($amount, 2));
        $reviewUrl = rtrim((string) config('app.frontend_url'), '/').'/medical-claims';
        $remainingIfApproved = max(0, $usage['remaining'] - (float) $claim->amount);

        Employee::query()
            ->with('user:id,email')
            ->whereIn('id', $recipientIds)
            ->get()
            ->each(function (Employee $recipient) use ($claim, $requester, $usage, $format, $reviewUrl, $remainingIfApproved): void {
                $email = $recipient->user?->email ?? $recipient->email;
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return;
                }

                try {
                    Mail::to($email)->queue(new MedicalClaimSubmittedMail(
                        requesterName: $requester->name,
                        claimType: $claim->type,
                        incurredOn: $claim->incurred_on->toDateString(),
                        claimAmount: $format((float) $claim->amount),
                        description: $claim->description,
                        annualAllowance: $format($usage['limit']),
                        usedThisYear: $format($usage['used']),
                        remainingBeforeClaim: $format($usage['remaining']),
                        remainingIfApproved: $format($remainingIfApproved),
                        hasReceipt: filled($claim->receipt_path),
                        reviewUrl: $reviewUrl,
                    ));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
    }
}
