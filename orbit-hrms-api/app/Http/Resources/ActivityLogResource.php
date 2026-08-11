<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actorId' => $this->actor_id,
            // The stored copy, not the relation: an entry has to stay readable
            // after the employee record is gone.
            'actorName' => $this->actor_name,
            'actorRole' => $this->actor_role,
            'module' => $this->module,
            'action' => $this->action,
            'details' => $this->details,
            'targetId' => $this->target_id,
            'targetType' => $this->target_type,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
