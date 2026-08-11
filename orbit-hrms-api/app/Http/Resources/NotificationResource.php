<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            // Shaped for the router: the bell links straight to this.
            'route' => $this->route_name
                ? ['name' => $this->route_name, 'params' => $this->route_params ?: []]
                : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'readAt' => $this->read_at?->toIso8601String(),
            'isRead' => (bool) $this->read_at,
        ];
    }
}
