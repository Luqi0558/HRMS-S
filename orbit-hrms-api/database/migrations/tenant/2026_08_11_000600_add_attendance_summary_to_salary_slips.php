<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->text('attendance_summary')->nullable()->after('attendance_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->dropColumn('attendance_summary');
        });
    }
};
