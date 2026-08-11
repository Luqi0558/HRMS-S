<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The asset register, and who has had each item.
 *
 * Two tables because "who holds this laptop now" and "who has held it" are
 * different questions, and only the second survives a handover. Keeping just
 * the current assignee would mean an asset returned damaged has no record of
 * who had it — which is the one thing the register exists to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 60);

            // Unique where present: two rows with one serial means the register
            // has lost track of a physical item. Nullable because accessories
            // often have none.
            $table->string('serial_number')->nullable()->unique();

            $table->string('condition', 40)->default('Good');

            // available - in stock
            // assigned  - someone holds it
            // retired   - written off, kept for the history
            $table->enum('status', ['available', 'assigned', 'retired'])->default('available');

            // The current holder, denormalised from the open assignment below
            // so the common query — "list assets with who has them" — does not
            // need a join to a filtered subquery on every read.
            $table->foreignId('assigned_to')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category']);
            $table->index('assigned_to');
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            // Copied in so a returned asset still records who held it after
            // that employee is offboarded.
            $table->string('employee_name')->nullable();

            $table->date('issued_on');
            $table->date('return_due')->nullable();

            // Null while the asset is still out. This is what makes an
            // assignment "open", and what the unique index below relies on.
            $table->date('returned_on')->nullable();

            $table->string('condition_out', 40)->nullable();
            $table->string('condition_in', 40)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('issued_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();

            // The history for one asset, newest first.
            $table->index(['asset_id', 'issued_on']);
            // What one person currently holds.
            $table->index(['employee_id', 'returned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
    }
};
