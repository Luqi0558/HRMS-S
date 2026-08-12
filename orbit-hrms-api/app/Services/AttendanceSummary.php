<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind a check-out announcement.
 *
 * Two different numbers, and the difference between them is the point:
 *
 *   Work duration   how long they were at their desk — the sum of the day's
 *                   attendance sessions. Staff stay punched in through the
 *                   scheduled break, so this is the full span at the desk
 *   Effected hours  how much of that landed on a ticket, from the timers they
 *                   actually started
 *
 * Effected hours can legitimately be lower (meetings, admin) but never
 * meaningfully higher; a large excess means somebody left a timer running.
 */
class AttendanceSummary
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * @return array{
     *   employeeName: string,
     *   checkedOutAt: string,
     *   workedSeconds: int,
     *   effectedSeconds: int,
     *   tickets: array<int, array{reference: string, seconds: int}>
     * }
     */
    public function forCheckOut(Attendance $attendance): array
    {
        $attendance->loadMissing(['employee', 'sessions']);

        $tickets = $this->ticketBreakdown($attendance);

        return [
            'employeeName' => $attendance->employee?->name ?? 'Someone',
            // 12-hour, matching how the announcement reads.
            'checkedOutAt' => Carbon::parse($attendance->check_out)->format('h:i A'),
            'workedSeconds' => (int) ($attendance->workedSeconds() ?? 0),
            'effectedSeconds' => (int) array_sum(array_column($tickets, 'seconds')),
            'tickets' => $tickets,
        ];
    }

    /**
     * Time logged against tickets during that working day, biggest first.
     *
     * The window is built in the company's timezone: ticket_time_logs stores
     * real timestamps while attendance.date is a local calendar day, so
     * comparing the two directly would attribute the first hours of a shift to
     * the previous date on any tenant east of UTC.
     *
     * @return array<int, array{reference: string, seconds: int}>
     */
    private function ticketBreakdown(Attendance $attendance): array
    {
        $zone = (string) $this->settings->get('company', 'timezone', config('app.timezone'));

        $dayStart = Carbon::parse($attendance->date->toDateString(), $zone)->startOfDay()->utc();
        $dayEnd = (clone $dayStart)->addDay();

        $rows = DB::table('ticket_time_logs')
            ->join('tickets', 'tickets.id', '=', 'ticket_time_logs.ticket_id')
            ->where('ticket_time_logs.employee_id', $attendance->employee_id)
            ->whereNotNull('ticket_time_logs.duration_seconds')
            ->where('ticket_time_logs.started_at', '>=', $dayStart)
            ->where('ticket_time_logs.started_at', '<', $dayEnd)
            ->groupBy('tickets.id', 'tickets.reference', 'tickets.title')
            ->orderByDesc(DB::raw('SUM(ticket_time_logs.duration_seconds)'))
            ->get([
                'tickets.reference',
                'tickets.title',
                DB::raw('SUM(ticket_time_logs.duration_seconds) as seconds'),
            ]);

        return $rows->map(fn ($row) => [
            // The external code when there is one, the title otherwise — a line
            // reading just "—" would tell the channel nothing.
            'reference' => filled($row->reference) ? $row->reference : $row->title,
            'seconds' => (int) $row->seconds,
        ])->all();
    }

    /**
     * "9 Hours 18 Minutes", matching the wording of the announcement.
     * Seconds are dropped: nobody reconciles a timesheet to the second.
     */
    public static function humanise(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours.' '.($hours === 1 ? 'Hour' : 'Hours');
        }
        // Always shown when there are no hours, so a short day reads
        // "12 Minutes" rather than an empty string.
        if ($minutes > 0 || $hours === 0) {
            $parts[] = $minutes.' '.($minutes === 1 ? 'Minute' : 'Minutes');
        }

        return implode(' ', $parts);
    }
}
