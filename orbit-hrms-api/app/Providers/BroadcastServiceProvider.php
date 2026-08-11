<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Broadcast::routes() is deliberately NOT called. It registers
        // /broadcasting/auth outside the tenant group, where tenancy has not
        // been initialised — the callbacks in channels.php would then resolve
        // `employees` against the central database, which has no such table.
        // routes/tenant.php registers the endpoint inside the group instead.
        require base_path('routes/channels.php');
    }
}
