<?php

namespace Tests\Feature;

use App\Jobs\AnnounceCheckOutToTeams;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\TicketTimeLog;
use App\Models\User;
use App\Services\AttendanceSummary;
use App\Services\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * The check-out announcement posted to Microsoft Teams.
 *
 * The figures matter more than the plumbing: "work duration" and "effected
 * hours" are two different numbers and the gap between them is what a manager
 * reads the message for.
 */
class TeamsCheckOutTest extends TenantTestCase
{
    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->worker = $this->makeEmployee('worker@test.com', 'employee');
    }

    private function makeEmployee(string $email, string $role): Employee
    {
        $user = new User();
        $user->fill(['name' => 'Bilal Rauf', 'email' => $email, 'password' => 'password123']);
        $user->role = $role;
        $user->status = 'active';
        $user->save();

        return Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ORB-'.substr(md5($email), 0, 6),
            'name' => 'Bilal Rauf',
            'email' => $email,
            'status' => 'active',
        ]);
    }

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    /** A day with two sessions and time logged against one referenced ticket. */
    private function makeDay(): Attendance
    {
        $attendance = Attendance::create([
            'employee_id' => $this->worker->id,
            'date' => '2026-08-11',
            'check_in' => '09:02',
            'check_out' => '19:50',
            'status' => 'present',
        ]);
        $attendance->sessions()->create(['check_in' => '09:02', 'check_out' => '13:00']);
        $attendance->sessions()->create(['check_in' => '14:00', 'check_out' => '19:50']);

        $project = Project::create(['name' => 'Internal', 'slug' => 'internal']);
        $module = ProjectModule::create([
            'project_id' => $project->id, 'name' => 'Attendance', 'slug' => 'attendance',
        ]);
        $ticket = Ticket::create([
            'project_id' => $project->id,
            'project_module_id' => $module->id,
            'title' => 'Camera permission fallback',
            'reference' => 'LPS-M-683',
            'assignee_id' => $this->worker->id,
        ]);

        // 7h 13m, inside 2026-08-11 in Asia/Karachi.
        TicketTimeLog::create([
            'ticket_id' => $ticket->id,
            'employee_id' => $this->worker->id,
            'started_at' => Carbon::parse('2026-08-11 09:30:00', 'Asia/Karachi'),
            'ended_at' => Carbon::parse('2026-08-11 16:43:00', 'Asia/Karachi'),
            'duration_seconds' => (7 * 3600) + (13 * 60),
        ]);

        return $attendance->fresh(['employee', 'sessions']);
    }

    public function test_the_summary_separates_desk_time_from_ticket_time(): void
    {
        app(SettingsService::class)->putGroup('company', ['timezone' => 'Asia/Karachi']);

        $summary = app(AttendanceSummary::class)->forCheckOut($this->makeDay());

        $this->assertSame('Bilal Rauf', $summary['employeeName']);
        $this->assertSame('07:50 PM', $summary['checkedOutAt']);

        // 09:02-13:00 plus 14:00-19:50 = 9h 48m. The hour between sessions is
        // excluded, which is the whole reason sessions exist.
        $this->assertSame((9 * 3600) + (48 * 60), $summary['workedSeconds']);
        $this->assertSame((7 * 3600) + (13 * 60), $summary['effectedSeconds']);

        $this->assertSame([['reference' => 'LPS-M-683', 'seconds' => (7 * 3600) + (13 * 60)]], $summary['tickets']);
    }

    public function test_a_ticket_without_a_reference_falls_back_to_its_title(): void
    {
        app(SettingsService::class)->putGroup('company', ['timezone' => 'Asia/Karachi']);

        $attendance = $this->makeDay();
        Ticket::query()->update(['reference' => null]);

        $summary = app(AttendanceSummary::class)->forCheckOut($attendance);

        $this->assertSame('Camera permission fallback', $summary['tickets'][0]['reference']);
    }

    public function test_durations_read_the_way_the_announcement_does(): void
    {
        $this->assertSame('9 Hours 18 Minutes', AttendanceSummary::humanise((9 * 3600) + (18 * 60)));
        $this->assertSame('1 Hour 1 Minute', AttendanceSummary::humanise(3660));
        // No hours still names the unit rather than rendering blank.
        $this->assertSame('12 Minutes', AttendanceSummary::humanise(12 * 60));
        $this->assertSame('0 Minutes', AttendanceSummary::humanise(0));
        $this->assertSame('2 Hours', AttendanceSummary::humanise(7200));
    }

    public function test_checking_out_queues_the_announcement(): void
    {
        Queue::fake();

        $headers = $this->auth('worker@test.com');
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)->assertCreated();
        $this->postJson('/api/attendance/check-out', [], $headers)->assertOk();

        Queue::assertPushed(AnnounceCheckOutToTeams::class);
    }

    public function test_nothing_is_posted_when_no_webhook_is_configured(): void
    {
        Http::fake();
        app(SettingsService::class)->putGroup('integrations', ['teamsAttendanceWebhookUrl' => '']);

        $attendance = $this->makeDay();
        (new AnnounceCheckOutToTeams($attendance->id))->handle(
            app(\App\Services\TeamsNotifier::class),
            app(AttendanceSummary::class),
        );

        Http::assertNothingSent();
    }

    public function test_the_card_carries_the_figures(): void
    {
        Http::fake(['*' => Http::response('', 202)]);
        app(SettingsService::class)->putGroup('company', ['timezone' => 'Asia/Karachi']);
        app(SettingsService::class)->putGroup('integrations', [
            'teamsAttendanceWebhookUrl' => 'https://prod-01.westus.logic.azure.com/workflows/test',
        ]);

        $attendance = $this->makeDay();
        (new AnnounceCheckOutToTeams($attendance->id))->handle(
            app(\App\Services\TeamsNotifier::class),
            app(AttendanceSummary::class),
        );

        Http::assertSent(function ($request) {
            // Unescaped slashes, or the content-type below never matches.
            $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES);

            return str_contains($body, 'Bilal Rauf')
                && str_contains($body, 'Checked Out at 07:50 PM')
                && str_contains($body, 'Work Duration : 9 Hours 48 Minutes')
                && str_contains($body, 'Effected Hours : 7 Hours 13 Minutes')
                && str_contains($body, 'LPS-M-683')
                // The workflow endpoint requires the attachment envelope.
                && str_contains($body, 'application/vnd.microsoft.card.adaptive');
        });
    }

    /**
     * Announcements go to their own channel, not the attendance one.
     *
     * A Teams workflow is bound to the channel it was created in, so the only
     * way to reach two channels is two URLs — the destination cannot be chosen
     * per message.
     */
    public function test_an_announcement_posts_to_the_announcements_channel(): void
    {
        Http::fake(['*' => Http::response('', 202)]);
        app(SettingsService::class)->putGroup('integrations', [
            'teamsAttendanceWebhookUrl' => 'https://prod-01.westus.logic.azure.com/workflows/attendance',
            'teamsAnnouncementsWebhookUrl' => 'https://prod-01.westus.logic.azure.com/workflows/announcements',
        ]);

        $announcement = \App\Models\Announcement::create([
            'title' => 'Office closed Friday',
            'body' => 'The office is closed for maintenance.',
            'audience' => 'All company',
            'priority' => 'high',
            'author_name' => 'Sara Ahmed',
            'published_at' => now(),
        ]);

        (new \App\Jobs\AnnouncePostToTeams($announcement->id))->handle(
            app(\App\Services\TeamsNotifier::class),
        );

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES);

            return str_contains($request->url(), '/workflows/announcements')
                && str_contains($body, 'Office closed Friday')
                && str_contains($body, 'Important announcement')
                && str_contains($body, 'Sara Ahmed');
        });

        // And never to the punch channel.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/workflows/attendance'));
    }

    public function test_announcements_stay_quiet_when_only_the_attendance_channel_is_set(): void
    {
        Http::fake();
        app(SettingsService::class)->putGroup('integrations', [
            'teamsAttendanceWebhookUrl' => 'https://prod-01.westus.logic.azure.com/workflows/attendance',
            'teamsAnnouncementsWebhookUrl' => '',
        ]);

        $announcement = \App\Models\Announcement::create([
            'title' => 'Nothing to see',
            'body' => 'Body',
            'audience' => 'All company',
            'priority' => 'normal',
            'published_at' => now(),
        ]);

        (new \App\Jobs\AnnouncePostToTeams($announcement->id))->handle(
            app(\App\Services\TeamsNotifier::class),
        );

        Http::assertNothingSent();
    }

    /**
     * Teams being down must never cost somebody their check-out.
     */
    public function test_a_failing_webhook_does_not_break_the_punch(): void
    {
        Http::fake(['*' => Http::response('gateway timeout', 504)]);
        app(SettingsService::class)->putGroup('integrations', [
            'teamsAttendanceWebhookUrl' => 'https://prod-01.westus.logic.azure.com/workflows/test',
        ]);

        $headers = $this->auth('worker@test.com');
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)->assertCreated();

        // Runs inline on the sync queue, so a raised exception would surface here.
        $this->postJson('/api/attendance/check-out', [], $headers)->assertOk();
    }
}
