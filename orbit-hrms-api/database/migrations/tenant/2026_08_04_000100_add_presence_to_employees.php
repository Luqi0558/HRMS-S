<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manual half of presence.
 *
 * Online and offline are not stored: they come from whether a browser is
 * subscribed to the presence channel, which Pusher already tracks. Storing
 * them would mean maintaining a last_seen column and a job to expire it, and
 * it would still be wrong the moment someone closed a laptop lid without
 * signing out.
 *
 * What a person *chooses* to say — busy, away, appear offline — cannot be
 * inferred and so does live here. It outlives the connection: setting Busy and
 * closing the tab should still read Busy when they come back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // null means "no override" — presence is then whatever the
            // connection says. An explicit value always wins.
            $table->enum('presence_status', ['available', 'busy', 'away', 'invisible'])
                ->nullable()
                ->after('status');

            // Free text alongside the status: "In a meeting until 3".
            $table->string('presence_message', 120)->nullable()->after('presence_status');

            $table->timestamp('presence_updated_at')->nullable()->after('presence_message');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['presence_status', 'presence_message', 'presence_updated_at']);
        });
    }
};
