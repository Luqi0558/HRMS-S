<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalarySlipRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'payrollRunId' => $this->payroll_run_id,
            'period' => $this->period?->toDateString(),
            // "June 2026" — the label the frontend's month column shows.
            'month' => $this->period?->format('F Y'),
            'status' => $this->status,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewComment' => $this->review_comment,
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'department' => $this->whenLoaded('employee', fn () => $this->employee?->department),
            'reviewedByName' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            // Present once approved; the slip is issued in the same
            // transaction as the approval.
            'slip' => $this->whenLoaded('slip', fn () => $this->slip
                ? new SalarySlipResource($this->slip)
                : null),
        ];
    }
}
