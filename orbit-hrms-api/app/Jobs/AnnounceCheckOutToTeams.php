<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Services\AttendanceSummary;
use App\Services\TeamsNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Announces a check-out in Teams.
 *
 * Queued so the punch does not wait on Microsoft. QueueTenancyBootstrapper is
 * enabled, so the tenant is captured at dispatch and restored here — which is
 * why this carries an id rather than the composed message: nothing tenant-
 * specific, and no webhook URL, ends up sitting in the jobs table.
 *
 * With QUEUE_CONNECTION=sync this still runs inside the request. That is
 * survivable because TeamsNotifier swallows its own failures and uses a short
 * timeout, but a real worker is what keeps check-out instant.
 */
class AnnounceCheckOutToTeams implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One retry, then give up. An announcement that arrives an hour late is
     * noise, and the punch itself is already safely recorded.
     */
    public int $tries = 2;

    public function __construct(private readonly int $attendanceId)
    {
    }

    public function handle(TeamsNotifier $teams, AttendanceSummary $summaries): void
    {
        if (! $teams->isConfigured(TeamsNotifier::ATTENDANCE)) {
            return;
        }

        $attendance = Attendance::with(['employee', 'sessions'])->find($this->attendanceId);

        // Deleted between the punch and the job running, or checked back in —
        // announcing a check-out that no longer stands would be worse than
        // staying quiet.
        if (! $attendance || blank($attendance->check_out)) {
            return;
        }

        $summary = $summaries->forCheckOut($attendance);

        $teams->announceCheckOut(
            $summary['employeeName'],
            $summary['checkedOutAt'],
            $summary['workedSeconds'],
            $summary['effectedSeconds'],
            $summary['tickets'],
        );
    }
}
