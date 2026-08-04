<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $records = $this->attendance->list(
            $request->user(),
            $request->only(['date', 'from', 'to', 'status', 'employee_id']),
            min((int) $request->input('per_page', 50), 200),
        );

        return response()->json([
            'data' => AttendanceResource::collection($records->items()),
            'meta' => [
                'currentPage' => $records->currentPage(),
                'lastPage' => $records->lastPage(),
                'perPage' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /** Drives the Check in / Check out button's state. */
    public function today(Request $request): JsonResponse
    {
        $record = $this->attendance->today($request->user());

        return response()->json([
            'attendance' => $record ? new AttendanceResource($record) : null,
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device' => ['nullable', 'string', 'max:40'],
            'photoPath' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Checked in.',
            'attendance' => new AttendanceResource(
                $this->attendance->checkIn($request->user(), $validated)
            ),
        ], 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Checked out.',
            'attendance' => new AttendanceResource(
                $this->attendance->checkOut($request->user())
            ),
        ]);
    }
}
