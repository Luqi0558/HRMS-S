<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('base_salary');
            $table->text('house_allowance')->nullable();
            $table->text('medical_allowance')->nullable();

            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->string('reason')->nullable();

            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salaries');
    }
};
