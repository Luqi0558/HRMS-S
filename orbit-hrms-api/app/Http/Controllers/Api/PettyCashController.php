<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PettyCashResource;
use App\Services\PettyCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PettyCashController extends Controller
{
    public function __construct(private readonly PettyCashService $pettyCash)
    {
    }

    /**
     * Everything the page needs in one call: the tin's position plus all three
     * ledgers. They are always rendered together, so separate endpoints would
     * mean four round trips to draw one screen.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'summary' => $this->pettyCash->summary($user),
            'topUps' => PettyCashResource::collection($this->pettyCash->topUps($user)),
            'expenses' => PettyCashResource::collection(
                $this->pettyCash->expenses($user, $request->only(['status', 'category']))
            ),
            'reconciliations' => PettyCashResource::collection($this->pettyCash->reconciliations($user)),
        ]);
    }

    public function storeTopUp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'message' => 'Top-up recorded.',
            'topUp' => new PettyCashResource(
                $this->pettyCash->requestTopUp($request->user(), $validated)
            ),
        ], 201);
    }

    public function decideTopUp(Request $request, int $topUp): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
        ]);

        return response()->json([
            'topUp' => new PettyCashResource(
                $this->pettyCash->decideTopUp($request->user(), $topUp, $validated['decision'])
            ),
        ]);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'date' => ['nullable', 'date'],
            'category' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        if ($request->hasFile('receipt')) {
            $validated['receiptPath'] = $request->file('receipt')
                ->store('petty-cash', 'local');
        }

        return response()->json([
            'message' => 'Expense recorded.',
            'expense' => new PettyCashResource(
                $this->pettyCash->recordExpense($request->user(), $validated)
            ),
        ], 201);
    }

    public function decideExpense(Request $request, int $expense): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'expense' => new PettyCashResource(
                $this->pettyCash->decideExpense(
                    $request->user(),
                    $expense,
                    $validated['decision'],
                    $validated['comment'] ?? null,
                )
            ),
        ]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'countedAmount' => ['required', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'message' => 'Reconciliation recorded.',
            'reconciliation' => new PettyCashResource(
                $this->pettyCash->reconcile($request->user(), $validated)
            ),
        ], 201);
    }
}
