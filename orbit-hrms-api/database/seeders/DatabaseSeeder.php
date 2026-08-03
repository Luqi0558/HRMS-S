<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs inside a tenant context: `php artisan tenants:seed`.
 *
 * Plain `db:seed` would write to the central database, which holds only
 * tenants and domains — none of these tables exist there.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Required for the app to function at all: without the permission grid
        // every can() check denies, so a signed-in user reaches no page.
        $this->call([
            RolePermissionSeeder::class,
            SettingsListSeeder::class,
        ]);

        // Demo accounts share a known password, so they are gated on
        // environment rather than left to a deployer to remember to skip.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoUserSeeder::class);
            // After the users, since it resolves employees by email.
            $this->call(DemoProjectSeeder::class);
        } else {
            $this->command?->warn('Skipping DemoUserSeeder outside local/testing.');
        }
    }
}
