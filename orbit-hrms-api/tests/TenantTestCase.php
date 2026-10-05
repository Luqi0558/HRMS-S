<?php

namespace Tests;

use App\Models\Tenant;

/**
 * Base for tests that exercise tenant-scoped code.
 *
 * Every test gets its own freshly-migrated tenant database, created in setUp
 * and dropped in tearDown. That is slower than an in-memory SQLite database,
 * but it is the only way to test what actually ships: the schema uses MySQL
 * enums and the tenancy layer swaps connections at runtime, neither of which
 * SQLite reproduces faithfully.
 */
abstract class TenantTestCase extends TestCase
{
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Unique per test so a crashed run cannot leave a database that the
        // next run then reuses with stale rows.
        $this->tenant = Tenant::create([
            'id' => 'test'.uniqid(),
            'name' => 'Test Co',
            'slug' => 'test-'.uniqid(),
        ]);

        tenancy()->initialize($this->tenant);

        // Most feature tests exercise attendance-adjacent business rules, not
        // photo upload. Keep that shared fixture lightweight; the camera
        // policy itself is covered explicitly in AttendancePhotoTest.
        app(\App\Services\SettingsService::class)->put(
            'attendance',
            'cameraCheckinRequired',
            false,
        );
    }

    protected function tearDown(): void
    {
        // end() before delete() so the connection is not left pointing at a
        // database that is about to be dropped.
        tenancy()->end();
        $this->tenant->delete();

        parent::tearDown();
    }

    /** Headers the SPA sends on every request. */
    protected function tenantHeaders(array $extra = []): array
    {
        return array_merge([
            'Accept' => 'application/json',
            'X-Tenant' => $this->tenant->id,
        ], $extra);
    }
}
