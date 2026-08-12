<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per check-in/check-out pair, so a day can hold more than one.
 *
 * `attendance` stays as it is — one row per employee per day — because status,
 * the late calculation, the check-in photo and every report already key off it.
 * Its check_in and check_out become first-in and last-out for the day, which is
 * what they already meant when there was only ever one session. Nothing that
 * reads them needs to change.
 *
 * What the day total means does change: summing sessions excludes the gap
 * between them. Staff here do not punch out for the scheduled break, so most
 * days hold a single session; this matters when somebody genuinely leaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            // The parent table is 'attendance', not 'attendances' — it has to be
            // named explicitly or the constraint looks for the wrong table.
            $table->foreignId('attendance_id')
                ->constrained('attendance')
                ->cascadeOnDelete();

            // TIME rather than TIMESTAMP, matching the parent: the date lives on
            // the attendance row. It also sidesteps MySQL's habit of giving the
            // first NOT NULL TIMESTAMP column an implicit
            // ON UPDATE CURRENT_TIMESTAMP, which corrupted ticket_time_logs.
            $table->time('check_in');
            $table->time('check_out')->nullable();

            $table->string('device')->nullable();
            // Per session: each return to the desk is photographed separately
            // when camera check-in is on.
            $table->string('photo_path')->nullable();

            $table->timestamps();
            $table->index(['attendance_id', 'check_in']);
        });

        // Existing days become their single session, so history keeps its hours
        // and the sum below agrees with what those days already reported.
        DB::statement(
            'INSERT INTO attendance_sessions
                (attendance_id, check_in, check_out, device, photo_path, created_at, updated_at)
             SELECT id, check_in, check_out, device, photo_path, NOW(), NOW()
               FROM attendance
              WHERE check_in IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
