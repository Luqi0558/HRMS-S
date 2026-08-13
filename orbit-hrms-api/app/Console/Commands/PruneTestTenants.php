<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes the tenants that test runs leave behind.
 *
 * TenantTestCase drops its tenant in tearDown, so a clean run leaves nothing.
 * A run that is killed — a timeout, a Ctrl-C, a fatal in setUp — never reaches
 * tearDown, and the tenant survives. Those accumulate silently until a global
 * command like `tenants:migrate` walks the list and dies partway through on a
 * record whose database is no longer there.
 *
 * Two kinds of leftover exist and they need different handling:
 *
 *  - record + database. Deleting the model fires TenantDeleted, which drops the
 *    database through the job pipeline. The normal path.
 *  - record, no database. The drop would fail on a database that is already
 *    gone, so the model is deleted without events instead.
 */
class PruneTestTenants extends Command
{
    protected $signature = 'tenants:prune-test
                            {--force : Actually delete. Without this the command only reports.}
                            {--older-than= : Only prune tenants created more than this many hours ago.}';

    protected $description = 'Remove leftover test tenants and their databases';

    /**
     * The only tenants this command will ever touch.
     *
     * TenantTestCase names its tenants "test".uniqid(). Anything that does not
     * match is somebody's real data, and no flag makes it eligible — a mistake
     * here is unrecoverable, so the guard is a prefix check rather than a
     * confirmation prompt.
     */
    private const TEST_PREFIX = 'test';

    public function handle(): int
    {
        $all = Tenant::all();
        $test = $all->filter(
            fn (Tenant $tenant) => str_starts_with((string) $tenant->getTenantKey(), self::TEST_PREFIX)
        );

        /*
         * Wired into `composer test`, which means it can fire while a second
         * suite is running in another terminal — and those tenants are in use.
         * An age cut-off keeps the sweep to genuine leftovers: a crashed run's
         * tenants are hours old, a live run's are seconds old.
         */
        if ($hours = $this->option('older-than')) {
            $cutoff = now()->subHours((float) $hours);
            $test = $test->filter(fn (Tenant $tenant) => $tenant->created_at?->lt($cutoff) ?? true);
        }

        $kept = $all->count() - $test->count();

        if ($test->isEmpty()) {
            $this->info("Nothing to prune. {$kept} tenant(s) left alone.");

            return self::SUCCESS;
        }

        [$withDatabase, $orphanedRecords] = $test->partition(
            fn (Tenant $tenant) => $this->databaseExists($tenant)
        );

        $this->line("  test tenants ............ {$test->count()}");
        $this->line("    with a database ....... {$withDatabase->count()}  (record + database dropped)");
        $this->line("    database already gone . {$orphanedRecords->count()}  (record removed)");
        $this->line("  left alone .............. {$kept}  ".$this->survivorList($all, $test));

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run. Re-run with --force to delete.');

            return self::SUCCESS;
        }

        $this->newLine();
        $failures = 0;
        $bar = $this->output->createProgressBar($test->count());

        foreach ($test as $tenant) {
            try {
                if ($this->databaseExists($tenant)) {
                    // Fires TenantDeleted, which drops the database.
                    $tenant->delete();
                } else {
                    // No database to drop, and letting the pipeline try would
                    // throw on the missing schema.
                    Tenant::withoutEvents(fn () => $tenant->delete());
                }
            } catch (Throwable $e) {
                $failures++;
                $this->newLine();
                $this->error("  {$tenant->getTenantKey()}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $remaining = Tenant::count();
        $this->info("Pruned ".($test->count() - $failures)." tenant(s). {$remaining} remaining.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Asked of information_schema rather than by connecting: half these records
     * point at a database that no longer exists, and opening a connection to
     * one of those throws rather than returning false.
     */
    private function databaseExists(Tenant $tenant): bool
    {
        $name = $tenant->database()->getName();

        return DB::connection('mysql')->select(
            'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1',
            [$name],
        ) !== [];
    }

    /** Names the tenants being kept, so an over-broad prune is obvious before it runs. */
    private function survivorList($all, $test): string
    {
        $names = $all->diff($test)
            ->map(fn (Tenant $tenant) => (string) $tenant->getTenantKey())
            ->values();

        if ($names->isEmpty()) {
            return '';
        }

        // With --older-than the survivors include a live run's tenants, which
        // can be dozens. The real names are the point; the rest is noise.
        $shown = $names->take(5);
        $more = $names->count() - $shown->count();

        return '('.$shown->implode(', ').($more > 0 ? ", +{$more} more" : '').')';
    }
}
