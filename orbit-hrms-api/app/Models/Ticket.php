<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id', 'project_module_id', 'title',
        'reference', 'description',
        'status', 'priority', 'assignee_id', 'created_by', 'deadline',
        'completed_at',
    ];

    protected $casts = [
        'deadline' => 'date',
        'completed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ProjectModule::class, 'project_module_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }

    /**
     * Whoever raised the ticket. The board shows "Assigned by", and employees
     * cannot read the employee roster to resolve created_by themselves — so the
     * name has to travel with the ticket.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->latest();
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(TicketTimeLog::class);
    }

    /** Overdue only applies to work still open — a delivered ticket is not late. */
    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && $this->status !== 'done'
            && $this->deadline->isPast();
    }
}
