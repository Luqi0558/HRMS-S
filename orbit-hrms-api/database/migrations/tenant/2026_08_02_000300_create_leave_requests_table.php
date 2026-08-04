<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['Annual leave', 'Sick leave', 'Casual leave', 'Unpaid leave']);

            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 1)->unsigned();

            $table->text('reason')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])
                ->default('pending');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'from_date']);
            $table->index(['from_date', 'to_date']);
            $table->index('deleted_at');
        });

        Schema::create('leave_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->enum('stage', ['lead', 'hr']);

            $table->foreignId('approver_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->enum('decision', ['approved', 'rejected']);
            $table->text('comment')->nullable();

            $table->timestamps();
            $table->unique(['leave_request_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approvals');
        Schema::dropIfExists('leave_requests');
    }
};