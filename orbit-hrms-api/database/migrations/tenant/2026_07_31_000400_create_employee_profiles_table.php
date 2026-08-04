<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->unique()
                ->constrained()->cascadeOnDelete();

            $table->string('phone')->nullable();
            $table->text('cnic')->nullable();
            $table->text('bank_account')->nullable();
            $table->text('tax_number')->nullable();
            $table->string('emergency_contact')->nullable();

            $table->text('address')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
