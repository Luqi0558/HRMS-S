<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PettyCashExpense;
use App\Models\PettyCashReconciliation;
use App\Models\PettyCashTopUp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The petty cash tin.
 *
 * Two rules shape this service.
 *
 * The balance is derived, never stored: approved top-ups minus every expense
 * that was not rejected. A stored running total would drift the moment
 * anything was backdated or corrected, and the tin's whole purpose is that
 * the ledger and the cash agree.
 *
 * Spend above settings.pettyCash.approvalThreshold needs sign-off. That
 * threshold is read from settings rather than hardcoded, so changing it in
 * Settings changes what the API accepts — and it is checked here rather than
 * only in the form, because a threshold enforced in the browser is not
 * enforced at all.
 */
class PettyCashService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly SettingsService $settings,
        private readonly MessageService $messages,
    ) {
    }

    // --- reading ---------------------------------------------------------

    /**
     * Float, spend, balance and the low-balance flag.
     *
     * Rejected expenses are excluded: refusing a claim means the money is not
     * owed, so it never left the tin. A pending one still counts — the cash is
     * gone whether or not the paperwork has caught up.
     */
    public function summary(User $user): array
    {
        $this->assertMayView($user);

        $float = (float) PettyCashTopUp::where('status', 'approved')->sum('amount');
        $spent = (float) PettyCashExpense::where('status', '!=', 'rejected')->sum('amount');
        $balance = $float - $spent;

        $lowThreshold = (float) $this->settings->get('pettyCash', 'lowBalanceThreshold', 0);

        return [
            'float' => $float,
            'spent' => $spent,
            'balance' => $balance,
            'lowBalanceThreshold' => $lowThreshold,
            // Flagged rather than left to the client to work out, so the
            // warning appears the same way everywhere it is shown.
            'isLow' => $lowThreshold > 0 && $balance < $lowThreshold,
            'approvalThreshold' => (float) $this->settings->get('pettyCash', 'approvalThreshold', 0),
            'pendingTopUps' => PettyCashTopUp::where('status', 'pending')->count(),
            'pendingExpenses' => PettyCashExpense::where('status', 'pending')->count(),
        ];
    }

    public function topUps(User $user): array
    {
        $this->assertMayView($user);

        return PettyCashTopUp::with(['requester:id,name', 'approver:id,name'])
            ->orderByDesc('date')->orderByDesc('id')->get()->all();
    }

    public function expenses(User $user, array $filters = []): array
    {
        $this->assertMayView($user);

        $query = PettyCashExpense::with(['logger:id,name', 'approver:id,name']);

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        return $query->orderByDesc('date')->orderByDesc('id')->get()->all();
    }

    public function reconciliations(User $user): array
    {
        $this->assertMayView($user);

        return PettyCashReconciliation::with('counter:id,name')
            ->orderByDesc('date')->get()->all();
    }

    // --- top-ups ---------------------------------------------------------

    /**
     * Adds float to the tin.
     *
     * Someone who can approve records it as already approved — they are the
     * sign-off. Anyone else raises a request that waits for one.
     */
    public function requestTopUp(User $user, array $attributes): PettyCashTopUp
    {
        $this->assertMayRecord($user);

        $employee = $this->employeeFor($user);
        $approves = $this->permissions->can($user, 'Petty Cash', 'approve');

        $topUp = PettyCashTopUp::create([
            'amount' => (float) $attributes['amount'],
            'date' => $attributes['date'] ?? now()->toDateString(),
            'reference' => $attributes['reference'] ?? null,
            'note' => $attributes['note'] ?? null,
            'requested_by' => $employee?->id,
            'status' => $approves ? 'approved' : 'pending',
            'approved_by' => $approves ? $employee?->id : null,
            'approved_at' => $approves ? now() : null,
        ]);

        // A request nobody hears about waits forever; one that signed itself
        // off needs no-one.
        if ($topUp->status === 'pending') {
            $this->messages->notifyOthers(
                $user,
                $this->messages->employeeIdsForRoles(['super_admin']),
                [
                    'title' => 'Petty cash top-up requested',
                    'body' => sprintf('%s requested a %s top-up.', $employee?->name ?? 'Someone', number_format((float) $topUp->amount)),
                    'type' => 'finance',
                    'routeName' => 'petty-cash',
                ],
            );
        }

        return $topUp;
    }

    public function decideTopUp(User $user, int $id, string $decision): PettyCashTopUp
    {
        $this->assertMayApprove($user);

        $topUp = PettyCashTopUp::find($id);

        if (! $topUp) {
            throw new NotFoundHttpException('Top-up not found.');
        }

        if ($topUp->status !== 'pending') {
            throw new ConflictHttpException('This top-up has already been decided.');
        }

        $topUp->update([
            'status' => $decision,
            'approved_by' => $this->employeeFor($user)?->id,
            'approved_at' => now(),
        ]);

        if ($topUp->requested_by) {
            $this->messages->notify([$topUp->requested_by], [
                'title' => $decision === 'approved' ? 'Petty cash top-up approved' : 'Petty cash top-up rejected',
                'body' => sprintf('Your %s top-up request was %s.', number_format((float) $topUp->amount), $decision),
                'type' => 'finance',
                'routeName' => 'petty-cash',
            ]);
        }

        return $topUp->refresh()->load(['requester:id,name', 'approver:id,name']);
    }

    // --- expenses --------------------------------------------------------

    /**
     * Records money spent.
     *
     * Above the approval threshold the entry waits for sign-off; at or below
     * it, it is simply recorded. Either way the amount counts against the
     * balance immediately, because the cash has already left the tin.
     */
    public function recordExpense(User $user, array $attributes): PettyCashExpense
    {
        $this->assertMayRecord($user);

        $amount = (float) $attributes['amount'];

        // Spending more than the tin holds means the ledger no longer matches
        // reality — either the float is wrong or the entry is.
        $balance = $this->rawBalance();
        if ($amount > $balance) {
            throw new ConflictHttpException(sprintf(
                'That exceeds the petty cash balance of %s.',
                number_format($balance, 2),
            ));
        }

        $threshold = (float) $this->settings->get('pettyCash', 'approvalThreshold', 0);
        $needsApproval = $threshold > 0 && $amount > $threshold;

        // Someone who can approve does not queue for their own sign-off.
        $approves = $this->permissions->can($user, 'Petty Cash', 'approve');
        $employee = $this->employeeFor($user);

        $expense = PettyCashExpense::create([
            'amount' => $amount,
            'date' => $attributes['date'] ?? now()->toDateString(),
            'category' => $attributes['category'],
            'payee' => $attributes['payee'] ?? null,
            'description' => $attributes['description'] ?? null,
            'receipt_path' => $attributes['receiptPath'] ?? null,
            'logged_by' => $employee?->id,
            'status' => ($needsApproval && ! $approves) ? 'pending' : 'recorded',
            'approved_by' => ($needsApproval && $approves) ? $employee?->id : null,
            'approved_at' => ($needsApproval && $approves) ? now() : null,
        ]);

        // Only spend that is actually waiting on someone needs raising.
        if ($expense->status === 'pending') {
            $this->messages->notify(
                $this->messages->employeeIdsForRoles(['super_admin']),
                [
                    'title' => 'Petty cash needs approval',
                    'body' => sprintf('%s for %s is above the approval limit.', number_format($amount), $expense->category),
                    'type' => 'finance',
                    'routeName' => 'petty-cash',
                ],
            );
        }

        return $expense;
    }

    public function decideExpense(User $user, int $id, string $decision, ?string $comment = null): PettyCashExpense
    {
        $this->assertMayApprove($user);

        $expense = PettyCashExpense::find($id);

        if (! $expense) {
            throw new NotFoundHttpException('Expense not found.');
        }

        if ($expense->status !== 'pending') {
            throw new ConflictHttpException('This expense has already been decided.');
        }

        $expense->update([
            'status' => $decision === 'approved' ? 'approved' : 'rejected',
            'approved_by' => $this->employeeFor($user)?->id,
            'approved_at' => now(),
            'review_comment' => $comment,
        ]);

        if ($expense->logged_by) {
            $this->messages->notify([$expense->logged_by], [
                'title' => $decision === 'approved' ? 'Petty cash expense approved' : 'Petty cash expense rejected',
                'body' => sprintf('%s for %s was %s.', number_format((float) $expense->amount), $expense->category, $decision),
                'type' => 'finance',
                'routeName' => 'petty-cash',
            ]);
        }

        return $expense->refresh()->load(['logger:id,name', 'approver:id,name']);
    }

    // --- reconciliation --------------------------------------------------

    /**
     * Records a physical count.
     *
     * The book balance is captured at the moment of counting rather than
     * recomputed later: a backdated expense entered next week would change
     * today's arithmetic, and a past count must keep showing the variance
     * that was actually found.
     */
    public function reconcile(User $user, array $attributes): PettyCashReconciliation
    {
        $this->assertMayRecord($user);

        $counted = (float) $attributes['countedAmount'];
        $book = $this->rawBalance();

        $reconciliation = PettyCashReconciliation::create([
            'date' => $attributes['date'] ?? now()->toDateString(),
            'counted_amount' => $counted,
            'book_balance' => $book,
            // Negative means cash is missing; positive means there is more in
            // the tin than the ledger accounts for.
            'variance' => $counted - $book,
            'note' => $attributes['note'] ?? null,
            'counted_by' => $this->employeeFor($user)?->id,
        ]);

        // A mismatch means cash is missing or unrecorded — always escalate it.
        // Rounded to the cent so float noise in the subtraction is not a
        // variance.
        $variance = round($counted - $book, 2);

        if ($variance !== 0.0) {
            $this->messages->notifyOthers(
                $user,
                $this->messages->employeeIdsForRoles(['super_admin']),
                [
                    'title' => 'Petty cash variance found',
                    'body' => sprintf(
                        'Counted cash is %s %s the ledger.',
                        number_format(abs($variance), 2),
                        $variance < 0 ? 'short of' : 'over',
                    ),
                    'type' => 'finance',
                    'routeName' => 'petty-cash',
                ],
            );
        }

        return $reconciliation;
    }

    // --- internals -------------------------------------------------------

    /** The balance, without the permission check the summary performs. */
    private function rawBalance(): float
    {
        return (float) DB::table('petty_cash_top_ups')->where('status', 'approved')->sum('amount')
            - (float) DB::table('petty_cash_expenses')->where('status', '!=', 'rejected')->sum('amount');
    }

    private function assertMayView(User $user): void
    {
        if (! $this->permissions->can($user, 'Petty Cash', 'view')) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }
    }

    private function assertMayRecord(User $user): void
    {
        if (! $this->permissions->can($user, 'Petty Cash', 'create')) {
            throw new AccessDeniedHttpException('You may not record petty cash.');
        }
    }

    private function assertMayApprove(User $user): void
    {
        if (! $this->permissions->can($user, 'Petty Cash', 'approve')) {
            throw new AccessDeniedHttpException('You may not approve petty cash.');
        }
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
