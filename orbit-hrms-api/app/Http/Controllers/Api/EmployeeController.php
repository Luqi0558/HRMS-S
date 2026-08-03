<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $employees = $this->employees->list(
            $request->user(),
            $request->only(['search', 'status', 'department', 'designation']),
            // Capped: an unbounded per_page from the client is a trivial way
            // to make the server materialise the whole table.
            min((int) $request->input('per_page', 25), 100),
        );

        return response()->json([
            'data' => EmployeeResource::collection($employees->items()),
            'meta' => [
                'currentPage' => $employees->currentPage(),
                'lastPage' => $employees->lastPage(),
                'perPage' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    public function show(Request $request, int $employee): JsonResponse
    {
        return response()->json([
            'employee' => new EmployeeResource(
                $this->employees->find($request->user(), $employee)
            ),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, int $employee): JsonResponse
    {
        $updated = $this->employees->update(
            $request->user(),
            $employee,
            $request->toAttributes(),
        );

        return response()->json([
            'message' => 'Employee updated.',
            'employee' => new EmployeeResource($updated),
        ]);
    }
}
