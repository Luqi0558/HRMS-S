<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\MedicalClaim;
use App\Models\ModulePayment;
use App\Models\Policy;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authoritative operational reporting. The browser receives already scoped
 * rows and never decides which company records it may include in an export.
 */
class ReportService
{
    public const TYPES = ['attendance', 'leaves', 'claims', 'employees', 'assets', 'policies', 'projects', 'finance'];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly ActivityLogService $activity,
    ) {
    }

    /** @return array{title:string, rows:array<int,array<string,mixed>>} */
    public function generate(User $user, string $type, array $filters = []): array
    {
        if (! $this->permissions->can($user, 'Reports', 'view')) {
            throw new AccessDeniedHttpException('You do not have permission to run reports.');
        }

        $rows = match ($type) {
            'attendance' => $this->attendance($user, $filters),
            'leaves' => $this->leaves($user, $filters),
            'claims' => $this->claims($user, $filters),
            'employees' => $this->employees($user, $filters),
            'assets' => $this->assets($user, $filters),
            'policies' => $this->policies($user, $filters),
            'projects' => $this->projects($user, $filters),
            'finance' => $this->finance($user, $filters),
            default => throw new AccessDeniedHttpException('That report is not available.'),
        };

        return ['title' => ucfirst($type).' report', 'rows' => $this->filterRows($rows, $filters)];
    }

    public function recordExport(User $user, string $type, int $count): void
    {
        $this->activity->record($user, [
            'module' => 'reports',
            'action' => 'report exported',
            'details' => sprintf('%s report exported with %d row(s).', $type, $count),
            'targetType' => 'report',
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function attendance(User $user, array $filters): array
    {
        $ids = $this->employeeIds($user, 'Attendance', 'view');
        return Attendance::with('employee:id,name')->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->when($filters['dateFrom'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
            ->when($filters['dateTo'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date))
            ->orderByDesc('date')->get()->map(fn ($row) => [
                'employee' => $row->employee?->name ?? '—', 'date' => $row->date?->toDateString(),
                'checkIn' => $row->check_in ?? '—', 'checkOut' => $row->check_out ?? '—',
                'status' => $row->status, 'device' => $row->device ?? '—',
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function leaves(User $user, array $filters): array
    {
        $ids = $this->employeeIds($user, 'Leave Request', 'approve');
        return LeaveRequest::with('employee:id,name')->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->when($filters['dateFrom'] ?? null, fn ($q, $date) => $q->whereDate('from_date', '>=', $date))
            ->when($filters['dateTo'] ?? null, fn ($q, $date) => $q->whereDate('from_date', '<=', $date))
            ->orderByDesc('from_date')->get()->map(fn ($row) => [
                'employee' => $row->employee?->name ?? '—', 'type' => $row->type,
                'from' => $row->from_date?->toDateString(), 'to' => $row->to_date?->toDateString(),
                'duration' => (string) $row->days, 'status' => $row->status,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function claims(User $user, array $filters): array
    {
        $ids = $this->employeeIds($user, 'Medical Request', 'approve');
        return MedicalClaim::with('employee:id,name')->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->when($filters['dateFrom'] ?? null, fn ($q, $date) => $q->whereDate('incurred_on', '>=', $date))
            ->when($filters['dateTo'] ?? null, fn ($q, $date) => $q->whereDate('incurred_on', '<=', $date))
            ->orderByDesc('incurred_on')->get()->map(fn ($row) => [
                'employee' => $row->employee?->name ?? '—', 'type' => $row->type,
                'amount' => (string) $row->amount, 'submittedAt' => $row->incurred_on?->toDateString(), 'status' => $row->status,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function employees(User $user, array $filters): array
    {
        $ids = $this->employeeIds($user, 'Employees', 'view');
        return Employee::with('user:id,role')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')->get()->map(fn ($row) => [
                'name' => $row->name, 'email' => $row->email, 'designation' => $row->designation ?? '—',
                'role' => $row->user?->role ?? 'employee', 'status' => $row->status,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function assets(User $user, array $filters): array
    {
        $ids = $this->employeeIds($user, 'Assets', 'view');
        return Asset::with('holder:id,name')->when($ids !== null, fn ($q) => $q->whereIn('assigned_to', $ids))
            ->orderBy('name')->get()->map(fn ($row) => [
                'asset' => $row->name, 'category' => $row->category, 'serial' => $row->serial_number ?? '—',
                'assignedTo' => $row->holder?->name ?? '—', 'condition' => $row->condition, 'status' => $row->status,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function policies(User $user, array $filters): array
    {
        $this->assertPermission($user, 'Company Hub', 'create');
        return Policy::orderByDesc('updated_at')->get()->map(fn ($row) => [
            'policy' => $row->title, 'category' => $row->category, 'version' => $row->version,
            'updatedAt' => $row->updated_at?->toDateString(), 'acknowledgment' => $row->acknowledgment_required ? 'Required' : 'Optional',
        ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function projects(User $user, array $filters): array
    {
        $scope = $this->scopeFor($user, 'Projects', 'view');
        $ids = $this->scopes->visibleProjectIds($user, $scope);
        return Project::with(['manager:id,name', 'members:id', 'tickets:id,project_id,status'])
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get()
            ->map(fn ($row) => [
                'project' => $row->name, 'manager' => $row->manager?->name ?? '—', 'teamSize' => $row->members->count(),
                'tickets' => $row->tickets->count(), 'completed' => $row->tickets->where('status', 'done')->count(), 'status' => $row->status,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function finance(User $user, array $filters): array
    {
        $scope = $this->scopeFor($user, 'Finance', 'finance');
        $projectIds = $this->scopes->visibleProjectIds($user, $scope);
        return ProjectModule::with(['project.manager:id,name', 'payments:id,project_module_id,amount'])
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))->orderBy('name')->get()
            ->map(function ($row) {
                $received = (float) $row->payments->sum(fn ($payment) => (float) $payment->amount);
                $budget = (float) $row->budget;
                return [
                    'project' => $row->name, 'category' => $row->project?->name ?? '—', 'manager' => $row->project?->manager?->name ?? '—',
                    'budget' => $budget, 'received' => $received, 'pending' => max(0, $budget - $received),
                    'commission' => (float) $row->commission_value, 'status' => $row->commission_status ?? 'pending',
                ];
            })->all();
    }

    /** @return array<int,int>|null */
    private function employeeIds(User $user, string $module, string $action): ?array
    {
        return $this->scopes->visibleEmployeeIds($user, $this->scopeFor($user, $module, $action));
    }

    private function scopeFor(User $user, string $module, string $action): string
    {
        $this->assertPermission($user, $module, $action);
        $row = collect($this->permissions->forUser($user))->firstWhere('module', $module);
        return $row['scope'] ?? 'all';
    }

    private function assertPermission(User $user, string $module, string $action): void
    {
        if (! $this->permissions->can($user, $module, $action)) {
            throw new AccessDeniedHttpException('You do not have permission to run this report.');
        }
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function filterRows(array $rows, array $filters): array
    {
        $query = mb_strtolower(trim((string) ($filters['query'] ?? '')));
        return array_values(array_filter($rows, function (array $row) use ($filters, $query) {
            $person = $row['employee'] ?? $row['name'] ?? $row['manager'] ?? $row['assignedTo'] ?? '';
            if (($filters['employee'] ?? null) && $filters['employee'] !== 'all' && $person !== $filters['employee']) return false;
            if (($filters['status'] ?? null) && $filters['status'] !== 'all' && ($row['status'] ?? '') !== $filters['status']) return false;
            if ($query && ! str_contains(mb_strtolower(implode(' ', array_map('strval', $row))), $query)) return false;
            return true;
        }));
    }
}
