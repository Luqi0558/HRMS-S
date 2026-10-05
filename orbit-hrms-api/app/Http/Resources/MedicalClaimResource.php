<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicalClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'type' => $this->type,
            // Strings: JSON numbers are doubles, and a payable amount rendered
            // through one can lose precision.
            'amount' => (string) $this->amount,
            'approvedAmount' => $this->approved_amount !== null
                ? (string) $this->approved_amount
                : null,
            'description' => $this->description,
            'incurredOn' => $this->incurred_on?->toDateString(),
            'submittedAt' => $this->created_at?->toDateString(),
            'status' => $this->status,
            'hasReceipt' => filled($this->receipt_path),
            // The review steps before a decision. `status` stays 'pending'
            // through them - it is what moves money - so the stage is its own
            // field rather than a status value.
            'reviewStage' => $this->reviewStage(),
            'viewedAt' => $this->viewed_at?->toIso8601String(),
            'viewedByName' => $this->whenLoaded('viewer', fn () => $this->viewer?->name),
            'processingAt' => $this->processing_at?->toIso8601String(),
            'processingByName' => $this->whenLoaded('processor', fn () => $this->processor?->name),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewComment' => $this->review_comment,
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'reviewerName' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
        ];
    }
}
