<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveService $leave)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $requests = $this->leave->list(
            $request->user(),
            $request->only(['status', 'employee_id']),
            min((int) $request->input('per_page', 50), 200),
        );

        return response()->json([
            'data' => LeaveRequestResource::collection($requests->items()),
            'meta' => [
                'currentPage' => $requests->currentPage(),
                'lastPage' => $requests->lastPage(),
                'perPage' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['Annual leave', 'Sick leave', 'Casual leave', 'Unpaid leave'])],
            'fromDate' => ['required', 'date'],
            'toDate' => ['required', 'date'],
            // 0.5 steps for half days; the service refuses fractions when the
            // policy has them disabled.
            'days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'contact' => ['nullable', 'string', 'max:255'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        return response()->json([
            'message' => 'Leave requested.',
            'request' => new LeaveRequestResource(
                $this->leave->create($request->user(), $validated)
            ),
        ], 201);
    }

    /** Streams a request's supporting document. Access is checked in the service. */
    public function attachment(Request $request, int $leaveRequest): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $record = $this->leave->findForAttachment($request->user(), $leaveRequest);

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $record->attachment_path,
            $record->attachment_name ?: 'leave-'.$record->id.'.'.pathinfo($record->attachment_path, PATHINFO_EXTENSION),
        );
    }

    public function decide(Request $request, int $leaveRequest): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'request' => new LeaveRequestResource(
                $this->leave->decide(
                    $request->user(),
                    $leaveRequest,
                    $validated['decision'],
                    $validated['comment'] ?? null,
                )
            ),
        ]);
    }

    public function cancel(Request $request, int $leaveRequest): JsonResponse
    {
        return response()->json([
            'message' => 'Request cancelled.',
            'request' => new LeaveRequestResource(
                $this->leave->cancel($request->user(), $leaveRequest)
            ),
        ]);
    }

    /** Remaining days per type, for the balance widget. */
    public function balance(Request $request): JsonResponse
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['balance' => $this->leave->balance($employee)]);
    }
}
