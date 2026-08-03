<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Keys mirror the frontend's project shape (src/data/mock.js), so the SPA can
 * swap mock data for this without a mapping layer.
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'managerId' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => [
                'id' => $this->manager?->id,
                'name' => $this->manager?->name,
            ]),

            // withCount aliases; absent when the caller did not ask for them.
            'moduleCount' => $this->whenCounted('modules'),
            'ticketCount' => $this->whenCounted('tickets'),
            'openTicketCount' => $this->when(
                isset($this->open_tickets_count),
                fn () => (int) $this->open_tickets_count
            ),
            'overdueTicketCount' => $this->when(
                isset($this->overdue_tickets_count),
                fn () => (int) $this->overdue_tickets_count
            ),

            'teamMembers' => $this->whenLoaded('members', fn () => $this->members->pluck('id')),
            'members' => EmployeeSummaryResource::collection($this->whenLoaded('members')),
            'modules' => ProjectModuleResource::collection($this->whenLoaded('modules')),
        ];
    }
}
