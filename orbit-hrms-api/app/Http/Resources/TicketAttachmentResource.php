<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'size' => $this->size_bytes,
            'mimeType' => $this->mime_type,
            'uploadedById' => $this->uploaded_by,
            'uploadedBy' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'uploadedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
