<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceFollowUpResource;
use App\Models\AttendanceFollowUp;
use App\Services\AttendanceFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceFollowUpController extends Controller
{
    public function __construct(private readonly AttendanceFollowUpService $followUps)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employeeId' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['open', 'resolved'])],
        ]);

        return response()->json([
            'data' => AttendanceFollowUpResource::collection($this->followUps->list($request->user(), $filters)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'attendanceId' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'issue' => ['required', Rule::in(AttendanceFollowUp::ISSUES)],
            'action' => ['nullable', Rule::in(AttendanceFollowUp::ACTIONS)],
            'note' => ['required', 'string', 'max:2000'],
            // Written separately from the note: this one the employee reads.
            'messageToEmployee' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Follow-up recorded.',
            'followUp' => new AttendanceFollowUpResource($this->followUps->record($request->user(), $validated)),
        ], 201);
    }

    public function resolve(Request $request, int $followUp): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Follow-up resolved.',
            'followUp' => new AttendanceFollowUpResource(
                $this->followUps->resolve($request->user(), $followUp, $validated['resolution'] ?? null)
            ),
        ]);
    }
}
