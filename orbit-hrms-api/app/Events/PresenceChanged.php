<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One person's chosen status changed.
 *
 * The presence channel tells subscribers when someone joins or leaves, but not
 * when a person who stays connected switches to Busy. This carries that.
 *
 * It does carry a payload, unlike FeedUpdated — but only a status and a short
 * note, both of which every colleague can already see. Nothing here is private
 * the way a message body is.
 */
class PresenceChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $employeeId,
        public readonly string $tenantId,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("tenant.{$this->tenantId}.presence");
    }

    public function broadcastAs(): string
    {
        return 'presence.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->employeeId,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
