<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeInvitationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PermissionController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

/*
|--------------------------------------------------------------------------
| Tenant routes
|--------------------------------------------------------------------------
|
| Every route here runs against one company's database. Tenancy is resolved
| from the X-Tenant header rather than the domain, because the SPA is served
| from its own origin (localhost:5174) and does not share a hostname with the
| API — domain resolution would have nothing to match on.
|
| InitializeTenancyByRequestData must come first: without an active tenant the
| queries below would hit the central connection, where `users` does not exist.
|
| PreventAccessFromCentralDomains is deliberately NOT applied. It exists to stop
| tenant routes resolving on a central hostname, which only makes sense for
| domain-based tenancy — with header-based resolution the API and the central
| app share a hostname, so it 404s every request before tenancy is even checked.
| The X-Tenant header is what scopes these routes.
|
*/

Route::middleware([
    'api',
    InitializeTenancyByRequestData::class,
])->prefix('api')->group(function () {

    /*
     * Public. Rate limited hard: these are the only endpoints reachable
     * without a token, so they are where credential stuffing and token
     * guessing arrive. Throttled per IP.
     */
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->name('api.login');

        // Resolving an invitation is a read, but it is also an oracle for
        // guessing tokens, so it gets the same budget as login.
        Route::post('/onboarding/resolve', [OnboardingController::class, 'resolve'])
            ->name('api.onboarding.resolve');

        Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])
            ->name('api.onboarding.complete');
    });

    /*
     * Authenticated.
     */
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])
            ->name('api.logout.all');

        // The signed-in user's effective permission grid. Fetched once after
        // login so the UI stops relying on its own hardcoded copy.
        Route::get('/me/permissions', [PermissionController::class, 'mine'])
            ->name('api.me.permissions');

        /*
         * Inviting staff. Gated on the same grid the UI reads, so a hidden
         * button and a refused request agree — the browser-side router guard
         * is UX only and cannot be trusted.
         */
        /*
         * Employees. The middleware gates the module; EmployeeService applies
         * the scope, so HR sees everyone and a manager only their own people
         * from the same route.
         *
         * `show` is deliberately ungated: a user must be able to open their
         * own record, and an ordinary employee holds no Employees permission
         * at all. The service enforces the record-level rule instead.
         */
        Route::get('/employees', [EmployeeController::class, 'index'])
            ->middleware('can.module:Employees,view')
            ->name('api.employees.index');

        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])
            ->whereNumber('employee')
            ->name('api.employees.show');

        Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])
            ->whereNumber('employee')
            ->middleware('can.module:Employees,create')
            ->name('api.employees.update');

        Route::post('/employees/invite', [EmployeeInvitationController::class, 'store'])
            ->middleware('can.module:Employees,create')
            ->name('api.employees.invite');

        Route::post('/employees/{employee}/resend-invite', [EmployeeInvitationController::class, 'resend'])
            ->middleware('can.module:Employees,create')
            ->name('api.employees.resend-invite');
    });
});
