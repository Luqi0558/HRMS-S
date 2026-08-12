<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ticket's identifier in whatever system the work is tracked against
 * elsewhere — "LPS-M-683".
 *
 * Free text rather than a generated code: the point is that it matches what
 * somebody else's board says, so this application is in no position to invent
 * it. Nullable throughout, since plenty of tickets will never have one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('reference', 60)->nullable()->after('title');
            // Not unique: two tickets may legitimately track the same external
            // item, and a partially-filled column would make a unique index a
            // source of confusing failures rather than a safeguard.
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['reference']);
            $table->dropColumn('reference');
        });
    }
};
