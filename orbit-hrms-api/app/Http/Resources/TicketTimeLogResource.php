<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketTimeLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'start' => $this->started_at?->toIso8601String(),
            // Null means the timer is still running — the dashboard keys its
            // live counter off exactly this.
            'end' => $this->ended_at?->toIso8601String(),
            'durationSeconds' => $this->duration_seconds,
        ];
    }
}
