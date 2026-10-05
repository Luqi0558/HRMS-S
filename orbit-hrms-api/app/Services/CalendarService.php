<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Read model for the company calendar.
 *
 * Calendar consumers receive a deliberately small, date-bounded people-ops
 * event shape; delivery schedules remain in the project workspace instead of
 * leaking into HR's company calendar.
 */
class CalendarService
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /**
     * @param array<int, string> $types
     * @return array<int, array<string, mixed>>
     */
    public function events(User $user, Carbon $from, Carbon $to, array $types = []): array
    {
        if (! $this->permissions->can($user, 'Calendar', 'view')) {
            throw new AccessDeniedHttpException('You may not view the company calendar.');
        }

        $allowedTypes = ['birthday', 'anniversary', 'leave'];
        $types = array_values(array_intersect($types ?: $allowedTypes, $allowedTypes));
        $events = [];

        if (in_array('birthday', $types, true) || in_array('anniversary', $types, true)) {
            $employees = Employee::query()
                ->where('status', 'active')
                ->where(function ($query) use ($types) {
                    if (in_array('birthday', $types, true)) {
                        $query->whereNotNull('birthday');
                    }
                    if (in_array('anniversary', $types, true)) {
                        $query->orWhereNotNull('joining_date');
                    }
                })
                ->get(['id', 'name', 'department', 'birthday', 'joining_date']);

            foreach ($employees as $employee) {
                if (in_array('birthday', $types, true) && $employee->birthday) {
                    foreach ($this->yearlyOccurrences($employee->birthday, $from, $to) as $date) {
                        $events[] = $this->event(
                            "birthday-{$employee->id}-{$date->year}",
                            'birthday',
                            "{$employee->name}'s birthday",
                            $date,
                            $date,
                            "{$employee->department} · birthday",
                            ['employeeId' => $employee->id],
                        );
                    }
                }

                if (in_array('anniversary', $types, true) && $employee->joining_date) {
                    foreach ($this->yearlyOccurrences($employee->joining_date, $from, $to) as $date) {
                        if ($date->year < $employee->joining_date->year) {
                            continue;
                        }

                        $years = $date->year - $employee->joining_date->year;
                        $events[] = $this->event(
                            "anniversary-{$employee->id}-{$date->year}",
                            'anniversary',
                            "{$employee->name}'s work anniversary",
                            $date,
                            $date,
                            "{$years}-year milestone · {$employee->department}",
                            ['employeeId' => $employee->id],
                        );
                    }
                }
            }
        }

        if (in_array('leave', $types, true)) {
            LeaveRequest::query()
                ->with('employee:id,name,department')
                ->where('status', 'approved')
                ->whereDate('from_date', '<=', $to->toDateString())
                ->whereDate('to_date', '>=', $from->toDateString())
                ->get()
                ->each(function (LeaveRequest $leave) use (&$events): void {
                    $events[] = $this->event(
                        "leave-{$leave->id}",
                        'leave',
                        "{$leave->employee?->name} on leave",
                        $leave->from_date,
                        $leave->to_date,
                        "{$leave->type} · {$leave->days} day".($leave->days == 1.0 ? '' : 's'),
                        ['leaveRequestId' => $leave->id, 'employeeId' => $leave->employee_id],
                    );
                });
        }

        usort($events, fn (array $a, array $b) => [$a['startDate'], $a['title']] <=> [$b['startDate'], $b['title']]);

        return $events;
    }

    /** @return array<int, Carbon> */
    private function yearlyOccurrences(Carbon $source, Carbon $from, Carbon $to): array
    {
        $events = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $day = min($source->day, Carbon::create($year, $source->month, 1)->daysInMonth);
            $date = Carbon::create($year, $source->month, $day)->startOfDay();
            if ($date->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay())) {
                $events[] = $date;
            }
        }

        return $events;
    }

    /** @param array<string, int> $references */
    private function event(
        string $id,
        string $type,
        string $title,
        Carbon $start,
        Carbon $end,
        string $detail,
        array $references,
    ): array {
        return array_merge([
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'allDay' => true,
            'detail' => $detail,
        ], $references);
    }
}
