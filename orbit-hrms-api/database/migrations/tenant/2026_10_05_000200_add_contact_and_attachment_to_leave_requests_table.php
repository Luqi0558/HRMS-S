<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to reach someone while they are away, and the note that supports the
 * request — a sick note, typically.
 *
 * The leave form has collected both for some time, but neither was ever sent:
 * approvers always saw "No attachment uploaded", and a medical note never
 * reached HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('contact')->nullable()->after('reason');
            $table->string('attachment_path')->nullable()->after('contact');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['contact', 'attachment_path', 'attachment_name']);
        });
    }
};
