<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Http\Resources\NotificationResource;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages)
    {
    }

    /**
     * The poll. Called every few seconds by every signed-in client.
     *
     * Returns 204 when nothing has changed, which is the common case: a quiet
     * office should cost an empty response with no body to parse, not a JSON
     * document restating what the client already has.
     */
    public function sync(Request $request): JsonResponse|Response
    {
        $validated = $request->validate([
            'sinceMessage' => ['nullable', 'integer', 'min:0'],
            'sinceNotification' => ['nullable', 'integer', 'min:0'],
            // The highest delivery-row id already seen. An id rather than a
            // timestamp: comparing app time against database time silently
            // returned nothing whenever the two clocks differed at all.
            'sinceReceipt' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = $this->messages->sync(
            $request->user(),
            $validated['sinceMessage'] ?? null,
            $validated['sinceNotification'] ?? null,
            $validated['sinceReceipt'] ?? null,
        );

        $isFirstPoll = ! isset($validated['sinceMessage']) && ! isset($validated['sinceNotification']);
        $hasNews = $result['messages'] !== []
            || $result['notifications'] !== []
            || ($result['receipts'] ?? []) !== [];

        // The first poll always answers in full — it carries the unread counts
        // and the starting cursors the client has no other way to learn.
        if (! $hasNews && ! $isFirstPoll) {
            return response()->noContent();
        }

        return response()->json([
            'messages' => MessageResource::collection($result['messages']),
            'notifications' => NotificationResource::collection($result['notifications']),
            // Delivery counts for messages this user sent, so their ticks
            // update without a reload.
            'receipts' => $result['receipts'] ?? [],
            'cursors' => $result['cursors'],
            'unread' => $result['unread'],
        ]);
    }

    /**
     * The Messages page in one call: the conversation history plus the people
     * this user may write to.
     *
     * Contacts ride along rather than coming from /employees, which an
     * employee has no permission to read — that request 403s for the most
     * common role, and would return the whole company rather than the few
     * people they may actually message.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Keyset paging: the oldest id the client already holds. An offset
            // would shift under them every time a new message arrived, which
            // in a live conversation is constantly.
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $before = $validated['before'] ?? null;

        $messages = $this->messages->inbox($request->user(), 100, $before);

        return response()->json([
            'data' => MessageResource::collection($messages),
            // Contacts only on the first page — they do not change as you
            // scroll back through history.
            'contacts' => $before ? [] : $this->messages->contacts($request->user()),
            'hasMore' => $this->messages->inboxHasMore(),
            'oldestId' => $this->messages->inboxOldestId(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'audience' => ['nullable', 'string', 'max:60'],
            'recipientIds' => ['nullable', 'array'],
            'recipientIds.*' => ['integer'],
        ]);

        $message = $this->messages->send($request->user(), $validated);

        return response()->json([
            'message' => 'Sent.',
            'sent' => [
                'id' => $message->id,
                'audience' => $message->audience,
                'subject' => $message->subject,
                'body' => $message->body,
                'senderId' => $message->sender_id,
                'senderName' => $message->sender?->name,
                'createdAt' => $message->created_at?->toIso8601String(),
                'recipientCount' => $message->recipients->count(),
                // The resolved list, not what the client asked for: a
                // broadcast sends no ids at all, and the sender's own copy
                // needs to know who it actually went to.
                'recipientIds' => $message->recipients->pluck('employee_id')->all(),
            ],
        ], 201);
    }

    /**
     * Sets the caller's chosen status. Null clears it, so presence falls back
     * to whatever their connection says.
     */
    public function setPresence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['available', 'away'])],
            'message' => ['nullable', 'string', 'max:120'],
        ]);

        $employee = $this->messages->setPresence(
            $request->user(),
            $validated['status'] ?? null,
            $validated['message'] ?? null,
        );

        return response()->json([
            'presence' => [
                'status' => $employee->presence_status,
                'message' => $employee->presence_message,
            ],
        ]);
    }

    /** Chosen statuses for the company — the half Pusher cannot know. */
    public function presence(Request $request): JsonResponse
    {
        return response()->json([
            'presence' => $this->messages->presenceStatuses($request->user()),
        ]);
    }

    public function markRead(Request $request, int $message): JsonResponse
    {
        $this->messages->markMessageRead($request->user(), $message);

        return response()->json(['message' => 'Marked read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json([
            'marked' => $this->messages->markAllMessagesRead($request->user()),
        ]);
    }

    public function markNotificationRead(Request $request, int $notification): JsonResponse
    {
        $this->messages->markNotificationRead($request->user(), $notification);

        return response()->json(['message' => 'Marked read.']);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        return response()->json([
            'marked' => $this->messages->markAllNotificationsRead($request->user()),
        ]);
    }
}
