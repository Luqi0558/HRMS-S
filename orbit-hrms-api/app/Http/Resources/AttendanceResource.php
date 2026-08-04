<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Keys match the frontend's attendance shape in src/data/mock.js. */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'date' => $this->date?->toDateString(),
            // Trimmed to HH:MM: MySQL returns TIME as HH:MM:SS, and the UI
            // renders "09:02" not "09:02:00".
            'checkIn' => $this->check_in ? substr($this->check_in, 0, 5) : null,
            'checkOut' => $this->check_out ? substr($this->check_out, 0, 5) : null,
            'status' => $this->status,
            'device' => $this->device,
            'workedSeconds' => $this->workedSeconds(),
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'department' => $this->whenLoaded('employee', fn () => $this->employee?->department),
        ];
    }
}
