<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('effectiveness_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('role', 60);
            $table->string('department', 120)->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('confidence', 20);
            $table->json('metrics');
            $table->json('evidence');
            $table->string('policy_version', 30);
            $table->timestamp('generated_at');
            // A finalised period is immutable. Regenerating an open month is
            // safe, but a closed monthly snapshot must remain auditable.
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'period_start', 'period_end'], 'effectiveness_period_unique');
            $table->index(['period_start', 'period_end']);
            $table->index(['department', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effectiveness_snapshots');
    }
};
