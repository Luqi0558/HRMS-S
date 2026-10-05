<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR's record of following up an attendance exception.
 *
 * The HR dashboard had a "Save follow-up" dialog that showed "Follow-up
 * recorded" and stored nothing. A follow-up is an internal note — "called,
 * asked for the reason, reminder sent" — and the trail of them is what a
 * warning or appraisal later rests on, so it is kept here, visible only to
 * people who supervise that employee's attendance. A message to the employee
 * is a separate, optional act and is linked rather than merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // The day's record when there is one; an absence has none.
            $table->foreignId('attendance_id')->nullable()
                ->constrained('attendance')->nullOnDelete();
            $table->date('date');

            $table->enum('issue', ['late', 'absent', 'missing_checkout', 'early_leave', 'other']);
            $table->enum('action', ['note', 'called', 'emailed', 'met', 'reminder', 'warning'])
                ->default('note');
            $table->text('note');

            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            // Set when the follow-up also messaged the employee.
            $table->foreignId('message_id')->nullable()
                ->constrained('messages')->nullOnDelete();

            $table->timestamps();
            $table->index(['employee_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_follow_ups');
    }
};
