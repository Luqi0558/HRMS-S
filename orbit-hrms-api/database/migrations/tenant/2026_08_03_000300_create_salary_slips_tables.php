<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_slip_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('period');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('reviewed_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();

            $table->timestamps();
            $table->unique(['employee_id', 'period']);
            $table->index('status');
        });

        Schema::create('salary_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_slip_request_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->date('period');
            $table->text('base_salary');
            $table->text('house_allowance')->nullable();
            $table->text('medical_allowance')->nullable();
            $table->text('bonus')->nullable();
            $table->text('tax_deduction')->nullable();
            $table->text('attendance_deduction')->nullable();
            $table->text('advance_deduction')->nullable();
            $table->text('net_pay');

            $table->text('notes')->nullable();

            $table->foreignId('issued_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('issued_at');

            $table->timestamps();
            $table->unique(['employee_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_slips');
        Schema::dropIfExists('salary_slip_requests');
    }
};