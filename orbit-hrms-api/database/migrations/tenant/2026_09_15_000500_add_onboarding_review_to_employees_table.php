<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('onboarding_review_status', ['pending', 'verified', 'correction_requested'])
                ->default('pending')
                ->after('probation_status');
            $table->foreignId('onboarding_reviewed_by')
                ->nullable()
                ->after('onboarding_review_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('onboarding_reviewed_at')->nullable()->after('onboarding_reviewed_by');
            $table->text('onboarding_correction_note')->nullable()->after('onboarding_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('onboarding_reviewed_by');
            $table->dropColumn([
                'onboarding_review_status',
                'onboarding_reviewed_at',
                'onboarding_correction_note',
            ]);
        });
    }
};
