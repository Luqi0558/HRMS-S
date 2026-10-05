<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /** The complete matrix is configuration data for Super Admin only. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'permissions' => $this->permissions->matrix($request->user()),
        ]);
    }

    /**
     * Applies changed matrix rows atomically. A submitted row must already
     * exist: adding modules and roles is an application deployment concern,
     * not an unchecked free-text administrative action.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => ['required', 'array', 'min:1', 'max:250'],
            'permissions.*.role' => ['required', 'string', 'max:80'],
            'permissions.*.module' => ['required', 'string', 'max:120'],
            'permissions.*.view' => ['required', 'boolean'],
            'permissions.*.create' => ['required', 'boolean'],
            'permissions.*.approve' => ['required', 'boolean'],
            'permissions.*.finance' => ['required', 'boolean'],
            'permissions.*.scope' => ['required', 'string', Rule::in(PermissionService::SCOPES)],
        ]);

        return response()->json([
            'message' => 'Role permissions saved.',
            'permissions' => $this->permissions->updateMatrix($request->user(), $validated['permissions']),
        ]);
    }
}
