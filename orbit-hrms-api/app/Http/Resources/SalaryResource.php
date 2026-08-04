<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'baseSalary' => (string) $this->base_salary,
            'houseAllowance' => (string) $this->house_allowance,
            'medicalAllowance' => (string) $this->medical_allowance,
            'effectiveFrom' => $this->effective_from?->toDateString(),
            'reason' => $this->reason,
            'setByName' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
