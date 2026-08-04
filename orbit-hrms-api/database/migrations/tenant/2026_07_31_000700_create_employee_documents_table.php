<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'photo',
                'cv',
                'cnic_front',
                'cnic_back',
                'education',
                'experience',
                'offer_letter',
                'contract',
                'certificates',
                'experience_letters',
                'other',
            ]);

            $table->string('title')->nullable();
            $table->foreignId('media_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type')->nullable();

            $table->enum('status', ['pending', 'submitted', 'verified', 'rejected'])
                ->default('pending');

            $table->foreignId('verified_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('expires_at')->nullable();

            $table->timestamps();
            $table->unique(['employee_id', 'type']);
            $table->index(['employee_id', 'status']);
            $table->index('expires_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};