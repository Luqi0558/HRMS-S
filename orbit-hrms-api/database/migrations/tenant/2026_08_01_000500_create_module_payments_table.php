<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->string('reference')->nullable();
            $table->string('payer')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('logged_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
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