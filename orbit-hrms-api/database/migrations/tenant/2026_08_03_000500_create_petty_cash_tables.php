<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The petty cash tin: money in, money out, and periodic counts.
 *
 * Three tables because they answer different questions. Top-ups are the float
 * added; expenses are what left; reconciliations record what was physically
 * counted on a date. The running balance is approved top-ups minus counted
 * expenses — derived, never stored, so it cannot drift from its own ledger.
 *
 * Amounts are decimal rather than encrypted, unlike payroll: office spend is
 * not sensitive the way an individual's pay is, and keeping it summable lets
 * the balance be computed in SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_top_ups', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('requested_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            // Only an approved top-up counts toward the float. A pending one
            // is a request for money, not money in the tin.
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('approved_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // The balance query filters on status and orders by date.
            $table->index(['status', 'date']);
        });

        Schema::create('petty_cash_expenses', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->string('category');
            $table->string('payee')->nullable();
            $table->text('description')->nullable();

            $table->string('receipt_path')->nullable();

            $table->foreignId('logged_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            // recorded - below the threshold, already spent
            // pending  - above it, waiting on sign-off
            // rejected - refused; the only status that does NOT count against
            //            the balance, because the money is not owed
            $table->enum('status', ['recorded', 'pending', 'approved', 'rejected'])
                ->default('recorded');

            $table->foreignId('approved_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_comment')->nullable();

            $table->timestamps();

            $table->index(['status', 'date']);
            // Spend-by-category is the common report.
            $table->index('category');
        });

        Schema::create('petty_cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->date('date');

            // What was physically counted, and what the ledger said at that
            // moment. The book balance is stored rather than recomputed: a
            // later backdated expense would change today's arithmetic, and a
            // past count must keep showing the variance actually found.
            $table->decimal('counted_amount', 12, 2);
            $table->decimal('book_balance', 12, 2);
            $table->decimal('variance', 12, 2);

            $table->text('note')->nullable();
            $table->foreignId('counted_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_reconciliations');
        Schema::dropIfExists('petty_cash_expenses');
        Schema::dropIfExists('petty_cash_top_ups');
    }
};
