<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalaryResource;
use App\Http\Resources\SalarySlipRequestResource;
use App\Http\Resources\SalarySlipResource;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll)
    {
    }

    // --- salaries --------------------------------------------------------

    public function salaryHistory(Request $request, int $employee): JsonResponse
    {
        return response()->json([
            'salaries' => SalaryResource::collection(
                $this->payroll->salaryHistory($request->user(), $employee)
            ),
        ]);
    }

    public function setSalary(Request $request, int $employee): JsonResponse
    {
        $validated = $request->validate([
            'baseSalary' => ['required', 'numeric', 'min:0'],
            'houseAllowance' => ['nullable', 'numeric', 'min:0'],
            'medicalAllowance' => ['nullable', 'numeric', 'min:0'],
            'effectiveFrom' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Salary recorded.',
            'salary' => new SalaryResource(
                $this->payroll->setSalary($request->user(), $employee, $validated)
            ),
        ], 201);
    }

    // --- slip requests ---------------------------------------------------

    public function requests(Request $request): JsonResponse
    {
        $requests = $this->payroll->listRequests(
            $request->user(),
            $request->only(['status']),
            min((int) $request->input('per_page', 50), 200),
        );

        return response()->json([
            'data' => SalarySlipRequestResource::collection($requests->items()),
            'meta' => [
                'currentPage' => $requests->currentPage(),
                'lastPage' => $requests->lastPage(),
                'perPage' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'date'],
        ]);

        return response()->json([
            'message' => 'Slip requested.',
            'request' => new SalarySlipRequestResource(
                $this->payroll->requestSlip($request->user(), $validated)
            ),
        ], 201);
    }

    /**
     * Approving issues the slip. `overrides` lets HR adjust a bonus or advance
     * for that month without altering the standing salary.
     */
    public function decideRequest(Request $request, int $slipRequest): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:1000'],
            'baseSalary' => ['nullable', 'numeric', 'min:0'],
            'houseAllowance' => ['nullable', 'numeric', 'min:0'],
            'medicalAllowance' => ['nullable', 'numeric', 'min:0'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'taxDeduction' => ['nullable', 'numeric', 'min:0'],
            'attendanceDeduction' => ['nullable', 'numeric', 'min:0'],
            'advanceDeduction' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'request' => new SalarySlipRequestResource(
                $this->payroll->decideRequest(
                    $request->user(),
                    $slipRequest,
                    $validated['decision'],
                    $validated,
                    $validated['comment'] ?? null,
                )
            ),
        ]);
    }

    // --- slips -----------------------------------------------------------

    public function slips(Request $request): JsonResponse
    {
        $slips = $this->payroll->listSlips(
            $request->user(),
            $request->only(['period', 'employee_id']),
            min((int) $request->input('per_page', 50), 200),
        );

        return response()->json([
            'data' => SalarySlipResource::collection($slips->items()),
            'meta' => [
                'currentPage' => $slips->currentPage(),
                'lastPage' => $slips->lastPage(),
                'perPage' => $slips->perPage(),
                'total' => $slips->total(),
            ],
        ]);
    }

    public function showSlip(Request $request, int $slip): JsonResponse
    {
        return response()->json([
            'slip' => new SalarySlipResource(
                $this->payroll->findSlip($request->user(), $slip)
            ),
        ]);
    }

    /** Releases a generated slip for payment. */
    public function approveSlip(Request $request, int $slip): JsonResponse
    {
        return response()->json([
            'message' => 'Payroll released.',
            'slip' => new SalarySlipResource(
                $this->payroll->approveSlip($request->user(), $slip)
            ),
        ]);
    }

    public function markPaid(Request $request, int $slip): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['nullable', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:120'],
            'paidDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'message' => 'Payment recorded.',
            'slip' => new SalarySlipResource(
                $this->payroll->markPaid($request->user(), $slip, $validated)
            ),
        ]);
    }

    /** Returns a released slip to 'generated' so it can be corrected. */
    public function reopenSlip(Request $request, int $slip): JsonResponse
    {
        return response()->json([
            'message' => 'Payroll reopened.',
            'slip' => new SalarySlipResource(
                $this->payroll->reopenSlip($request->user(), $slip)
            ),
        ]);
    }

    /** Company payroll cost for a month, for the finance dashboard. */
    public function cost(Request $request): JsonResponse
    {
        return response()->json([
            'cost' => $this->payroll->monthlyCost(
                $request->user(),
                $request->input('period'),
            ),
        ]);
    }
}
