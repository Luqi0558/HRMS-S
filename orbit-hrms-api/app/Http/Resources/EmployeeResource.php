<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The employment record.
 *
 * `name` and `email` also exist on `users`, and both are kept: this is the HR
 * record, which is authoritative for payroll and correspondence, while the
 * user's copy is what they sign in with. They can legitimately differ — a
 * departed employee keeps their record after the login is revoked.
 *
 * This resource is therefore never nested inside a user payload, where the two
 * copies would appear side by side with no indication of which one wins.
 */
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
