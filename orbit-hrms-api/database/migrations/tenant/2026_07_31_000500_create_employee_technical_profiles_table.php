<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The engineering half of a profile: primary stack, experience, and links.
 * Multi-valued stacks and skills live in their own tables below rather than in
 * JSON columns here, so "who knows React?" stays an indexed query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_technical_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->unique()
                ->constrained()->cascadeOnDelete();

            // Job title as captured on the onboarding form's technical step.
            // Duplicated from employees.designation on purpose: this is what
            // the employee submitted, employees.designation is what HR
            // confirmed, and the two can legitimately differ until reviewed.
            $table->string('designation')->nullable();

            // Set on the Profile screen only — the onboarding form collects
            // `stacks` but no primary. Nullable for that reason.
            $table->string('primary_stack')->nullable();

            // Decimal, not integer — "2.5 years" is a normal answer. unsigned
            // because negative experience is not a thing.
            $table->decimal('experience_years', 4, 1)->unsigned()->nullable();

            // Profile-only fields; the onboarding form does not ask for these.
            $table->enum('employment_type', [
                'Full-time', 'Part-time', 'Contract', 'Internship',
            ])->nullable();
            $table->enum('probation_status', ['Probation', 'Confirmed'])->nullable();

            $table->string('github_url')->nullable();
            $table->string('portfolio_url')->nullable();
            $table->string('linkedin_url')->nullable();

            // When the invitee completed the onboarding wizard. Null means the
            // profile was built from the Profile screen instead. Mirrors
            // onboarding.submittedAt in the frontend payload.
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();
        });

        // Many-to-many: an employee works across several stacks.
        Schema::create('employee_stacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('stack');
            $table->timestamps();

            // Listing the same stack twice on one profile is always a bug.
            $table->unique(['employee_id', 'stack']);
            // Drives "find everyone who knows X" without scanning.
            $table->index('stack');
        });

        // Skills carry a proficiency, which is why they are a separate table
        // from stacks rather than sharing one. This replaces the frontend's
        // parallel `skills` array + `skillLevels` map, where a skill could
        // exist with no matching level entry.
        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('skill');

            // Nullable, NOT defaulted: the onboarding wizard submits a flat
            // skills array with no levels, while the Profile screen adds them
            // via skillLevels. Defaulting to 'Beginner' would fabricate a
            // proficiency the employee never claimed.
            $table->enum('level', [
                'Beginner',
                'Intermediate',
                'Advanced',
                'Expert',
            ])->nullable();

            // Mirrors the frontend's separate `currentlyLearning` array.
            $table->boolean('is_learning')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'skill']);
            // Composite: "who is learning X" and "who knows X" both hit this.
            $table->index(['skill', 'level']);
        });
    }

    public function down(): void
    {
        // Reverse creation order: children before the table they reference.
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('employee_stacks');
        Schema::dropIfExists('employee_technical_profiles');
    }
};
