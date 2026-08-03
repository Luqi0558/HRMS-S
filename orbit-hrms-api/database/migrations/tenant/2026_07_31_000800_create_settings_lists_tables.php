<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two option lists managed on the Settings screen. These currently live as
 * in-memory arrays in the Vue store, so every edit is lost on refresh.
 *
 * Employee records reference these by string rather than foreign key (see the
 * `designation` column on employees). That is deliberate: retiring an option
 * here should stop it being *offered*, not rewrite the history of everyone who
 * already holds it. `is_active` expresses exactly that — retire without delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Retired options stay for reference but leave the dropdowns.
            $table->boolean('is_active')->default(true);
            // Manual ordering for the picker; ties fall back to name.
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Every read of these tables is "active options, in display order".
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('stacks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Every read of these tables is "active options, in display order".
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stacks');
        Schema::dropIfExists('designations');
    }
};
