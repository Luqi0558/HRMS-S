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
            // The ticket's id in whatever system the work is tracked against
            // elsewhere. Surfaced on the board and used in the Teams summary.
            'reference' => $this->reference,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'assigneeId' => $this->assignee_id,
            // The frontend calls this assignedById: the person who raised the
            // ticket, used for notification routing ("your ticket moved").
            'assignedById' => $this->created_by,
            'deadline' => $this->deadline?->toDateString(),
            'completedAt' => $this->completed_at?->toIso8601String(),

            // Computed rather than stored: a delivered ticket is never late,
            // so this depends on status as well as the date.
            'isOverdue' => $this->isOverdue(),

            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->assignee?->id,
                'name' => $this->assignee?->name,
            ]),
            'assignedBy' => $this->whenLoaded('assignedBy', fn () => [
                'id' => $this->assignedBy?->id,
                'name' => $this->assignedBy?->name,
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
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'timeLogs' => TicketTimeLogResource::collection($this->whenLoaded('timeLogs')),

            // Total of every *finished* log, summed in SQL. The board is not
            // sent the individual logs, so without this it could only ever show
            // zero; the detail endpoint loads at most 50, so it cannot be
            // trusted to add them up either. Both therefore send this, and the
            // frontend adds the live elapsed time of any running log on top.
            //
            // Keyed on the attribute existing rather than on it being non-null:
            // the sum is NULL for a ticket nobody has logged against, and that
            // means zero, not "not loaded".
            'loggedSeconds' => $this->when(
                array_key_exists('logged_seconds', $this->resource->getAttributes()),
                fn () => (int) $this->logged_seconds
            ),
        ];
    }
}
