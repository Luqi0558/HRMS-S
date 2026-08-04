<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->foreignId('lead_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->enum('status', ['active', 'completed'])->default('active');
            $table->date('delivered_at')->nullable();
            $table->decimal('budget', 14, 2)->default(0);
            $table->enum('commission_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('commission_value', 12, 2)->default(0);
            $table->enum('commission_status', ['pending', 'approved', 'paid'])
                ->default('pending');

            $table->timestamps();
            $table->softDeletes();
            $table->unique(['project_id', 'slug']);
            $table->index(['lead_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_modules');
    }
};
