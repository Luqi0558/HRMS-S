<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payroll lifecycle beyond issuing a slip.
 *
 * A slip being generated, released for payment, and actually paid are three
 * different facts, and the payroll screen already treats them that way. They
 * need separate storage: "approved" without "paid" is money owed, and a
 * finance team needs to see that gap rather than infer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->enum('status', ['generated', 'approved', 'paid'])
                ->default('generated')
                ->after('period');

            $table->foreignId('approved_by')->nullable()->after('issued_by')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('issued_at');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->date('paid_on')->nullable();
            $table->text('payment_notes')->nullable();
            $table->foreignId('paid_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->index(['period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->dropIndex(['period', 'status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn([
                'status', 'approved_at', 'payment_method', 'payment_reference',
                'paid_on', 'payment_notes', 'paid_at',
            ]);
        });
    }
};