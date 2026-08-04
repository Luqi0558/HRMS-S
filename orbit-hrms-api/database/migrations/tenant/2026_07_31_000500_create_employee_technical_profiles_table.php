<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_technical_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->unique()
                ->constrained()->cascadeOnDelete();
            $table->string('designation')->nullable();
            $table->string('primary_stack')->nullable();
            $table->decimal('experience_years', 4, 1)->unsigned()->nullable();
            $table->enum('employment_type', [
                'Full-time', 'Part-time', 'Contract', 'Internship',
            ])->nullable();
            $table->enum('probation_status', ['Probation', 'Confirmed'])->nullable();

            $table->string('github_url')->nullable();
            $table->string('portfolio_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();
        });

        Schema::create('employee_stacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('stack');
            $table->timestamps();
            $table->unique(['employee_id', 'stack']);
            $table->index('stack');
        });
        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('skill');
            $table->enum('level', [
                'Beginner',
                'Intermediate',
                'Advanced',
                'Expert',
            ])->nullable();
            $table->boolean('is_learning')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'skill']);
            $table->index(['skill', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('employee_stacks');
        Schema::dropIfExists('employee_technical_profiles');
    }
};
