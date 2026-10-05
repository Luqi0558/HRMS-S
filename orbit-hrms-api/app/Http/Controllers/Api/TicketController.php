<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\StoreTicketRequest;
use App\Http\Requests\Ticket\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = $this->tickets->list(
            $request->user(),
            $request->only([
                'project_id', 'project_module_id', 'status',
                'priority', 'assignee_id', 'overdue', 'search',
            ]),
            min((int) $request->input('per_page', 50), 200),
        );

        return response()->json([
            'data' => TicketResource::collection($tickets->items()),
            'meta' => [
                'currentPage' => $tickets->currentPage(),
                'lastPage' => $tickets->lastPage(),
                'perPage' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return response()->json([
            'ticket' => new TicketResource($this->tickets->find($request->user(), $ticket)),
        ]);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Ticket created.',
            'ticket' => new TicketResource(
                $this->tickets->create($request->user(), $request->validated())
            ),
        ], 201);
    }

    public function update(UpdateTicketRequest $request, int $ticket): JsonResponse
    {
        return response()->json([
            'message' => 'Ticket updated.',
            'ticket' => new TicketResource(
                $this->tickets->update($request->user(), $ticket, $request->validated())
            ),
        ]);
    }

    public function comment(Request $request, int $ticket): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        return response()->json([
            'ticket' => new TicketResource(
                $this->tickets->comment($request->user(), $ticket, $validated['body'])
            ),
        ], 201);
    }

    public function attach(Request $request, int $ticket): JsonResponse
    {
        $validated = $request->validate([
            // Documents, images and archives a ticket typically carries; no
            // executables or HTML, which a teammate's browser might run.
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,gif,webp,zip'],
        ]);

        return response()->json([
            'ticket' => new TicketResource(
                $this->tickets->attach($request->user(), $ticket, $validated['file'])
            ),
        ], 201);
    }

    public function downloadAttachment(Request $request, int $ticket, int $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $record = $this->tickets->findAttachment($request->user(), $ticket, $attachment);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($record->path, $record->original_name);
    }

    public function startTimer(Request $request, int $ticket): JsonResponse
    {
        return response()->json([
            'message' => 'Timer started.',
            'ticket' => new TicketResource(
                $this->tickets->startTimer($request->user(), $ticket)
            ),
        ]);
    }

    public function stopTimer(Request $request): JsonResponse
    {
        $ticket = $this->tickets->stopTimer($request->user());

        return response()->json([
            'message' => 'Timer stopped.',
            // The refreshed ticket, so the board can show the session that just
            // ended rather than the total it was last sent. Null when no timer
            // was running.
            'ticket' => $ticket ? new TicketResource($ticket) : null,
        ]);
    }
}
