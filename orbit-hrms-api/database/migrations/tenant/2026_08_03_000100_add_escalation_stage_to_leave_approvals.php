<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement(
            "ALTER TABLE leave_approvals MODIFY stage ENUM('lead', 'hr', 'escalation') NOT NULL"
        );
    }

    public function down(): void
    {

        DB::table('leave_approvals')->where('stage', 'escalation')->delete();

        DB::statement(
            "ALTER TABLE leave_approvals MODIFY stage ENUM('lead', 'hr') NOT NULL"
        );
    }
};
