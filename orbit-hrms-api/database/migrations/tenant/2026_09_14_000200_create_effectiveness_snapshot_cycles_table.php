<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('effectiveness_snapshot_cycles', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'reviewed', 'finalized'])->default('draft');
            $table->string('policy_version', 30);
            $table->foreignId('reviewed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['period_start', 'period_end'], 'effectiveness_cycle_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effectiveness_snapshot_cycles');
    }
};
