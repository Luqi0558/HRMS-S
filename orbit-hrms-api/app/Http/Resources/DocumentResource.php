<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            // The key the screens use for this type (cv, offerLetter, ...).
            'key' => array_search($this->type, \App\Models\EmployeeDocument::TYPE_MAP, true) ?: $this->type,
            // The uploader's own filename, as a display label only — the file
            // is stored under a generated name.
            'name' => $this->original_name,
            'hasFile' => filled($this->path),
            'status' => $this->status,
            'verifiedAt' => $this->verified_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'expiresAt' => $this->expires_at?->toDateString(),
        ];
    }
}
