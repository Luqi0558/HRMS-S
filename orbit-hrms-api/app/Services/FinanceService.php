<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ModulePayment;
use App\Models\ProjectModule;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Client payments against project modules.
 *
 * The table and model existed — ReportService reads them — but nothing could
 * write a row. The Finance page "logged" payments by pushing an object into
 * browser state, so every payment, and every commission derived from one,
 * vanished on refresh and was never seen by anyone else.
 *
 * Payments are their own endpoint rather than a field on the module resource.
 * Modules go to everyone who can view a project, developers included; what a
 * client has paid is finance information, gated on the Finance right.
 */
class FinanceService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly MessageService $messages,
        private readonly ActivityLogService $activity,
    ) {
    }

    /**
     * Payments on the modules this user may see the money for: a manager's
     * own projects, or everything for a company-wide grant.
     *
     * @return array<int, ModulePayment>
     */
    public function payments(User $user, array $filters = []): array
    {
        $visible = $this->visibleProjectIds($user);

        return ModulePayment::query()
            ->with(['loggedBy:id,name', 'module:id,project_id'])
            ->when($visible !== null, fn ($query) => $query->whereHas(
                'module',
                fn ($modules) => $modules->whereIn('project_id', $visible),
            ))
            ->when(filled($filters['moduleId'] ?? null), fn ($query) => $query->where('project_module_id', (int) $filters['moduleId']))
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function logPayment(User $user, int $moduleId, array $attributes): ModulePayment
    {
        $module = $this->moduleWithinScope($user, $moduleId);
        $logger = $this->employeeFor($user);

        $payment = ModulePayment::create([
            'project_module_id' => $module->id,
            'amount' => $attributes['amount'],
            'paid_on' => $attributes['date'],
            'method' => $attributes['method'] ?? null,
            'payer' => filled($attributes['payer'] ?? null) ? $attributes['payer'] : null,
            'reference' => filled($attributes['reference'] ?? null) ? $attributes['reference'] : null,
            'notes' => $attributes['notes'] ?? null,
            'logged_by' => $logger?->id,
        ]);

        $amount = number_format((float) $payment->amount, 2);
        $label = sprintf('%s / %s', $module->project?->name ?? 'Project', $module->name);

        $this->activity->record($user, [
            'module' => 'finance',
            'action' => 'payment logged',
            'details' => sprintf('%s received for %s via %s.', $amount, $label, $payment->method ?? 'unspecified method'),
            'targetId' => $module->id,
            'targetType' => 'module',
        ]);

        // Received money moves the commission the manager has earned, and is
        // the figure the super admin reconciles against the bank.
        $this->messages->notifyOthers(
            $user,
            [...$this->messages->employeeIdsForRoles(['super_admin']), $module->project?->manager_id],
            [
                'title' => 'Client payment logged',
                'body' => sprintf('%s was received for %s.', $amount, $label),
                'type' => 'finance',
                'routeName' => 'finance',
            ],
        );

        return $payment->load(['loggedBy:id,name', 'module:id,project_id']);
    }

    /**
     * Removes a payment logged in error.
     *
     * Soft-deleted, so the row survives for audit; and restricted to the
     * approve right, so a manager cannot quietly delete the payments their
     * own commission is calculated from.
     */
    public function deletePayment(User $user, int $paymentId): void
    {
        if (! $this->permissions->can($user, 'Finance', 'approve')) {
            throw new AccessDeniedHttpException('You may not remove payments.');
        }

        $payment = ModulePayment::with('module.project:id,name')->find($paymentId);

        if (! $payment) {
            throw new NotFoundHttpException('Payment not found.');
        }

        $payment->delete();

        $this->activity->record($user, [
            'module' => 'finance',
            'action' => 'payment removed',
            'details' => sprintf(
                '%s logged on %s for %s / %s was removed.',
                number_format((float) $payment->amount, 2),
                $payment->paid_on?->toDateString(),
                $payment->module?->project?->name ?? 'Project',
                $payment->module?->name ?? 'module',
            ),
            'targetId' => $payment->project_module_id,
            'targetType' => 'module',
        ]);
    }

    /** Null means every project; an array limits to those ids. */
    private function visibleProjectIds(User $user): ?array
    {
        if (! $this->permissions->can($user, 'Finance', 'finance')) {
            throw new AccessDeniedHttpException('You do not have access to finance.');
        }

        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Finance');

        // A super admin may have no Finance row in an older grid; can() above
        // already admitted them through the blanket grant, which is 'all'.
        return $this->scopes->visibleProjectIds($user, $row['scope'] ?? 'all');
    }

    private function moduleWithinScope(User $user, int $moduleId): ProjectModule
    {
        $visible = $this->visibleProjectIds($user);
        $module = ProjectModule::with('project:id,name,manager_id')->find($moduleId);

        // 404 for both: a 403 would confirm the module exists.
        if (! $module || ($visible !== null && ! in_array($module->project_id, $visible, true))) {
            throw new NotFoundHttpException('Module not found.');
        }

        return $module;
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
