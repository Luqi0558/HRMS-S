<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stops MySQL silently rewriting four timestamp columns on every UPDATE.
 *
 * With explicit_defaults_for_timestamp = 0 (the default on this server, and on
 * most XAMPP/MariaDB installs), the first TIMESTAMP column in a table declared
 * NOT NULL without an explicit default is silently given
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Nothing in the
 * migrations asked for that, and nothing in the app knows it happened.
 *
 * The result is a column that quietly resets itself to "now" whenever any other
 * field on the row changes:
 *
 *   ticket_time_logs.started_at   rewritten when the timer is stopped, so every
 *                                 finished timer read as zero elapsed — the end
 *                                 landed *before* the start
 *   invitations.expires_at        an invite's expiry pushed forward on any edit
 *   salary_slips.issued_at        the issue date of a payslip rewritten on edit
 *   policy_acknowledgements
 *     .acknowledged_at            when somebody accepted a policy, rewritten
 *
 * Re-declaring each column with an explicit DEFAULT and no ON UPDATE clause is
 * what removes the behaviour; the column definition is otherwise unchanged.
 */
return new class extends Migration
{
    /**
     * Column => the table it lives on. All four are TIMESTAMP NOT NULL.
     */
    private const COLUMNS = [
        'ticket_time_logs' => 'started_at',
        'invitations' => 'expires_at',
        'salary_slips' => 'issued_at',
        'policy_acknowledgements' => 'acknowledged_at',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! $this->hasAutoUpdate($table, $column)) {
                continue;
            }

            // Naming an explicit DEFAULT is what drops the implicit ON UPDATE.
            DB::statement(
                "ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
            );
        }

        $this->repairTimeLogs();
    }

    /**
     * Rows already damaged before the column was fixed.
     *
     * duration_seconds is trustworthy: it is computed in PHP from the correct
     * started_at and written in the same UPDATE that corrupts it, so it records
     * the real elapsed time. That makes the original start recoverable exactly,
     * rather than guessed.
     *
     * Only rows that are provably wrong are touched — a start after its own end
     * is impossible otherwise.
     */
    private function repairTimeLogs(): void
    {
        DB::update(
            'UPDATE ticket_time_logs
                SET started_at = DATE_SUB(ended_at, INTERVAL duration_seconds SECOND)
              WHERE ended_at IS NOT NULL
                AND duration_seconds IS NOT NULL
                AND started_at > ended_at'
        );
    }

    private function hasAutoUpdate(string $table, string $column): bool
    {
        $found = DB::select(
            'SELECT EXTRA FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return isset($found[0]) && str_contains(strtolower($found[0]->EXTRA), 'on update');
    }

    public function down(): void
    {
        // Deliberately empty. The previous state was a column that corrupted
        // itself, reachable only through a MySQL default nobody asked for —
        // restoring it would mean re-introducing the data loss this fixes.
    }
};
