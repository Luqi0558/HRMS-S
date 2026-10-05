<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MedicalClaimResource;
use App\Models\Employee;
use App\Services\MedicalClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicalClaimController extends Controller
{
    public function __construct(private readonly MedicalClaimService $claims)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $claims = $this->claims->list(
            $request->user(),
            $request->only(['status', 'employee_id']),
            min((int) $request->input('per_page', 50), 200),
        );

        return response()->json([
            'data' => MedicalClaimResource::collection($claims->items()),
            'meta' => [
                'currentPage' => $claims->currentPage(),
                'lastPage' => $claims->lastPage(),
                'perPage' => $claims->perPage(),
                'total' => $claims->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
            'incurredOn' => ['nullable', 'date', 'before_or_equal:today'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Stored before the service runs so the receipt-required check has a
        // path to see. An orphaned file on a rejected claim is harmless; a
        // claim rejected for a receipt the user did attach is not.
        if ($request->hasFile('receipt')) {
            $validated['receiptPath'] = $request->file('receipt')
                ->store('claims/'.$request->user()->id, 'local');
        }

        return response()->json([
            'message' => 'Claim submitted.',
            'claim' => new MedicalClaimResource(
                $this->claims->create($request->user(), $validated)
            ),
        ], 201);
    }

    /**
     * Streams a claim's receipt.
     *
     * Not a public URL: receipts are medical records, so the file is served
     * through the same permission and scope checks as the claim itself rather
     * than from a guessable path under the web root.
     */
    public function receipt(Request $request, int $claim): StreamedResponse
    {
        $record = $this->claims->findForReceipt($request->user(), $claim);

        return Storage::disk('local')->download(
            $record->receipt_path,
            'receipt-'.$record->id.'.'.pathinfo($record->receipt_path, PATHINFO_EXTENSION),
        );
    }

    public function decide(Request $request, int $claim): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            // Omitted approves the full claim; supplied settles less.
            'approvedAmount' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'claim' => new MedicalClaimResource(
                $this->claims->decide(
                    $request->user(),
                    $claim,
                    $validated['decision'],
                    isset($validated['approvedAmount']) ? (float) $validated['approvedAmount'] : null,
                    $validated['comment'] ?? null,
                )
            ),
        ]);
    }

    public function advanceStage(Request $request, int $claim): JsonResponse
    {
        $validated = $request->validate([
            'stage' => ['required', Rule::in(['view', 'processing'])],
        ]);

        return response()->json([
            'claim' => new MedicalClaimResource(
                $this->claims->advanceStage($request->user(), $claim, $validated['stage'])
            ),
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['usage' => $this->claims->annualUsage($employee)]);
    }
}
