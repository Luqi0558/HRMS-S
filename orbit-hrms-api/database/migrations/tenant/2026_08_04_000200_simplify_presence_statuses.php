<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Narrows presence to two states: active, or away.
 *
 * Busy and invisible were more than an HRMS needs. Four states asks people to
 * curate a status rather than just work, and "appear offline" in a workplace
 * tool invites a question — why is someone hiding — that the feature was never
 * meant to raise.
 *
 * 'available' is not stored: it is the absence of an override, which is what
 * lets presence fall back to whatever the connection says.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Anyone currently busy or invisible becomes away — the nearest
        // meaning, and better than dropping them to a status they did not
        // choose.
        DB::table('employees')
            ->whereIn('presence_status', ['busy', 'invisible'])
            ->update(['presence_status' => 'away']);

        DB::statement(
            "ALTER TABLE employees MODIFY presence_status ENUM('available', 'away') NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE employees MODIFY presence_status ENUM('available', 'busy', 'away', 'invisible') NULL"
        );
    }
};
