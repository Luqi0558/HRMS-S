<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate: `->middleware('can.module:Employees,create')`.
 *
 * This is the server-side half the frontend guard cannot provide — the router
 * check in src/router/index.js runs in the browser and is trivially bypassed.
 */
class EnsureCan
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string $module, string $action = 'view'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'unauthenticated',
            ], 401);
        }

        if (! $this->permissions->can($user, $module, $action)) {
            return response()->json([
                'message' => 'You do not have permission to do that.',
                'error' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
