<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client payments received against a module's budget.
 *
 * Its own table rather than a JSON column on the module, because these are
 * financial records: they need an audit trail of who logged each one, and
 * "total received" must be a SUM the database can compute rather than an
 * application-side reduce over a blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_module_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->date('paid_on');

            $table->string('method')->nullable();
            // Cheque number, transfer reference — what reconciliation matches
            // against a bank statement.
            $table->string('reference')->nullable();
            $table->string('payer')->nullable();
            $table->text('notes')->nullable();

            // Who recorded it. Retained when they leave: removing the audit
            // trail from a financial record defeats its purpose.
            $table->foreignId('logged_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();
            // Financial records are never hard-deleted — a correction is a
            // reversing entry, and the original must remain visible.
            $table->softDeletes();

            // Received-to-date per module, and payment history by date.
            $table->index(['project_module_id', 'paid_on']);
            $table->index('paid_on');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_payments');
    }
};
