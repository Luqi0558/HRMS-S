<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_module_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['todo', 'in_progress', 'review', 'done', 'blocked'])
                ->default('todo');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])
                ->default('medium');
            $table->foreignId('assignee_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->date('deadline')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_module_id', 'status']);
            $table->index(['assignee_id', 'status']);
            $table->index(['status', 'deadline']);
            $table->index(['project_id', 'status']);
            $table->index('deleted_at');
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('ticket_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();
            $table->index(['employee_id', 'ended_at']);
            $table->index(['ticket_id', 'started_at']);
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