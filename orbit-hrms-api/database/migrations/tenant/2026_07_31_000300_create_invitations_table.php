<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the accept-invite flow. The frontend currently identifies the invitee
 * by scanning for any employee with status 'invited', which is a placeholder —
 * with two outstanding invitations it picks the wrong person, and it lets
 * anyone who loads the page complete someone else's onboarding.
 *
 * A row here is the capability: possession of the token is what authorises
 * setting that account's first password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Duplicated from the employee so the invite email address is
            // pinned at send time. If HR later corrects a typo on the employee
            // record, the already-sent link stays bound to where it was sent.
            $table->string('email');

            // A SHA-256 hash of the token, never the token itself — the raw
            // value goes in the invite email and is not recoverable from the
            // database. A leaked table dump then yields no usable links.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            // Non-null means consumed. Enforces single use: a forwarded link
            // cannot be replayed after the invitee has onboarded.
            $table->timestamp('accepted_at')->nullable();

            $table->foreignId('invited_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // token_hash is already uniquely indexed above, which is what the
            // accept-invite lookup hits; the accepted_at/expires_at checks then
            // run against that single row, so no composite is needed for it.

            // "Which invitations are still outstanding?" — the HR reminder job
            // and the pending-invites list.
            $table->index(['accepted_at', 'expires_at']);
            // Resending an invite looks up prior ones for the employee.
            $table->index(['employee_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
