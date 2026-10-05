<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModulePaymentResource;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    /** The methods the Finance page offers; anything else is a typo or a forgery. */
    private const PAYMENT_METHODS = [
        'Bank transfer', 'Cheque', 'Cash', 'JazzCash', 'EasyPaisa', 'International wire',
    ];

    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function payments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'moduleId' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => ModulePaymentResource::collection($this->finance->payments($request->user(), $filters)),
        ]);
    }

    public function storePayment(Request $request, int $module): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'payer' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Payment logged.',
            'payment' => new ModulePaymentResource(
                $this->finance->logPayment($request->user(), $module, $validated)
            ),
        ], 201);
    }

    public function destroyPayment(Request $request, int $payment): JsonResponse
    {
        $this->finance->deletePayment($request->user(), $payment);

        return response()->json(['message' => 'Payment removed.']);
    }
}
