<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\EffectivenessSnapshot;
use App\Models\EffectivenessSnapshotCycle;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketTimeLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Read model for transparent, informational effectiveness reporting.
 *
 * Scores never feed payroll, disciplinary action, or appraisals. Every score
 * includes its source counts so a person can understand and challenge it.
 */
class EffectivenessService
{
    public const POLICY_VERSION = 'v1.0';

    /** @return array{start: Carbon, end: Carbon} */
    public function range(string $range = '30', ?string $from = null, ?string $to = null): array
    {
        if ($from && $to) {
            return [
                'start' => Carbon::parse($from)->startOfDay(),
                'end' => Carbon::parse($to)->endOfDay(),
            ];
        }

        $today = now()->startOfDay();

        return match ($range) {
            'month' => ['start' => $today->copy()->startOfMonth(), 'end' => $today],
            '90' => ['start' => $today->copy()->subDays(89), 'end' => $today],
            default => ['start' => $today->copy()->subDays(29), 'end' => $today],
        };
    }

    /** @return array<string, mixed> */
    public function personal(User $user, string $range = '30', ?string $from = null, ?string $to = null): array
    {
        $employee = $user->employee;
        if (! $employee) {
            throw new AccessDeniedHttpException('Your account is not linked to an employee record.');
        }

        $period = $this->range($range, $from, $to);
        return $this->calculate($employee, $period['start'], $period['end']);
    }

    /** @return array<string, mixed> */
    public function workforce(User $user, string $range = '30', ?string $from = null, ?string $to = null): array
    {
        if ($user->role !== 'super_admin') {
            throw new AccessDeniedHttpException('Only Super Admin may view workforce effectiveness.');
        }

        $period = $this->range($range, $from, $to);
        $rows = Employee::query()
            ->with('user:id,role')
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('role', '!=', 'super_admin'))
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $employee) => $this->calculate($employee, $period['start'], $period['end']));

        $scored = $rows->filter(fn (array $row) => $row['score'] !== null)->values();
        $metrics = collect(['delivery', 'quality', 'traceability', 'attendance'])->map(function (string $id) use ($scored) {
            $available = $scored->pluck('metrics')->map(fn (array $metrics) => collect($metrics)->firstWhere('id', $id))
                ->filter(fn (?array $metric) => $metric && $metric['available'] && $metric['score'] !== null)
                ->values();

            return [
                'id' => $id,
                'label' => match ($id) {
                    'delivery' => 'On-time delivery',
                    'quality' => 'Quality signal',
                    'traceability' => 'Work traceability',
                    default => 'Attendance coverage',
                },
                'score' => $available->isNotEmpty() ? round((float) $available->avg('score'), 1) : null,
                'available' => $available->isNotEmpty(),
                'detail' => $available->isNotEmpty()
                    ? $available->count().' employee record(s) with measurable evidence'
                    : 'No measurable evidence in this period',
            ];
        })->values()->all();

        $departments = $scored->groupBy(fn (array $row) => $row['employee']['department'] ?: 'Unassigned')
            ->map(fn ($departmentRows, string $department) => [
                'department' => $department,
                'score' => round((float) $departmentRows->avg('score'), 1),
                'employeesWithEvidence' => $departmentRows->count(),
            ])
            ->sortByDesc('score')->values()->all();

        return [
            'scope' => 'workforce',
            'score' => $scored->isNotEmpty() ? round((float) $scored->avg('score'), 1) : null,
            'confidence' => $this->confidence($scored->count()),
            'period' => $this->period($period['start'], $period['end']),
            'metrics' => $metrics,
            'evidence' => [
                'activeEmployees' => $rows->count(),
                'employeesWithEvidence' => $scored->count(),
                'employeesWithoutEvidence' => $rows->count() - $scored->count(),
            ],
            'departments' => $departments,
            'policyVersion' => self::POLICY_VERSION,
            'informationalOnly' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function calculate(Employee $employee, Carbon $start, Carbon $end): array
    {
        $completed = Ticket::query()
            ->where('assignee_id', $employee->id)
            ->where('status', 'done')
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['id', 'deadline', 'completed_at']);
        $deadlineAssessed = $completed->filter(fn (Ticket $ticket) => $ticket->deadline !== null);
        $onTime = $deadlineAssessed->filter(fn (Ticket $ticket) => $ticket->completed_at->lte($ticket->deadline->copy()->endOfDay()));

        $attendance = Attendance::query()
            ->with('sessions:id,attendance_id,check_in,check_out')
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();
        $attendanceEvidence = $attendance->where('status', '!=', 'on_leave')->values();
        $attendanceSeconds = (int) $attendanceEvidence->sum(fn (Attendance $row) => $row->workedSeconds() ?? 0);
        $loggedSeconds = (int) TicketTimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->sum('duration_seconds');

        $statusWeights = ['present' => 100, 'late' => 80, 'half_day' => 50, 'absent' => 0];
        $attendanceScores = $attendanceEvidence
            ->filter(fn (Attendance $row) => array_key_exists($row->status, $statusWeights))
            ->map(fn (Attendance $row) => $statusWeights[$row->status]);

        $metrics = [
            $this->metric('delivery', 'Delivery reliability',
                $deadlineAssessed->isNotEmpty() ? round(100 * $onTime->count() / $deadlineAssessed->count(), 1) : null,
                $deadlineAssessed->isNotEmpty(),
                $deadlineAssessed->isNotEmpty() ? 'Completed work that met its agreed deadline' : 'No completed tickets with a deadline in this period',
                ['completedTickets' => $completed->count(), 'deadlineAssessed' => $deadlineAssessed->count(), 'onTime' => $onTime->count()]),
            // Returned-work events are not yet modelled. A neutral invented
            // score would be misleading, so quality remains unavailable until
            // that audit trail exists.
            $this->metric('quality', 'Quality and rework', null, false,
                'Available after returned-work and acceptance events are recorded', ['returnedWorkEvents' => null]),
            $this->metric('traceability', 'Work traceability',
                $attendanceSeconds > 0 ? round(min(100, 100 * $loggedSeconds / $attendanceSeconds), 1) : null,
                $attendanceSeconds > 0,
                $attendanceSeconds > 0 ? 'Finished ticket time compared with checked-in work time' : 'No completed checked-in work time in this period',
                ['loggedSeconds' => $loggedSeconds, 'attendanceSeconds' => $attendanceSeconds]),
            $this->metric('attendance', 'Attendance reliability',
                $attendanceScores->isNotEmpty() ? round((float) $attendanceScores->avg(), 1) : null,
                $attendanceScores->isNotEmpty(),
                'Approved leave is excluded from attendance scoring',
                ['attendanceDays' => $attendanceScores->count(), 'approvedLeaveDaysExcluded' => $attendance->where('status', 'on_leave')->count()]),
        ];

        $weights = $this->weightsFor($employee->user?->role ?? 'employee');
        $availableWeight = 0.0;
        $weightedScore = 0.0;
        foreach ($metrics as $metric) {
            if (! $metric['available']) continue;
            $weight = $weights[$metric['id']] ?? 0.0;
            $availableWeight += $weight;
            $weightedScore += $metric['score'] * $weight;
        }

        $score = $availableWeight > 0 ? round($weightedScore / $availableWeight, 1) : null;

        return [
            'scope' => 'personal',
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'department' => $employee->department],
            'score' => $score,
            'confidence' => $this->confidence($this->evidenceCount($metrics)),
            'period' => $this->period($start, $end),
            'metrics' => $metrics,
            'evidence' => ['availableMetrics' => $this->evidenceCount($metrics), 'weightsUsed' => $weights],
            'policyVersion' => self::POLICY_VERSION,
            'informationalOnly' => true,
        ];
    }

    /** Persist a period for audit after HR has reviewed the policy. */
    public function snapshot(Employee $employee, Carbon $start, Carbon $end, bool $finalize = false): EffectivenessSnapshot
    {
        $calculation = $this->calculate($employee, $start, $end);
        $existing = EffectivenessSnapshot::query()->where('employee_id', $employee->id)
            ->whereDate('period_start', $start)->whereDate('period_end', $end)->first();

        if ($existing?->finalized_at) return $existing;

        return EffectivenessSnapshot::updateOrCreate(
            ['employee_id' => $employee->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()],
            [
                'role' => $employee->user?->role ?? 'employee', 'department' => $employee->department,
                'score' => $calculation['score'], 'confidence' => $calculation['confidence'],
                'metrics' => $calculation['metrics'], 'evidence' => $calculation['evidence'],
                'policy_version' => self::POLICY_VERSION, 'generated_at' => now(),
                'finalized_at' => $finalize ? now() : null,
            ],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function history(User $user, string $scope, int $months = 6): array
    {
        $query = EffectivenessSnapshot::query()->whereNotNull('finalized_at')
            ->whereDate('period_end', '<=', now()->toDateString())
            ->orderByDesc('period_start')->limit($months);

        if ($scope === 'workforce') {
            if ($user->role !== 'super_admin') {
                throw new AccessDeniedHttpException('Only Super Admin may view workforce effectiveness history.');
            }

            return $query->get()->groupBy(fn (EffectivenessSnapshot $snapshot) => $snapshot->period_start->toDateString())
                ->map(function ($snapshots, string $periodStart) {
                    return [
                        'periodStart' => $periodStart,
                        'periodEnd' => $snapshots->first()->period_end->toDateString(),
                        'score' => $snapshots->whereNotNull('score')->isNotEmpty()
                            ? round((float) $snapshots->whereNotNull('score')->avg('score'), 1) : null,
                        'employeesWithEvidence' => $snapshots->whereNotNull('score')->count(),
                    ];
                })->sortBy('periodStart')->values()->all();
        }

        $employee = $user->employee;
        if (! $employee) {
            throw new AccessDeniedHttpException('Your account is not linked to an employee record.');
        }

        return $query->where('employee_id', $employee->id)->get()->sortBy('period_start')
            ->map(fn (EffectivenessSnapshot $snapshot) => [
                'periodStart' => $snapshot->period_start->toDateString(),
                'periodEnd' => $snapshot->period_end->toDateString(),
                'score' => $snapshot->score,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function cycles(User $user): array
    {
        $this->assertMayReview($user);

        return EffectivenessSnapshotCycle::query()->with(['reviewer:id,name', 'finalizer:id,name'])
            ->orderByDesc('period_start')->limit(12)->get()->map(fn (EffectivenessSnapshotCycle $cycle) => [
                'id' => $cycle->id,
                'periodStart' => $cycle->period_start->toDateString(),
                'periodEnd' => $cycle->period_end->toDateString(),
                'status' => $cycle->status,
                'policyVersion' => $cycle->policy_version,
                'reviewedAt' => $cycle->reviewed_at?->toIso8601String(),
                'reviewedBy' => $cycle->reviewer?->name,
                'reviewNote' => $cycle->review_note,
                'finalizedAt' => $cycle->finalized_at?->toIso8601String(),
                'finalizedBy' => $cycle->finalizer?->name,
            ])->all();
    }

    public function review(User $user, int $cycleId, ?string $note): EffectivenessSnapshotCycle
    {
        $this->assertMayReview($user);
        $cycle = EffectivenessSnapshotCycle::query()->findOrFail($cycleId);
        if ($cycle->status === 'finalized') {
            throw new AccessDeniedHttpException('A finalized effectiveness period cannot be reviewed again.');
        }

        $cycle->update([
            'status' => 'reviewed', 'reviewed_by' => $user->employee?->id,
            'reviewed_at' => now(), 'review_note' => $note,
        ]);

        return $cycle->fresh();
    }

    public function finalize(User $user, int $cycleId): EffectivenessSnapshotCycle
    {
        if ($user->role !== 'super_admin') {
            throw new AccessDeniedHttpException('Only Super Admin may finalize an effectiveness period.');
        }

        return DB::transaction(function () use ($user, $cycleId) {
            $cycle = EffectivenessSnapshotCycle::query()->lockForUpdate()->findOrFail($cycleId);
            if ($cycle->status !== 'reviewed') {
                throw new AccessDeniedHttpException('HR review is required before finalization.');
            }

            $finalizedAt = now();
            EffectivenessSnapshot::query()->whereDate('period_start', $cycle->period_start)
                ->whereDate('period_end', $cycle->period_end)->update(['finalized_at' => $finalizedAt]);
            $cycle->update([
                'status' => 'finalized', 'finalized_by' => $user->employee?->id,
                'finalized_at' => $finalizedAt,
            ]);

            return $cycle->fresh();
        });
    }

    /** @return array<string, float> */
    private function weightsFor(string $role): array
    {
        return match ($role) {
            'hr' => ['delivery' => 0.30, 'quality' => 0.20, 'traceability' => 0.20, 'attendance' => 0.30],
            default => ['delivery' => 0.45, 'quality' => 0.20, 'traceability' => 0.20, 'attendance' => 0.15],
        };
    }

    /** @return array<string, mixed> */
    private function metric(string $id, string $label, ?float $score, bool $available, string $detail, array $evidence): array
    {
        return compact('id', 'label', 'score', 'available', 'detail', 'evidence');
    }

    private function evidenceCount(array $metrics): int
    {
        return count(array_filter($metrics, fn (array $metric) => $metric['available']));
    }

    private function confidence(int $evidenceCount): string
    {
        return match (true) {
            $evidenceCount >= 3 => 'high',
            $evidenceCount >= 2 => 'medium',
            default => 'low',
        };
    }

    private function assertMayReview(User $user): void
    {
        if (! in_array($user->role, ['hr', 'super_admin'], true)) {
            throw new AccessDeniedHttpException('Only HR or Super Admin may review effectiveness snapshots.');
        }
    }

    /** @return array{start: string, end: string} */
    private function period(Carbon $start, Carbon $end): array
    {
        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }
}
