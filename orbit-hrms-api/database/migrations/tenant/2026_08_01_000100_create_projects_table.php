<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project category ("Internal", "Freelance"). The UI calls these project
 * sections or categories; modules hang beneath them and tickets beneath those.
 *
 * project.manager_id and module.lead_id are what `scope: 'owned'` resolves
 * through, and project_members / module_members are what `scope: 'team'`
 * resolves through — so this table is the foundation for nearly every
 * permission check in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Used in URLs (/projects/internal), so it must be stable and
            // unique rather than derived from the name on every request.
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // The manager who owns this project. Null is allowed so a project
            // survives its manager being removed; restrictOnDelete would block
            // offboarding, cascade would delete the project's whole history.
            $table->foreignId('manager_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->enum('status', ['active', 'archived'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            // "Projects I manage" — the owned-scope lookup, run on every
            // permission check for a manager.
            $table->index(['manager_id', 'status']);
            $table->index('status');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
