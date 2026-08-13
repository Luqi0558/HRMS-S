<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The prune command deletes tenant databases, so the thing worth testing is
 * not that it deletes — it is that it refuses to delete anything else.
 *
 * Central-context test: it manages its own tenants rather than inheriting one
 * from TenantTestCase, because the subject under test is which tenants survive.
 *
 * Most cases here invoke --force without --older-than, which prunes every test
 * tenant that exists at that moment. Within one sequential run that is nothing
 * but this test's own tenants. Under `pest --parallel`, or with a second suite
 * running in another terminal, it would delete tenants those runs are using —
 * so this file is not parallel-safe, and deliberately so: the age guard is the
 * thing being tested in the last case, and the others need it absent.
 */
class PruneTestTenantsTest extends TestCase
{
    /** @var list<string> tenant ids to clean up however the test ends */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $tenant = Tenant::find($id);

            if ($tenant) {
                $this->databaseExists($tenant)
                    ? $tenant->delete()
                    : Tenant::withoutEvents(fn () => $tenant->delete());
            }
        }

        parent::tearDown();
    }

    private function makeTenant(string $id): Tenant
    {
        $this->created[] = $id;

        return Tenant::create(['id' => $id, 'name' => 'Co', 'slug' => $id]);
    }

    private function databaseExists(Tenant $tenant): bool
    {
        return DB::connection('mysql')->select(
            'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1',
            [$tenant->database()->getName()],
        ) !== [];
    }

    public function test_it_deletes_a_test_tenant_and_its_database(): void
    {
        $tenant = $this->makeTenant('test'.uniqid());
        $database = $tenant->database()->getName();

        $this->artisan('tenants:prune-test', ['--force' => true])->assertSuccessful();

        $this->assertNull(Tenant::find($tenant->getTenantKey()), 'the record should be gone');
        $this->assertSame([], DB::connection('mysql')->select(
            'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1',
            [$database],
        ), 'the database should be dropped');
    }

    public function test_it_never_touches_a_tenant_without_the_test_prefix(): void
    {
        // Named like a real company, which is exactly what must survive.
        $live = $this->makeTenant('acme'.uniqid());

        $this->artisan('tenants:prune-test', ['--force' => true])->assertSuccessful();

        $this->assertNotNull(Tenant::find($live->getTenantKey()), 'a non-test tenant must survive');
        $this->assertTrue($this->databaseExists($live), 'its database must survive too');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $tenant = $this->makeTenant('test'.uniqid());

        $this->artisan('tenants:prune-test')->assertSuccessful();

        $this->assertNotNull(Tenant::find($tenant->getTenantKey()));
        $this->assertTrue($this->databaseExists($tenant));
    }

    public function test_older_than_spares_a_tenant_a_running_suite_is_using(): void
    {
        // The concurrency guard: a second suite's tenants are seconds old, and
        // pruning them mid-run would fail that suite.
        $fresh = $this->makeTenant('test'.uniqid());

        $this->artisan('tenants:prune-test', ['--force' => true, '--older-than' => 1])
            ->assertSuccessful();

        $this->assertNotNull(Tenant::find($fresh->getTenantKey()));
    }

    public function test_it_removes_a_record_whose_database_is_already_gone(): void
    {
        // The 24 records that made `tenants:migrate` die partway through: the
        // database is missing, so the normal delete path would throw.
        $tenant = $this->makeTenant('test'.uniqid());
        DB::connection('mysql')->statement('DROP DATABASE `'.$tenant->database()->getName().'`');

        $this->artisan('tenants:prune-test', ['--force' => true])->assertSuccessful();

        $this->assertNull(Tenant::find($tenant->getTenantKey()));
    }
}
