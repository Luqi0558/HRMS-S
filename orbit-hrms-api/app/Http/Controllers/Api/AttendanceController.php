<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // The photo arrives as an uploaded file, not a path. photoPath is a
            // 255-character column, and the camera produces a JPEG — the client
            // was never in a position to name its own storage path anyway.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if ($request->hasFile('photo')) {
            // Private disk, as with claim receipts: a check-in photo is a
            // picture of an employee at their desk, not a public asset.
            $validated['photoPath'] = $request->file('photo')
                ->store('attendance/'.$request->user()->id, 'local');
        }

        unset($validated['photo']);

        return response()->json([
            'message' => 'Checked in.',
            'attendance' => new AttendanceResource(
                $this->attendance->checkIn($request->user(), $validated)
            ),
        ], 201);
    }

    /**
     * Streams a check-in photo.
     *
     * Not a public URL: these are photographs of staff, so the file is served
     * through the same permission and scope checks as the attendance row rather
     * than from a guessable path under the web root.
     */
    public function photo(Request $request, int $attendance): StreamedResponse
    {
        $row = $this->attendance->findForPhoto($request->user(), $attendance);

        return Storage::disk('local')->download(
            $row->photo_path,
            'check-in-'.$row->id.'.'.pathinfo($row->photo_path, PATHINFO_EXTENSION),
        );
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
