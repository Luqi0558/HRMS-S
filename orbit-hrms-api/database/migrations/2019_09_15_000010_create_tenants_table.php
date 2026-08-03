<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();

            // Mirrored in Tenant::getCustomColumns(). Anything not listed there
            // is stored in the `data` JSON blob instead of a real column.
            $table->string('name');
            $table->string('slug')->unique();
            // suspended keeps the row (and its database) but blocks sign-in, so
            // a non-paying company can be restored without a data restore.
            $table->enum('status', ['active', 'suspended'])->default('active');

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
