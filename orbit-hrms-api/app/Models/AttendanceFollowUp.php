<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceFollowUp extends Model
{
    public const ISSUES = ['late', 'absent', 'missing_checkout', 'early_leave', 'other'];

    public const ACTIONS = ['note', 'called', 'emailed', 'met', 'reminder', 'warning'];

    protected $fillable = [
        'employee_id', 'attendance_id', 'date', 'issue', 'action', 'note',
        'status', 'resolution', 'resolved_by', 'resolved_at',
        'created_by', 'message_id',
    ];

    protected $casts = [
        'date' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }
}
