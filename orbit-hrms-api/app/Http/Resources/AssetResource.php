<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'serialNumber' => $this->serial_number,
            'condition' => $this->condition,
            'status' => $this->status,
            // The frontend's existing field name for the current holder.
            'assignedTo' => $this->assigned_to,
            'assignedToName' => $this->whenLoaded('holder', fn () => $this->holder?->name),
            'notes' => $this->notes,
            // Dates come from the open assignment, not the asset: an asset
            // that has been issued three times has three sets of them, and
            // only the current one is meaningful here.
            'issuedAt' => $this->whenLoaded(
                'openAssignment',
                fn () => $this->openAssignment?->issued_on?->toDateString()
            ),
            'returnDue' => $this->whenLoaded(
                'openAssignment',
                fn () => $this->openAssignment?->return_due?->toDateString()
            ),
        ];
    }
}
