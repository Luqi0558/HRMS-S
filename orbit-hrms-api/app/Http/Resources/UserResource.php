<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shaped to match the frontend's currentUser object (see setCurrentUser in
 * src/store/index.js), so the SPA can consume it without a mapping layer.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            // whenLoaded: never triggers a lazy query, so a missing relation is
            // an omitted key rather than a silent N+1.
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
        ];
    }
}
