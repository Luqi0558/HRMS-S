<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            // A person can finish an office session and start a remote one on
            // the same day. The session, not only the parent day, owns the
            // selected location; historical sessions were office attendance.
            $table->string('work_mode', 20)->default('office')->after('check_out');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropColumn('work_mode');
        });
    }
};
