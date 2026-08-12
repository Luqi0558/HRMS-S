<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function sessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class)->orderBy('check_in');
    }

    /**
     * Seconds actually worked: the sum of the day's finished sessions.
     *
     * This used to be simply check_out minus check_in, which is the same number
     * while a day holds one session and wrong the moment it holds two — it
     * counts any gap between them as work.
     *
     * Null while nothing has been completed yet, which the UI renders as a dash
     * rather than a misleading zero.
     */
    public function workedSeconds(): ?int
    {
        $finished = $this->sessions->filter(fn ($session) => $session->check_out !== null);

        if ($finished->isEmpty()) {
            return null;
        }

        return (int) $finished->sum(fn ($session) => $session->workedSeconds());
    }

    /**
     * Time between sessions — an appointment, an errand. Null on a normal day
     * here, since staff stay punched in through the scheduled break, so the UI
     * leaves the column empty rather than showing 0m.
     */
    public function breakSeconds(): ?int
    {
        $finished = $this->sessions
            ->filter(fn ($session) => $session->check_out !== null)
            ->values();

        if ($finished->count() < 2) {
            return null;
        }

        $total = 0;
        for ($i = 1; $i < $finished->count(); $i++) {
            $total += strtotime($finished[$i]->check_in) - strtotime($finished[$i - 1]->check_out);
        }

        return max(0, $total);
    }

    /** The session still running, if any. */
    public function openSession(): ?AttendanceSession
    {
        return $this->sessions->firstWhere('check_out', null);
    }
}
