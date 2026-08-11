<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'version' => $this->version,
            'summary' => $this->summary,
            'body' => $this->body,
            // The frontend's existing field name.
            'acknowledgmentRequired' => (bool) $this->acknowledgment_required,
            'ownerId' => $this->owner_id,
            'ownerName' => $this->owner_name,
            'updatedAt' => $this->updated_at?->toDateString(),
            // Whether THIS viewer has acknowledged the CURRENT version — a
            // stale acknowledgement of an older version reads as false, which
            // is what makes the badge meaningful.
            'acknowledgedByMe' => (bool) $this->getAttribute('acknowledged_by_me'),
        ];
    }
}
