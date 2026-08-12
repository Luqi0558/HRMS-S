<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $this->refuseCachedConfig();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * A cached config makes the whole suite fail for a reason it never states.
     *
     * bootstrap/cache/config.php is read INSTEAD of re-evaluating env(), so
     * phpunit.xml's APP_ENV and database settings are ignored and every test
     * runs against the development database — where the seeded users do not
     * exist. What you see is a wall of 401s and 404s with nothing pointing at
     * the cause.
     *
     * The cache is wanted when serving (it stops concurrent .env reads failing
     * under Apache on Windows) and unwanted here, so this refuses to guess and
     * says which command to run.
     */
    private function refuseCachedConfig(): void
    {
        $cached = __DIR__.'/../bootstrap/cache/config.php';

        if (! file_exists($cached)) {
            return;
        }

        throw new RuntimeException(
            'The config cache is in place, so this run would use the development '
            ."database and fail with unexplained 401s.\n\n"
            ."  composer test                                             full suite\n"
            ."  php artisan config:clear && php artisan test --filter=X   one class\n\n"
            .'Afterwards, `composer serve-ready` restores the cache for serving.'
        );
    }
}
