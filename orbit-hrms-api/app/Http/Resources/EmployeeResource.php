<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeCode' => $this->employee_code,
            'name' => $this->name,
            'email' => $this->email,
            'designation' => $this->designation,
            'department' => $this->department,
            'birthday' => $this->birthday?->toDateString(),
            'joiningDate' => $this->joining_date?->toDateString(),
            'employmentType' => $this->employment_type,
            'probationStatus' => $this->probation_status,
            'status' => $this->status,

            // Nested under `onboarding` to mirror the frontend's existing
            // employee.onboarding shape rather than inventing a new one.
            'onboarding' => $this->when(
                $this->relationLoaded('profile') || $this->relationLoaded('technicalProfile'),
                fn () => [
                    'personal' => new EmployeeProfileResource($this->whenLoaded('profile')),
                    'technical' => new EmployeeTechnicalProfileResource($this->whenLoaded('technicalProfile')),
                    'documents' => DocumentResource::collection($this->whenLoaded('documents')),
                ]
            ),
        ];
    }
}
