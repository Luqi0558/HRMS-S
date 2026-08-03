<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal details captured during onboarding (Profile.vue's personal section).
 *
 * Split from `employees` rather than widening it, for two reasons: these
 * columns hold national ID, bank and tax data that most queries have no
 * business selecting, and a one-to-one table makes it straightforward to grant
 * narrower database access or encrypt this table alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();

            // unique() enforces the one-to-one: an employee has at most one
            // personal profile.
            $table->foreignId('employee_id')->unique()
                ->constrained()->cascadeOnDelete();

            $table->string('phone')->nullable();

            // National ID, bank account and tax number are the sensitive three.
            // Store them encrypted at the application layer (Laravel's
            // `encrypted` cast) — hence text rather than a sized string, since
            // ciphertext is far longer than the plaintext it replaces.
            //
            // NOTE: encrypted columns cannot be indexed or searched with LIKE.
            // If "find employee by CNIC" is ever needed, add a blind-index
            // column (HMAC of the normalised value) rather than dropping the
            // encryption.
            $table->text('cnic')->nullable();
            $table->text('bank_account')->nullable();
            $table->text('tax_number')->nullable();

            // One free-text field, matching the frontend exactly: both
            // AcceptInvite and Profile collect this as a single input
            // placeholdered "Name and phone number". Splitting it into
            // name/phone/relation columns here would mean the API had to parse
            // that string on the way in and reassemble it on the way out —
            // a lossy round trip. Split it when the form splits it.
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
