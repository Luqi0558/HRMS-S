<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EffectivenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EffectivenessController extends Controller
{
    public function __construct(private readonly EffectivenessService $effectiveness)
    {
    }

    public function mine(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'effectiveness' => $this->effectiveness->personal($request->user(), $period['range'], $period['from'], $period['to']),
        ]);
    }

    public function workforce(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'effectiveness' => $this->effectiveness->workforce($request->user(), $period['range'], $period['from'], $period['to']),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::in(['personal', 'workforce'])],
            'months' => ['nullable', 'integer', 'min:3', 'max:12'],
        ]);

        return response()->json([
            'history' => $this->effectiveness->history(
                $request->user(), $validated['scope'], (int) ($validated['months'] ?? 6),
            ),
        ]);
    }

    public function cycles(Request $request): JsonResponse
    {
        return response()->json(['cycles' => $this->effectiveness->cycles($request->user())]);
    }

    public function review(Request $request, int $cycle): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $cycle = $this->effectiveness->review($request->user(), $cycle, $validated['note'] ?? null);

        return response()->json(['message' => 'Effectiveness period reviewed.', 'cycle' => $cycle]);
    }

    public function finalize(Request $request, int $cycle): JsonResponse
    {
        $cycle = $this->effectiveness->finalize($request->user(), $cycle);

        return response()->json(['message' => 'Effectiveness period finalized.', 'cycle' => $cycle]);
    }

    /** @return array{range: string, from: ?string, to: ?string} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'range' => ['nullable', 'string', Rule::in(['30', '90', 'month'])],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
        ]);

        if (($validated['from'] ?? null) && Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) > 366) {
            abort(422, 'Effectiveness reports may cover at most one year.');
        }

        return [
            'range' => $validated['range'] ?? '30',
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }
}
