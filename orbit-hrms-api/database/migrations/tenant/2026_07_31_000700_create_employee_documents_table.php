<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding paperwork: CV, offer letter, signed contract, certificates.
 *
 * The bytes are handled by spatie/laravel-medialibrary (see the `media` table)
 * — this table records the HR state around each file, which medialibrary has no
 * concept of: which document type it satisfies, whether HR has verified it, and
 * when it expires. Without that, "has this employee returned a signed contract?"
 * cannot be answered without a human opening every attachment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Exactly the upload slots the two frontend forms expose, using
            // snake_case of their existing keys:
            //   AcceptInvite: photo, cv, cnicFront, cnicBack, education,
            //                 experience
            //   Profile:      photo, cv, offerLetter, contract, certificates,
            //                 experienceLetters
            // `experience` and `experience_letters` are kept distinct because
            // the two forms treat them as separate slots — collapsing them
            // would make a Profile upload silently overwrite an onboarding one.
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

            // Points at the medialibrary row holding the actual file. Nullable
            // so HR can mark a document as required/pending before it arrives.
            $table->foreignId('media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            $table->enum('status', ['pending', 'submitted', 'verified', 'rejected'])
                ->default('pending');

            // Who signed off, and when. Null while unverified.
            $table->foreignId('verified_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // For documents that lapse (visas, certifications). Null = no expiry.
            $table->date('expires_at')->nullable();

            $table->timestamps();

            // One row per slot per employee. The frontend models documents as
            // named slots (profile.photo, profile.cv, ...), so two rows of the
            // same type for one employee would make "which is the current CV?"
            // ambiguous. Re-uploading replaces the media_id on this row.
            $table->unique(['employee_id', 'type']);

            // "Which documents is this employee still missing?"
            $table->index(['employee_id', 'status']);
            // Drives an expiry reminder job. Partial in spirit — most rows are
            // null here, and MySQL skips nulls in a secondary index scan.
            $table->index('expires_at');
            // HR queue: everything awaiting verification, newest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
