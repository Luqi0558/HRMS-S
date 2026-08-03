<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'projectId' => $this->project_id,
            'moduleId' => $this->project_module_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'assigneeId' => $this->assignee_id,
            'deadline' => $this->deadline?->toDateString(),
            'completedAt' => $this->completed_at?->toIso8601String(),

            // Computed rather than stored: a delivered ticket is never late,
            // so this depends on status as well as the date.
            'isOverdue' => $this->isOverdue(),

            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->assignee?->id,
                'name' => $this->assignee?->name,
            ]),
            'module' => $this->whenLoaded('module', fn () => [
                'id' => $this->module?->id,
                'name' => $this->module?->name,
                'slug' => $this->module?->slug,
            ]),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project?->id,
                'name' => $this->project?->name,
                'slug' => $this->project?->slug,
            ]),

            'comments' => TicketCommentResource::collection($this->whenLoaded('comments')),
            'timeLogs' => TicketTimeLogResource::collection($this->whenLoaded('timeLogs')),
        ];
    }
}
