<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Keys match the frontend's onboarding.personal object exactly. */
class EmployeeProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'phone' => $this->phone,
            // Decrypted by the model cast on read.
            'cnic' => $this->cnic,
            'address' => $this->address,
            'emergencyContact' => $this->emergency_contact,
            'bankAccount' => $this->bank_account,
            'taxNumber' => $this->tax_number,
        ];
    }
}
