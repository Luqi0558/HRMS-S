<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message as one person sees it.
 *
 * Wraps either a MessageRecipient (a delivery, from the poll) or an
 * ['message' => …, 'readAt' => …] array (from the inbox, which also returns
 * messages this person sent and therefore has no delivery row for). Both
 * shapes produce the same JSON, so the client has one thing to handle.
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $message = $this->resolveMessage();
        $readAt = $this->resolveReadAt();

        return [
            'id' => $message?->id,
            'senderId' => $message?->sender_id,
            'senderName' => $message?->sender?->name,
            'audience' => $message?->audience,
            'subject' => $message?->subject,
            'body' => $message?->body,
            'createdAt' => $message?->created_at?->toIso8601String(),
            'readAt' => $readAt?->toIso8601String(),
            'isRead' => (bool) $readAt,
            // The view groups a conversation by who is on the other side, and
            // renders a broadcast differently from a direct message — both of
            // which need the delivery list, not just the sender.
            'recipientIds' => $message?->relationLoaded('recipients')
                ? $message->recipients->pluck('employee_id')->all()
                : null,
            // Delivery state, for the sender's ticks. Null on a message you
            // received — you know you have it, and how many other people have
            // read a broadcast is not yours to see.
            'delivery' => $this->when(
                $this->isMine() && $message?->relationLoaded('recipients'),
                fn () => [
                    'total' => $message->recipients->count(),
                    'read' => $message->recipients->whereNotNull('read_at')->count(),
                ],
            ),
        ];
    }

    /** Whether the viewer sent this message. */
    private function isMine(): bool
    {
        if (is_array($this->resource)) {
            return (bool) ($this->resource['isMine'] ?? false);
        }

        // A delivery row is by definition something received, not sent.
        return false;
    }

    private function resolveMessage(): ?Message
    {
        // The inbox hands over an array; the poll hands over a delivery row.
        if (is_array($this->resource)) {
            return $this->resource['message'] ?? null;
        }

        return $this->message;
    }

    private function resolveReadAt()
    {
        if (is_array($this->resource)) {
            // A message you sent is yours: nothing to mark read.
            return ($this->resource['isMine'] ?? false)
                ? $this->resource['message']?->created_at
                : $this->resource['readAt'] ?? null;
        }

        return $this->read_at;
    }
}
