<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->string('module');
            $table->boolean('view')->default(false);
            $table->boolean('create')->default(false);
            $table->boolean('approve')->default(false);
            $table->boolean('finance')->default(false);
            $table->enum('scope', ['all', 'owned', 'team', 'self'])->default('self');

            $table->timestamps();
            $table->unique(['role', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
