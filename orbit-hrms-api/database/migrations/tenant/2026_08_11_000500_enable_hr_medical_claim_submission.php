<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing tenants have a stored permission grid, so changing the
        // seeder alone would only fix new installations.
        DB::table('role_permissions')
            ->where('role', 'hr')
            ->where('module', 'Medical Request')
            ->where('create', false)
            ->update(['create' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not revoke a permission an administrator may have deliberately
        // retained after this migration was applied.
    }
};
