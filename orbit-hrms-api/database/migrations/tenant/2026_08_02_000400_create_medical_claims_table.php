<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->decimal('amount', 12, 2)->unsigned();

            $table->text('description')->nullable();
            $table->date('incurred_on')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])
                ->default('pending');
            $table->string('receipt_path')->nullable();

            $table->foreignId('reviewed_by')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->decimal('approved_amount', 12, 2)->unsigned()->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['employee_id', 'incurred_on']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_claims');
    }
};