<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period')->unique();
            $table->enum('status', ['draft', 'submitted', 'released', 'paid'])->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Requests and their immutable slip snapshots both belong to the same
        // monthly run. Keeping these nullable makes the change safe for
        // historical records created before payroll runs existed.
        Schema::table('salary_slip_requests', function (Blueprint $table) {
            $table->foreignId('payroll_run_id')->nullable()
                ->after('employee_id')->constrained('payroll_runs')->nullOnDelete();
        });

        Schema::table('salary_slips', function (Blueprint $table) {
            $table->foreignId('payroll_run_id')->nullable()
                ->after('employee_id')->constrained('payroll_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
        });

        Schema::table('salary_slip_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
        });

        Schema::dropIfExists('payroll_runs');
    }
};
