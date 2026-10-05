<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\AttendanceFollowUp;
use App\Models\Employee;
use App\Models\MessageRecipient;
use App\Models\User;
use App\Services\AttendanceFollowUpService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TenantTestCase;

/**
 * HR's internal follow-ups on attendance exceptions.
 *
 * The dashboard dialog used to say "Follow-up recorded" and store nothing.
 * These pin that notes are kept, are readable only by people who supervise
 * the employee's attendance — never by the employee — and that a message to
 * the employee happens only when HR writes one.
 */
class AttendanceFollowUpTest extends TenantTestCase
{
    private Employee $hr;
    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
        $this->worker = $this->makeEmployee('worker@test.com', 'employee');
    }

    private function makeEmployee(string $email, string $role): Employee
    {
        $user = new User();
        $user->fill(['name' => $email, 'email' => $email, 'password' => 'password123']);
        $user->role = $role;
        $user->status = 'active';
        $user->save();

        return Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ORB-'.substr(md5($email), 0, 6),
            'name' => $email,
            'email' => $email,
            'status' => 'active',
        ]);
    }

    private function userOf(Employee $employee): User
    {
        return User::findOrFail($employee->user_id);
    }

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        $this->app['auth']->forgetGuards();

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function lateToday(Employee $employee): Attendance
    {
        return Attendance::create([
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'check_in' => '10:40:00',
            'status' => 'late',
        ]);
    }

    public function test_hr_records_a_follow_up_and_it_survives_a_reload(): void
    {
        $attendance = $this->lateToday($this->worker);

        $this->postJson('/api/attendance/follow-ups', [
            'employeeId' => $this->worker->id,
            'attendanceId' => $attendance->id,
            'issue' => 'late',
            'action' => 'called',
            'note' => 'Called; traffic on the motorway. Reminded about the grace time.',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('followUp.status', 'open')
            ->assertJsonPath('followUp.action', 'called')
            ->assertJsonPath('followUp.employeeMessaged', false);

        $rows = $this->getJson("/api/attendance/follow-ups?employeeId={$this->worker->id}", $this->auth('hr@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame(now()->toDateString(), $rows[0]['date']);
        $this->assertSame('hr@test.com', $rows[0]['createdByName']);
    }

    /** The whole point of an internal note: the employee cannot read it. */
    public function test_an_employee_cannot_read_follow_ups(): void
    {
        app(AttendanceFollowUpService::class)->record($this->userOf($this->hr), [
            'employeeId' => $this->worker->id,
            'issue' => 'absent',
            'note' => 'Third absence this month; raise at review.',
        ]);

        $this->getJson('/api/attendance/follow-ups', $this->auth('worker@test.com'))->assertForbidden();
    }

    public function test_no_message_is_sent_unless_hr_writes_one(): void
    {
        $followUps = app(AttendanceFollowUpService::class);

        $followUps->record($this->userOf($this->hr), [
            'employeeId' => $this->worker->id,
            'issue' => 'absent',
            'note' => 'Internal only.',
        ]);
        $this->assertSame(0, MessageRecipient::where('employee_id', $this->worker->id)->count());

        $followUp = $followUps->record($this->userOf($this->hr), [
            'employeeId' => $this->worker->id,
            'issue' => 'absent',
            'note' => 'Asked for the reason by message too.',
            'messageToEmployee' => 'Hi, we have no check-in from you today. Please let HR know.',
        ]);

        $this->assertNotNull($followUp->message_id);
        $delivered = MessageRecipient::with('message')->where('employee_id', $this->worker->id)->get();
        $this->assertCount(1, $delivered);
        // The employee receives the message text, never the internal note.
        $this->assertSame('Hi, we have no check-in from you today. Please let HR know.', $delivered->first()->message->body);
        $this->assertStringNotContainsString('Asked for the reason', $delivered->first()->message->body);
    }

    /** The activity log is read more widely than these notes are meant to be. */
    public function test_the_audit_entry_never_contains_the_note(): void
    {
        app(AttendanceFollowUpService::class)->record($this->userOf($this->hr), [
            'employeeId' => $this->worker->id,
            'issue' => 'late',
            'note' => 'Confidential medical reason disclosed on the call.',
        ]);

        $entry = ActivityLog::where('action', 'attendance follow-up recorded')->firstOrFail();
        $this->assertStringNotContainsString('Confidential', (string) $entry->details);
    }

    public function test_a_follow_up_cannot_be_pinned_to_someone_elses_attendance(): void
    {
        $other = $this->makeEmployee('other@test.com', 'employee');
        $attendance = $this->lateToday($other);

        $this->expectException(ValidationException::class);
        app(AttendanceFollowUpService::class)->record($this->userOf($this->hr), [
            'employeeId' => $this->worker->id,
            'attendanceId' => $attendance->id,
            'issue' => 'late',
            'note' => 'Wrong record.',
        ]);
    }

    public function test_nobody_follows_up_their_own_attendance(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        app(AttendanceFollowUpService::class)->record($this->userOf($this->hr), [
            'employeeId' => $this->hr->id,
            'issue' => 'late',
            'note' => 'Excusing myself.',
        ]);
    }

    public function test_a_follow_up_is_resolved_once_with_its_outcome(): void
    {
        $followUps = app(AttendanceFollowUpService::class);
        $followUp = $followUps->record($this->userOf($this->hr), [
            'employeeId' => $this->worker->id,
            'issue' => 'missing_checkout',
            'note' => 'Asked to confirm finish time.',
        ]);

        $resolved = $followUps->resolve($this->userOf($this->hr), $followUp->id, 'Finished at 18:10; record corrected.');

        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('Finished at 18:10; record corrected.', $resolved->resolution);
        $this->assertSame($this->hr->id, $resolved->resolved_by);

        $this->expectException(ConflictHttpException::class);
        $followUps->resolve($this->userOf($this->hr), $followUp->id);
    }

    public function test_follow_ups_filter_to_a_day(): void
    {
        $followUps = app(AttendanceFollowUpService::class);
        $followUps->record($this->userOf($this->hr), [
            'employeeId' => $this->worker->id, 'issue' => 'late', 'note' => 'Today.',
        ]);
        AttendanceFollowUp::create([
            'employee_id' => $this->worker->id, 'date' => now()->subDays(5)->toDateString(),
            'issue' => 'late', 'action' => 'note', 'note' => 'Last week.',
        ]);

        $today = $followUps->list($this->userOf($this->hr), ['from' => now()->toDateString(), 'to' => now()->toDateString()]);

        $this->assertCount(1, $today);
        $this->assertSame('Today.', $today[0]->note);
    }
}
