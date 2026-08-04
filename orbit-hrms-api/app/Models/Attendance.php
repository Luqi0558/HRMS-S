<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    // Laravel would pluralise this to "attendances".
    protected $table = 'attendance';

    protected $fillable = [
        'employee_id', 'date', 'check_in', 'check_out',
        'status', 'device', 'photo_path', 'notes',
    ];

    protected $casts = ['date' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Seconds between check-in and check-out; null while still checked in. */
    public function workedSeconds(): ?int
    {
        if (! $this->check_in || ! $this->check_out) {
            return null;
        }

        return strtotime($this->check_out) - strtotime($this->check_in);
    }
}
