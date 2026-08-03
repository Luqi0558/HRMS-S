<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Indexed, not unique — see the note on employees.email: soft
            // deletes make a database-level unique constraint reject re-hires.
            // Login looks this up on every attempt, so the index matters.
            $table->string('email')->index();
            $table->timestamp('email_verified_at')->nullable();
            // Nullable: an invited user exists before they have chosen a
            // password. The login flow must therefore never treat "no password"
            // as "any password" — see the status check below.
            $table->string('password')->nullable();

            // Mirrors the frontend's role strings (see rolePermissions in
            // src/store/index.js). Kept as a plain column rather than a foreign
            // key so a role can be added to the permission grid without a
            // migration, which is how the frontend already treats it.
            $table->string('role')->default('employee');

            // invited  -> row exists, no password yet, invitation outstanding
            // active   -> can sign in
            // blocked  -> credentials valid but sign-in refused
            $table->enum('status', ['invited', 'active', 'blocked'])->default('invited');

            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            // Employees are deactivated far more often than truly deleted, and
            // their attendance/payroll history has to survive either way.
            $table->softDeletes();

            $table->index(['status', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
