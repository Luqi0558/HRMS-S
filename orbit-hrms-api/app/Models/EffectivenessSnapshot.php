<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EffectivenessSnapshot extends Model
{
    protected $fillable = [
        'employee_id', 'period_start', 'period_end', 'role', 'department',
        'score', 'confidence', 'metrics', 'evidence', 'policy_version',
        'generated_at', 'finalized_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'score' => 'float',
        'metrics' => 'array',
        'evidence' => 'array',
        'generated_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
