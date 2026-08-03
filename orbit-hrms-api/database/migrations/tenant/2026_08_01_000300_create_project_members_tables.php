<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team membership at both levels. These two tables are what `scope: 'team'`
 * resolves through, and they back the "Add employee to project" modal.
 *
 * Two tables rather than one polymorphic pivot: the foreign keys stay real, so
 * the database can enforce that a member row cannot outlive its project or
 * module. A polymorphic table would push that guarantee into application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Someone cannot be on the same project twice.
            $table->unique(['project_id', 'employee_id']);
            // "Which projects is this person on?" — the team-scope lookup,
            // which runs far more often than listing one project's members.
            $table->index('employee_id');
        });

        Schema::create('project_module_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_module_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_module_members');
        Schema::dropIfExists('project_members');
    }
};
