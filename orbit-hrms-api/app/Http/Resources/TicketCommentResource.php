<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authorId' => $this->author_id,
            'authorName' => $this->whenLoaded('author', fn () => $this->author?->name),
            'text' => $this->body,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
