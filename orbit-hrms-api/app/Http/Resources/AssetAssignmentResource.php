<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assetId' => $this->asset_id,
            'employeeId' => $this->employee_id,
            // The stored copy, so a past holder still has a name after being
            // offboarded — which is when the history matters most.
            'employeeName' => $this->employee_name,
            'issuedOn' => $this->issued_on?->toDateString(),
            'returnDue' => $this->return_due?->toDateString(),
            'returnedOn' => $this->returned_on?->toDateString(),
            'conditionOut' => $this->condition_out,
            'conditionIn' => $this->condition_in,
            'notes' => $this->notes,
            'issuedByName' => $this->whenLoaded('issuer', fn () => $this->issuer?->name),
            // Still out.
            'isOpen' => $this->returned_on === null,
        ];
    }
}
