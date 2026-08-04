<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AttendanceService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Today's record for the signed-in user, or null before they check in.
     * Drives the Check in / Check out button.
     */
    public function today(User $user): ?Attendance
    {
        $employee = $this->employeeFor($user);
        if (! $employee) {
            return null;
        }

        return Attendance::where('employee_id', $employee->id)
            ->whereDate('date', now()->toDateString())
            ->first();
    }

    public function checkIn(User $user, array $attributes = []): Attendance
    {
        $employee = $this->requireEmployee($user);
        $today = now()->toDateString();

        $existing = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $today)
            ->first();

        // 409 rather than silently returning the existing row: a second
        // check-in usually means a double-tap, and overwriting check_in would
        // erase the real arrival time.
        if ($existing && $existing->check_in) {
            throw new ConflictHttpException('You have already checked in today.');
        }

        $now = now()->format('H:i');

        return Attendance::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $today],
            [
                'check_in' => $now,
                // Computed once, at write time. Recomputing on read would
                // rewrite history the moment HR changed the grace period.
                'status' => $this->statusFor($now),
                'device' => $attributes['device'] ?? 'laptop',
                'photo_path' => $attributes['photoPath'] ?? null,
            ],
        );
    }

    public function checkOut(User $user): Attendance
    {
        $employee = $this->requireEmployee($user);

        $record = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        if (! $record || ! $record->check_in) {
            throw new ConflictHttpException('You have not checked in today.');
        }

        if ($record->check_out) {
            throw new ConflictHttpException('You have already checked out today.');
        }

        $record->update(['check_out' => now()->format('H:i')]);

        return $record->refresh();
    }

    /**
     * The punch ledger. Scoped like every other list: HR sees the company, a
     * manager their own people, an employee only themselves.
     */
    public function list(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Attendance');
        $employee = $this->employeeFor($user);

        // No Attendance permission still means seeing your own record — an
        // employee has no row in the grid but must see their own history.
        if (! $row || ! ($row['view'] ?? false)) {
            if (! $employee) {
                throw new AccessDeniedHttpException('You do not have permission to do that.');
            }
            $visibleIds = [$employee->id];
        } else {
            $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        }

        $query = Attendance::with('employee:id,name,department,designation');

        if ($visibleIds !== null) {
            $query->whereIn('employee_id', $visibleIds);
        }

        if (filled($filters['date'] ?? null)) {
            $query->whereDate('date', $filters['date']);
        }

        if (filled($filters['from'] ?? null)) {
            $query->whereDate('date', '>=', $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->whereDate('date', '<=', $filters['to']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['employee_id'] ?? null)) {
            $query->where('employee_id', $filters['employee_id']);
        }

        return $query->orderByDesc('date')->orderBy('employee_id')->paginate($perPage);
    }

    /**
     * Late when check-in is past the configured start plus grace.
     *
     * Mirrors attendanceStatusFor() in the Vue store — but this is the copy
     * that decides the stored value.
     */
    private function statusFor(string $checkIn): string
    {
        $attendance = $this->settings->group('attendance');

        $start = $this->toMinutes((string) ($attendance['startTime'] ?? '09:30'));
        $grace = (int) ($attendance['graceMinutes'] ?? 0);

        return $this->toMinutes($checkIn) > $start + $grace ? 'late' : 'present';
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hours) * 60 + (int) $minutes;
    }

    private function requireEmployee(User $user): Employee
    {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can record attendance.');
        }

        return $employee;
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
