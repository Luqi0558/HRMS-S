<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModulePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'moduleId' => $this->project_module_id,
            'projectId' => $this->module?->project_id,
            // A string, like every other money field: a JSON number is a
            // double and can lose precision on large amounts.
            'amount' => (string) $this->amount,
            'date' => $this->paid_on?->toDateString(),
            'method' => $this->method,
            'payer' => $this->payer,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'loggedById' => $this->logged_by,
            'loggedByName' => $this->loggedBy?->name,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
