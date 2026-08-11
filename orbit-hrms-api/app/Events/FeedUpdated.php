<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one employee that something new is waiting for them.
 *
 * Deliberately carries no content — only the type and the tenant. The client
 * responds by calling /api/sync, which returns the actual message or
 * notification from this server.
 *
 * Two reasons for that shape. It keeps message bodies and notification text
 * off a third-party wire, and it means the socket and the poll converge on
 * one code path: whether news arrives via Pusher or via the 3-second tick,
 * the client does the same thing with it. A payload here would be a second
 * source of truth that could disagree with the poll.
 *
 * Broadcasting is fire-and-forget. If Pusher is unreachable the send still
 * succeeds and the recipient gets it on their next poll — the socket is an
 * accelerator, never the delivery guarantee.
 */
class FeedUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, int>  $employeeIds  everyone to ping in one go
     */
    public function __construct(
        public readonly array $employeeIds,
        public readonly string $tenantId,
        /** 'message' or 'notification' — lets the client skip a fetch it does not need. */
        public readonly string $type = 'message',
    ) {
    }

    /**
     * One event, many channels.
     *
     * Returning an array means the Pusher driver sends a SINGLE HTTP call for
     * the whole set. Dispatching one event per recipient instead made a
     * company-wide broadcast into dozens of sequential round trips to Mumbai,
     * stacked onto the send request the user was waiting on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // Scoped by tenant as well as employee: two companies in this
        // installation both have an employee 1, and a shared Pusher app would
        // otherwise deliver one company's ping to the other.
        return array_map(
            fn (int $id) => new PrivateChannel("tenant.{$this->tenantId}.employee.{$id}"),
            $this->employeeIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'feed.updated';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['type' => $this->type];
    }
}
