<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Hub: announcements and policies.
 *
 * The third table exists because the UI already promises it — a policy shows
 * an "Acknowledgment required" badge, and until now nothing could record one.
 * A compliance flag nobody can satisfy is worse than no flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');

            // Free text rather than an enum: "All company", "Engineering",
            // "Employees and Managers" — companies word this how they like,
            // and constraining it would force a migration for a new phrasing.
            $table->string('audience', 80)->default('All company');
            $table->enum('priority', ['normal', 'high'])->default('normal');

            $table->foreignId('author_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            // Kept so an announcement still names its author after they leave.
            $table->string('author_name')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Read newest-first, occasionally filtered by priority.
            $table->index('published_at');
            $table->index(['priority', 'published_at']);
        });

        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category', 60)->default('HR');

            // A string, not a number: "1.2" and "2.0-draft" are both versions
            // people write, and neither is a decimal.
            $table->string('version', 20)->default('1.0');
            $table->text('summary');
            $table->text('body')->nullable();

            $table->boolean('acknowledgment_required')->default(false);

            $table->foreignId('owner_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->string('owner_name')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
        });

        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Which version they agreed to. A policy reissued at 2.0 needs
            // acknowledging again — an acknowledgement of 1.0 says nothing
            // about the new text.
            $table->string('version', 20);

            $table->timestamp('acknowledged_at');
            $table->timestamps();

            // One acknowledgement per person per version. Re-reading a policy
            // must not create a second row, and the unique key is what makes
            // "who has not acknowledged this" answerable.
            $table->unique(['policy_id', 'employee_id', 'version']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('announcements');
    }
};
