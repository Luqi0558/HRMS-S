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
            // Whether a check-in photo exists, never where it lives. The path is
            // a storage location on a private disk; handing it out would invite
            // the client to construct its own URL to somebody's photograph.
            'hasCheckInPhoto' => filled($this->photo_path),
            // The sum of finished sessions. Staff here stay punched in through the
            // break, so this normally equals one span; it only differs when
            // somebody genuinely punched out and back in mid-day.
            'workedSeconds' => $this->workedSeconds(),
            'breakSeconds' => $this->breakSeconds(),
            // True between checking out and checking back in — the UI shows
            // "Check in" again rather than treating the day as finished.
            'isCheckedIn' => $this->relationLoaded('sessions')
                ? $this->openSession() !== null
                : null,
            'sessions' => $this->whenLoaded('sessions', fn () => $this->sessions->map(fn ($session) => [
                'id' => $session->id,
                'checkIn' => substr((string) $session->check_in, 0, 5),
                'checkOut' => $session->check_out ? substr((string) $session->check_out, 0, 5) : null,
                'device' => $session->device,
                'workedSeconds' => $session->workedSeconds(),
            ])->values()),
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'department' => $this->whenLoaded('employee', fn () => $this->employee?->department),
        ];
    }
}
