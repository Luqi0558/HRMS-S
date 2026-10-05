<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalaryResource;
use App\Http\Resources\PayrollRunResource;
use App\Http\Resources\SalarySlipRequestResource;
use App\Http\Resources\SalarySlipResource;
use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function runs(Request $request): JsonResponse
    {
        return response()->json([
            'runs' => PayrollRunResource::collection($this->payroll->listRuns($request->user())),
        ]);
    }

    public function openRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Payroll run ready for preparation.',
            'run' => new PayrollRunResource(
                $this->payroll->openRun($request->user(), $validated['period'], $validated['notes'] ?? null)
            ),
        ], 201);
    }

    public function submitRun(Request $request, int $run): JsonResponse
    {
        return response()->json([
            'message' => 'Payroll run submitted for release.',
            'run' => new PayrollRunResource($this->payroll->submitRun($request->user(), $run)),
        ]);
    }

    public function releaseRun(Request $request, int $run): JsonResponse
    {
        return response()->json([
            'message' => 'Payroll run released for payment.',
            'run' => new PayrollRunResource($this->payroll->releaseRun($request->user(), $run)),
        ]);
    }

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
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
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

    /**
     * The only canonical payslip download. The browser receives a document
     * rendered from the stored salary-slip snapshot after the same scope check
     * used by the JSON detail endpoint.
     */
    public function downloadSlip(Request $request, int $slip)
    {
        $salarySlip = $this->payroll->findSlip($request->user(), $slip);
        $salarySlip->loadMissing([
            'employee:id,name,email,department,employee_code',
            'issuer:id,name', 'approver:id,name', 'payer:id,name',
        ]);

        $pdf = Pdf::loadView('pdf.salary-slip', [
            'slip' => $salarySlip,
            'companyName' => config('app.name', 'Orbit HRMS'),
        ])->setPaper('a4');

        return $pdf->download(sprintf(
            'payslip-%s-%s.pdf',
            $salarySlip->employee?->employee_code ?? $salarySlip->employee_id,
            $salarySlip->period?->format('Y-m') ?? $salarySlip->id,
        ));
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
    /**
     * Corrects a slip that is open for correction. Returns the request it
     * belongs to as well, because the Payroll page keys its rows on requests.
     */
    public function amendSlip(Request $request, int $slip): JsonResponse
    {
        $validated = $request->validate([
            'baseSalary' => ['sometimes', 'numeric', 'gt:0'],
            'houseAllowance' => ['sometimes', 'numeric', 'min:0'],
            'medicalAllowance' => ['sometimes', 'numeric', 'min:0'],
            'bonus' => ['sometimes', 'numeric', 'min:0'],
            'taxDeduction' => ['sometimes', 'numeric', 'min:0'],
            'advanceDeduction' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $amended = $this->payroll->amendSlip($request->user(), $slip, $validated);
        $owner = $amended->request?->load(['employee:id,name,department', 'reviewer:id,name', 'slip']);

        return response()->json([
            'message' => 'Payslip corrected.',
            'slip' => new SalarySlipResource($amended),
            'request' => $owner ? new SalarySlipRequestResource($owner) : null,
        ]);
    }

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
