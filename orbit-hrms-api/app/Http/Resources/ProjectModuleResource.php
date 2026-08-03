<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'projectId' => $this->project_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'deliveredAt' => $this->delivered_at?->toDateString(),
            'leadId' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead?->id,
                'name' => $this->lead?->name,
            ]),

            // Money is a string, not a float: JSON numbers are doubles, and
            // a budget rendered through one can lose precision.
            'budget' => (string) $this->budget,
            'commission' => [
                'type' => $this->commission_type,
                'value' => (string) $this->commission_value,
                'status' => $this->commission_status,
            ],

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
        ];
    }
}
