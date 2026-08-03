<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets and their two child collections.
 *
 * Comments and time logs are separate tables rather than JSON columns on the
 * ticket: both are appended constantly by different users, and JSON would mean
 * read-modify-write on the whole blob for every comment — with the last writer
 * silently discarding a concurrent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // project_id is denormalised from the module for query speed: the
            // board filters by project constantly, and joining through modules
            // on every read is avoidable work. The application keeps it in step
            // — a ticket cannot change module without changing project.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_module_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Matches statusMeta in src/data/mock.js exactly, so the frontend's
            // labels and colours keep working without a mapping layer.
            $table->enum('status', ['todo', 'in_progress', 'review', 'done', 'blocked'])
                ->default('todo');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])
                ->default('medium');

            // Null on delete rather than cascade: an unassigned ticket is a
            // normal state, and deleting an employee must not delete the work.
            $table->foreignId('assignee_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->date('deadline')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The kanban board: one module's tickets grouped by status.
            $table->index(['project_module_id', 'status']);
            // "My tickets" on the employee dashboard.
            $table->index(['assignee_id', 'status']);
            // Overdue queries scan by deadline across a status filter.
            $table->index(['status', 'deadline']);
            $table->index(['project_id', 'status']);
            $table->index('deleted_at');
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            // Kept when the author leaves, so the discussion stays readable.
            $table->foreignId('author_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            // Comments are always read as a thread, oldest first.
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('ticket_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at');
            // Null means the timer is still running. The dashboard finds an
            // active timer by looking for exactly this.
            $table->timestamp('ended_at')->nullable();

            // Written on stop so reports can SUM() instead of computing a
            // difference per row across a date range.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            // "Is this person's timer running?" — checked on every dashboard
            // load, so it must not scan.
            $table->index(['employee_id', 'ended_at']);
            $table->index(['ticket_id', 'started_at']);
            // Timesheet aggregation over a date range.
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_time_logs');
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('tickets');
    }
};
