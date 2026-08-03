<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /**
     * The effective permission grid for the signed-in user.
     *
     * Scoped to their own role rather than returning the whole table: the full
     * grid is an administrative concern, and shipping every role's rules to
     * every client discloses the app's authorization model needlessly.
     */
    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'permissions' => $this->permissions->forUser($request->user()),
        ]);
    }
}
