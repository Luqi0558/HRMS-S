<?php

namespace App\Services;

use App\Jobs\AnnounceCheckOutToTeams;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AttendanceService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * The current moment in the company's own timezone.
     *
     * check_in and check_out are TIME columns — a wall clock with no zone
     * attached — and settings.attendance.startTime ("09:30") is a wall clock in
     * the office's timezone too. Stamping them from now() meant writing UTC and
     * comparing it against a local start time: on Asia/Karachi (UTC+5) a 09:00
     * arrival stored as 04:00 and someone strolling in at 14:00 was recorded
     * "present", five hours late, because 09:00 UTC is before 09:30.
     *
     * The date is taken from here too, so the working day rolls over at local
     * midnight rather than at 05:00.
     */
    private function companyNow(): Carbon
    {
        return now()->setTimezone(
            (string) $this->settings->get('company', 'timezone', config('app.timezone'))
        );
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

        return Attendance::with('sessions')
            ->where('employee_id', $employee->id)
            ->whereDate('date', $this->companyNow()->toDateString())
            ->first();
    }

    /**
     * An attendance row whose check-in photo the caller is allowed to see.
     *
     * Mirrors MedicalClaimService::findForReceipt: your own row always, anyone
     * else's only through the Attendance module and only within your scope. A
     * 404 rather than a 403 throughout — whether a given employee photographed
     * themselves this morning is itself information.
     */
    public function findForPhoto(User $user, int $attendanceId): Attendance
    {
        $row = Attendance::find($attendanceId);

        if (! $row || blank($row->photo_path)) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $employee = $this->employeeFor($user);

        if ($employee && $row->employee_id === $employee->id) {
            return $row;
        }

        $permission = collect($this->permissions->forUser($user))->firstWhere('module', 'Attendance');

        if (! $permission || ! ($permission['view'] ?? false)) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $visibleIds = $this->scopes->visibleEmployeeIds($user, $permission['scope']);
        if ($visibleIds !== null && ! in_array($row->employee_id, $visibleIds, true)) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return $row;
    }

    /**
     * Removes a superseded check-in photo from disk.
     *
     * Quiet about failures: a missing file means the job is already done, and a
     * punch must not fail because an old image could not be tidied away.
     */
    private function forgetPhoto(Attendance $record, ?string $path): void
    {
        if (blank($path)) {
            return;
        }

        try {
            Storage::disk('local')->delete($path);
        } catch (\Throwable) {
            // Nothing to do — the row is about to stop pointing at it anyway.
        }

        // The session that captured it holds the same path, so it is cleared
        // too — otherwise the database would go on referencing a file that is
        // no longer on disk.
        if ($record->exists) {
            $record->sessions()->where('photo_path', $path)->update(['photo_path' => null]);
        }
    }

    public function checkIn(User $user, array $attributes = []): Attendance
    {
        $employee = $this->requireEmployee($user);
        $today = $this->companyNow()->toDateString();

        $now = $this->companyNow()->format('H:i');

        return DB::transaction(function () use ($employee, $today, $now, $attributes) {
            $record = Attendance::where('employee_id', $employee->id)
                ->whereDate('date', $today)
                ->lockForUpdate()
                ->first();

            // Only an open session blocks a new one. Checking in again after
            // checking out is legitimate — an appointment, or simply correcting
            // a mistaken punch — and used to be refused outright, which left
            // people unable to record the rest of their day.
            if ($record?->sessions()->whereNull('check_out')->exists()) {
                throw new ConflictHttpException('You are already checked in. Check out first.');
            }

            $record ??= new Attendance([
                'employee_id' => $employee->id,
                'date' => $today,
            ]);

            // Status and the day's check_in are stamped from the FIRST session
            // only. Recomputing either on a later check-in would relabel a
            // punctual morning as late because somebody punched back in later
            // in the day.
            if (blank($record->check_in)) {
                $record->check_in = $now;
                $record->status = $this->statusFor($now);
            }

            // Unlike check_in and status, these track the MOST RECENT session.
            // The row's photo is what the ledger shows, and showing this
            // morning's photo against an afternoon check-in makes it look like
            // nobody photographed the return — the point of the camera is to
            // evidence the punch that just happened.
            //
            // Only overwritten when the new punch actually carries a photo, so
            // a check-in with the camera turned off does not blank the evidence
            // already gathered.
            $record->device = $attributes['device'] ?? $record->device ?? 'laptop';
            if (filled($attributes['photoPath'] ?? null)) {
                // The previous shot is deleted rather than kept: the ledger only
                // ever shows the latest, so older files would accumulate on disk
                // unseen. Requested deliberately — the trade is that the earlier
                // arrivals of a multi-session day are no longer evidenced.
                $this->forgetPhoto($record, $record->photo_path);
                $record->photo_path = $attributes['photoPath'];
            }

            // Reopened: the day has no last-out again until they check out.
            $record->check_out = null;
            $record->save();

            $record->sessions()->create([
                'check_in' => $now,
                'device' => $attributes['device'] ?? 'laptop',
                'photo_path' => $attributes['photoPath'] ?? null,
            ]);

            return $record->refresh()->load('sessions');
        });
    }

    public function checkOut(User $user): Attendance
    {
        $employee = $this->requireEmployee($user);

        $now = $this->companyNow()->format('H:i');

        $record = DB::transaction(function () use ($employee, $now) {
            $record = Attendance::where('employee_id', $employee->id)
                ->whereDate('date', $this->companyNow()->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $record || ! $record->check_in) {
                throw new ConflictHttpException('You have not checked in today.');
            }

            $open = $record->sessions()->whereNull('check_out')->orderBy('check_in')->first();

            if (! $open) {
                throw new ConflictHttpException('You are not currently checked in.');
            }

            $open->update(['check_out' => $now]);

            // The day's check_out is the last one, so reports and exports that
            // read the parent row keep working unchanged.
            $record->update(['check_out' => $now]);

            return $record->refresh()->load('sessions');
        });

        // Outside the transaction on purpose. On the sync queue the job runs
        // immediately, and dispatching from inside would announce a check-out
        // that had not committed yet — and could still roll back. The job
        // no-ops when no Teams webhook is configured.
        AnnounceCheckOutToTeams::dispatch($record->id);

        return $record;
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

        $query = Attendance::with([
            'employee:id,name,department,designation',
            // workedSeconds() and breakSeconds() sum these, so loading them here
            // is what keeps the ledger from firing a query per row.
            'sessions',
        ]);

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
