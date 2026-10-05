<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessControlController extends Controller
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function people(Request $request): JsonResponse
    {
        $people = $this->access->people(
            $request->user(),
            $request->only(['search', 'role', 'status']),
            min(max((int) $request->input('per_page', 25), 1), 100),
        );

        return response()->json([
            'data' => EmployeeResource::collection($people->items()),
            'meta' => [
                'currentPage' => $people->currentPage(),
                'lastPage' => $people->lastPage(),
                'perPage' => $people->perPage(),
                'total' => $people->total(),
            ],
        ]);
    }

    public function assignRole(Request $request, int $employee): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'message' => 'Role assigned. The employee must sign in again.',
            'employee' => new EmployeeResource(
                $this->access->assignRole($request->user(), $employee, $validated['role'], $validated['reason'] ?? null)
            ),
        ]);
    }
}
