<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One continuous stretch at work: in at 09:02, out at 13:00.
 *
 * A day is made of these. The parent Attendance row keeps the day's first
 * check-in and last check-out for reporting, but the hours actually worked are
 * the sum of its sessions — the gap between them is a break, not work.
 */
class AttendanceSession extends Model
{
    protected $fillable = [
        'attendance_id', 'check_in', 'check_out', 'device', 'photo_path',
    ];

    public function isOpen(): bool
    {
        return $this->check_out === null;
    }

    /** Null while the session is still running. */
    public function workedSeconds(): ?int
    {
        if (! $this->check_in || ! $this->check_out) {
            return null;
        }

        return strtotime($this->check_out) - strtotime($this->check_in);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
