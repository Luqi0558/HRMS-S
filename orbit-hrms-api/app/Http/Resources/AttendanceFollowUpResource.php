<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceFollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'attendanceId' => $this->attendance_id,
            'date' => $this->date?->toDateString(),
            'issue' => $this->issue,
            'action' => $this->action,
            'note' => $this->note,
            'status' => $this->status,
            'resolution' => $this->resolution,
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'resolvedByName' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'createdById' => $this->created_by,
            'createdByName' => $this->whenLoaded('author', fn () => $this->author?->name),
            'createdAt' => $this->created_at?->toIso8601String(),
            'employeeMessaged' => $this->message_id !== null,
        ];
    }
}
