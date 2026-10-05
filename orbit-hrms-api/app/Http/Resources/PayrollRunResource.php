<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A compact, non-sensitive view of a company payroll cycle. */
class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period?->toDateString(),
            'periodLabel' => $this->period?->format('F Y'),
            'status' => $this->status,
            'notes' => $this->notes,
            'preparedAt' => $this->created_at?->toIso8601String(),
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'releasedAt' => $this->released_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'preparedByName' => $this->whenLoaded('preparer', fn () => $this->preparer?->name),
            'submittedByName' => $this->whenLoaded('submitter', fn () => $this->submitter?->name),
            'releasedByName' => $this->whenLoaded('releaser', fn () => $this->releaser?->name),
            'slipCount' => $this->whenLoaded('slips', fn () => $this->slips->count()),
            'paidSlipCount' => $this->whenLoaded('slips', fn () => $this->slips->where('status', 'paid')->count()),
        ];
    }
}
