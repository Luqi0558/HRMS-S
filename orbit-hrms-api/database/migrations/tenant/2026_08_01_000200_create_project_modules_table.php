<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deliverable within a project ("Orbit HRMS", "Attendance System").
 *
 * Modules carry the money (budget, commission) and the delivery state, because
 * that is the unit a client is invoiced for — a project category groups them
 * but is not itself billable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_modules', function (Blueprint $table) {
            $table->id();

            // Cascade: a module has no meaning without its project, and the
            // frontend nests them the same way.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // The Team Lead running this squad. Optional — modules without one
            // are managed directly by the project manager, which is why
            // `owned` scope has to check both lead_id and the parent's
            // manager_id rather than assuming a lead exists.
            $table->foreignId('lead_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->enum('status', ['active', 'completed'])->default('active');
            $table->date('delivered_at')->nullable();

            // Decimal, not float: this is money and it is invoiced. Float
            // rounding would produce off-by-a-paisa totals.
            $table->decimal('budget', 14, 2)->default(0);

            // Commission the company earns on delivery. Percent of budget, or
            // a flat amount — the type column decides how `value` is read.
            $table->enum('commission_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('commission_value', 12, 2)->default(0);
            $table->enum('commission_status', ['pending', 'approved', 'paid'])
                ->default('pending');

            $table->timestamps();
            $table->softDeletes();

            // Slugs appear in URLs as /projects/{project}/{module}, so they
            // need only be unique within their project.
            $table->unique(['project_id', 'slug']);
            // "Modules I lead" — the owned-scope lookup for a team lead.
            $table->index(['lead_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_modules');
    }
};
