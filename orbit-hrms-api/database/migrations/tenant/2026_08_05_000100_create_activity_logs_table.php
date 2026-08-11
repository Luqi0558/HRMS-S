<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail: who did what, and to which record.
 *
 * Until now every logActivity() call in the SPA wrote to a reactive array and
 * nothing else — seventeen modules recording leave approvals, payroll
 * releases and petty-cash decisions into memory that a page refresh emptied.
 * For an HR system that is the one log that has to survive.
 *
 * Deliberately append-only in use: there is no update path, and the service
 * exposes no delete. An audit trail that can be edited is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Who. Nullable and nullOnDelete so removing an employee does not
            // erase the record of what they did — the name is denormalised
            // below for exactly that case.
            $table->foreignId('actor_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            // Kept as text as well as a foreign key: a log that reads
            // "someone deleted a payslip" is useless, and the employee row may
            // be gone by the time anyone reads it.
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 40)->nullable();

            // 'leave', 'payroll', 'petty cash', 'messages'…
            $table->string('module', 60);
            // 'leave approved', 'payroll released'…
            $table->string('action', 120);
            $table->text('details')->nullable();

            // What it happened to, so the log can link back to the record.
            // Not a real foreign key: the target may live in any of a dozen
            // tables, and constraining it to one would be a lie.
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_type', 60)->nullable();

            $table->timestamps();

            // The log is read newest-first, filtered by module or by actor.
            $table->index('created_at');
            $table->index(['module', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
