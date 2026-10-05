<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceFollowUp;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Validation\ValidationException;

/**
 * HR's follow-ups on attendance exceptions.
 *
 * These are internal notes. Who may read or write them is whoever supervises
 * the employee's attendance — the Attendance grant, at a scope wider than
 * 'self' that reaches them. The employee never sees the note; if HR wants to
 * tell them something, that is a message, written separately and sent
 * through the ordinary messaging rules.
 */
class AttendanceFollowUpService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly MessageService $messages,
        private readonly ActivityLogService $activity,
    ) {
    }

    /**
     * Follow-ups this user may see, newest first.
     *
     * @param  array{employeeId?: int, from?: string, to?: string, status?: string}  $filters
     * @return array<int, AttendanceFollowUp>
     */
    public function list(User $user, array $filters = []): array
    {
        $visible = $this->supervisedEmployeeIds($user);

        return AttendanceFollowUp::query()
            ->with(['employee:id,name', 'author:id,name', 'resolver:id,name'])
            ->when($visible !== null, fn ($query) => $query->whereIn('employee_id', $visible))
            ->when(filled($filters['employeeId'] ?? null), fn ($query) => $query->where('employee_id', (int) $filters['employeeId']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('date', '<=', $filters['to']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->all();
    }

    /**
     * Records a follow-up, and optionally messages the employee.
     *
     * The message is sent first: if this user may not message the employee,
     * nothing is saved, rather than leaving a follow-up that claims a message
     * went out when it did not.
     */
    public function record(User $user, array $attributes): AttendanceFollowUp
    {
        $employee = Employee::find($attributes['employeeId']);
        $author = $this->employeeFor($user);

        $this->assertMaySupervise($user, $employee, $author);

        $attendance = null;
        if (filled($attributes['attendanceId'] ?? null)) {
            $attendance = Attendance::find($attributes['attendanceId']);

            // A follow-up pinned to someone else's attendance would put a note
            // about one person on another's record.
            if (! $attendance || $attendance->employee_id !== $employee->id) {
                throw ValidationException::withMessages([
                    'attendanceId' => 'That attendance record does not belong to this employee.',
                ]);
            }
        }

        $message = filled($attributes['messageToEmployee'] ?? null)
            ? $this->messages->send($user, [
                'audience' => 'Direct message',
                'subject' => 'Attendance follow-up',
                'body' => $attributes['messageToEmployee'],
                'recipientIds' => [$employee->id],
            ])
            : null;

        $followUp = AttendanceFollowUp::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'date' => $attributes['date'] ?? $attendance?->date ?? now()->toDateString(),
            'issue' => $attributes['issue'],
            'action' => $attributes['action'] ?? 'note',
            'note' => $attributes['note'],
            // Set here rather than left to the column default: a default is
            // not read back onto the model, so the response said null and the
            // dialog offered no way to resolve the new follow-up.
            'status' => 'open',
            'created_by' => $author?->id,
            'message_id' => $message?->id,
        ]);

        // The audit names the event, never the note: the activity log is read
        // more widely than these notes are meant to be.
        $this->activity->record($user, [
            'module' => 'attendance',
            'action' => 'attendance follow-up recorded',
            'details' => sprintf(
                'Follow-up recorded for %s (%s on %s)%s.',
                $employee->name,
                str_replace('_', ' ', $followUp->issue),
                $followUp->date->toDateString(),
                $message ? ', employee messaged' : '',
            ),
            'targetId' => $employee->id,
            'targetType' => 'employee',
        ]);

        return $followUp->load(['employee:id,name', 'author:id,name', 'resolver:id,name']);
    }

    /** Closes a follow-up, with what came of it. */
    public function resolve(User $user, int $followUpId, ?string $resolution = null): AttendanceFollowUp
    {
        $followUp = AttendanceFollowUp::with('employee')->find($followUpId);
        $author = $this->employeeFor($user);

        if (! $followUp) {
            throw new NotFoundHttpException('Follow-up not found.');
        }

        $this->assertMaySupervise($user, $followUp->employee, $author, notFound: true);

        if ($followUp->status === 'resolved') {
            throw new ConflictHttpException('This follow-up is already resolved.');
        }

        DB::transaction(fn () => $followUp->update([
            'status' => 'resolved',
            'resolution' => $resolution,
            'resolved_by' => $author?->id,
            'resolved_at' => now(),
        ]));

        return $followUp->refresh()->load(['employee:id,name', 'author:id,name', 'resolver:id,name']);
    }

    /**
     * Ids of the employees whose attendance this user supervises, or null for
     * everyone. A 'self' grant supervises nobody — it is how an employee sees
     * their own attendance, and these notes are about them, not for them.
     */
    private function supervisedEmployeeIds(User $user): ?array
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Attendance');

        if (! ($row['view'] ?? false) || ($row['scope'] ?? 'self') === 'self') {
            throw new AccessDeniedHttpException('You do not have access to attendance follow-ups.');
        }

        return $this->scopes->visibleEmployeeIds($user, $row['scope']);
    }

    private function assertMaySupervise(User $user, ?Employee $employee, ?Employee $author, bool $notFound = false): void
    {
        $visible = $this->supervisedEmployeeIds($user);

        if (! $employee || ($visible !== null && ! in_array($employee->id, $visible, true))) {
            throw $notFound
                ? new NotFoundHttpException('Follow-up not found.')
                : new NotFoundHttpException('Employee not found.');
        }

        if ($author && $author->id === $employee->id) {
            throw new AccessDeniedHttpException('You cannot record a follow-up on your own attendance.');
        }
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}
