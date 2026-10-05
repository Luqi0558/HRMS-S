<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EffectivenessSnapshotCycle extends Model
{
    protected $fillable = [
        'period_start', 'period_end', 'status', 'policy_version', 'reviewed_by',
        'reviewed_at', 'review_note', 'finalized_by', 'finalized_at',
    ];

    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date',
        'reviewed_at' => 'datetime', 'finalized_at' => 'datetime',
    ];

    public function reviewer(): BelongsTo { return $this->belongsTo(Employee::class, 'reviewed_by'); }
    public function finalizer(): BelongsTo { return $this->belongsTo(Employee::class, 'finalized_by'); }
}
