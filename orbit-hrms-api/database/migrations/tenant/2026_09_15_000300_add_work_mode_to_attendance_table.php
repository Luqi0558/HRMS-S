<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            // Work location is independent from attendance status: a remote
            // employee can still be on time, late, or absent. Existing rows
            // are historical office attendance unless explicitly recorded
            // otherwise, so the default is intentional.
            $table->string('work_mode', 20)->default('office')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('work_mode');
        });
    }
};
