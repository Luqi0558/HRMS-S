<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two review steps before a claim is decided: opened, then in processing.
 *
 * They were tracked only in the reviewer's browser, so a claim marked
 * "processing" was back to "pending" on the next load and the employee never
 * knew anyone had looked at it.
 *
 * Kept beside `status` rather than added to its enum. `status` is what moves
 * money — annual usage, reports and payroll all filter on it — and a claim
 * being looked at is still, for all of those, pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_claims', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('status');
            $table->foreignId('viewed_by')->nullable()->after('viewed_at')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('processing_at')->nullable()->after('viewed_by');
            $table->foreignId('processing_by')->nullable()->after('processing_at')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medical_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('viewed_by');
            $table->dropConstrainedForeignId('processing_by');
            $table->dropColumn(['viewed_at', 'processing_at']);
        });
    }
};
