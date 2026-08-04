<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'type' => $this->type,
            // `from`/`to` rather than fromDate/toDate: the frontend's existing
            // leave shape uses these names.
            'from' => $this->from_date?->toDateString(),
            'to' => $this->to_date?->toDateString(),
            'days' => (float) $this->days,
            'reason' => $this->reason,
            'status' => $this->status,
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($a) => [
                'stage' => $a->stage,
                'decision' => $a->decision,
                'comment' => $a->comment,
                'approverName' => $a->approver?->name,
                'decidedAt' => $a->created_at?->toIso8601String(),
            ])),
        ];
    }
}
