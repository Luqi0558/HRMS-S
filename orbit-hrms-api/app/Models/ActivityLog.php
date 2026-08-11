<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the audit trail.
 *
 * Append-only by convention: nothing in the application updates or deletes
 * these. The actor's name and role are stored alongside the foreign key so an
 * entry still reads correctly after the employee record is gone.
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'actor_id', 'actor_name', 'actor_role',
        'module', 'action', 'details',
        'target_id', 'target_type',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_id');
    }
}
