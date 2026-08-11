<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audience,
            'priority' => $this->priority,
            'authorId' => $this->author_id,
            // The stored copy, so it still names its author after they leave.
            'authorName' => $this->author_name,
            'publishedAt' => $this->published_at?->toIso8601String(),
        ];
    }
}
