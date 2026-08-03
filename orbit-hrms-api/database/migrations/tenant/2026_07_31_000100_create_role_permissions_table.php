<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors state.rolePermissions from the Vue store one-for-one, so the same
 * grid drives the API and the UI instead of the two drifting apart.
 *
 * Deliberately NOT spatie/laravel-permission: that package models a flat
 * permission list, while this app asks two questions per row — does the role
 * hold this action, and over which records (`scope`). Encoding scope in
 * spatie would mean permission names like "projects.view.team", which is
 * exactly the string-parsing the frontend's table structure avoids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();

            // Plain strings, matching the frontend. A new role is a new set of
            // rows, not a schema change.
            $table->string('role');
            $table->string('module');

            // The action columns. Every one defaults to deny, so a row added
            // for a new module grants nothing until explicitly opened up.
            $table->boolean('view')->default(false);
            $table->boolean('create')->default(false);
            $table->boolean('approve')->default(false);
            $table->boolean('finance')->default(false);

            // Which records the actions apply to:
            //   all   - every record in the company
            //   owned - records this user manages (managerId / leadId)
            //   team  - records of a project/module they belong to
            //   self  - their own records only
            $table->enum('scope', ['all', 'owned', 'team', 'self'])->default('self');

            $table->timestamps();

            // One row per role/module pair — two contradictory rows for the
            // same pair would make the effective permission order-dependent.
            $table->unique(['role', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
