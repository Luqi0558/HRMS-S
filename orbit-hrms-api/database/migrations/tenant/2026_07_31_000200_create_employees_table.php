<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR record, kept separate from `users` (the sign-in identity).
 *
 * They are split because their lifecycles differ: a departed employee keeps an
 * employment record for payroll history and tax reporting long after their
 * login is revoked, and not every user is an employee (a contractor or an
 * external auditor may sign in without an employment record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Nullable so HR can prepare a record before the invitation is
            // sent. Set null on delete rather than cascade — losing the login
            // must not delete the employment history attached to it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Human-facing identifier (ORB-0001). Distinct from the surrogate
            // primary key so it can be reformatted without touching foreign
            // keys, and so it appears predictably on payslips.
            $table->string('employee_code')->unique();

            $table->string('name');
            // NOTE: a plain unique index here also matches soft-deleted rows,
            // so re-hiring someone previously removed would be rejected by the
            // database. Enforce uniqueness over non-deleted rows in the
            // application (a validation rule with whereNull('deleted_at'))
            // rather than here — MySQL has no partial indexes.
            $table->string('email')->index();

            // Job title from the Settings > Designations list. A plain string,
            // not an FK: retiring a designation must not rewrite or block the
            // records of people who currently hold it.
            $table->string('designation')->nullable();
            $table->string('department')->nullable();

            $table->date('birthday')->nullable();
            $table->date('joining_date')->nullable();
            // Set when employment ends; the row itself is retained.
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

            // Every employee list is filtered by status first (active roster,
            // pending invites, exited staff), then usually grouped by
            // department — so a composite in that order serves both, and also
            // serves a status-only filter via its leftmost prefix.
            $table->index(['status', 'department']);

            // Directory search and the designation filter on the Employees
            // screen.
            $table->index('designation');

            // Birthday and anniversary widgets scan a date range across the
            // whole roster; without these they are full table scans.
            $table->index('birthday');
            $table->index('joining_date');

            // Soft-deleted rows are excluded from almost every query, so the
            // planner benefits from being able to skip them by index.
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
