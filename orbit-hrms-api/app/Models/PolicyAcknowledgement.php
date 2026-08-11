<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person agreeing to one VERSION of a policy.
 *
 * The version matters: a policy reissued at 2.0 needs acknowledging again,
 * because an acknowledgement of 1.0 says nothing about the new text.
 */
class PolicyAcknowledgement extends Model
{
    protected $fillable = ['policy_id', 'employee_id', 'version', 'acknowledged_at'];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
