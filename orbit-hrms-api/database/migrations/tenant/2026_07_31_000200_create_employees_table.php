<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_code')->unique();

            $table->string('name');
            $table->string('email')->index();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();

            $table->date('birthday')->nullable();
            $table->date('joining_date')->nullable();
            $table->date('exit_date')->nullable();

            $table->enum('employment_type', [
                'Full-time',
                'Part-time',
                'Contract',
                'Internship',
            ])->default('Full-time');

            $table->enum('probation_status', [
                'Probation',
                'Confirmed',
            ])->default('Probation');

            $table->enum('status', ['invited', 'active', 'blocked', 'exited'])
                ->default('invited');

            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'department']);
            $table->index('designation');
            $table->index('birthday');
            $table->index('joining_date');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
