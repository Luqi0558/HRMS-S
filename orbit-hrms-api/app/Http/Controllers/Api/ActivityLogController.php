<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityLogService $logs)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:60'],
            'actor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = $this->logs->list(
            $request->user(),
            $validated,
            (int) ($validated['per_page'] ?? 50),
        );

        return response()->json([
            'data' => ActivityLogResource::collection($logs->items()),
            // The filter dropdown offers only modules that actually appear.
            'modules' => $this->logs->modules($request->user()),
            'meta' => [
                'currentPage' => $logs->currentPage(),
                'lastPage' => $logs->lastPage(),
                'perPage' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Records an action the SPA performed.
     *
     * Deliberately open to any signed-in user: the caller has already done the
     * thing being logged, so refusing the log would only lose the record of
     * something that happened anyway. What it CANNOT do is forge an actor —
     * that comes from the token, never the body.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'max:60'],
            'action' => ['required', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:2000'],
            'targetId' => ['nullable', 'integer'],
            'targetType' => ['nullable', 'string', 'max:60'],
        ]);

        $log = $this->logs->record($request->user(), $validated);

        return response()->json([
            'log' => new ActivityLogResource($log),
        ], 201);
    }
}
