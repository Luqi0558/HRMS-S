<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(['birthday', 'anniversary', 'leave'])],
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();

        if ($from->diffInDays($to) > 366) {
            return response()->json([
                'message' => 'Calendar queries may cover at most one year.',
                'errors' => ['to' => ['Calendar queries may cover at most one year.']],
            ], 422);
        }

        $events = $this->calendar->events($request->user(), $from, $to, $validated['types'] ?? []);

        return response()->json([
            'events' => $events,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'total' => count($events),
            ],
        ]);
    }
}
