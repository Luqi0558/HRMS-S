<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalarySlipRequest extends Model
{
    protected $fillable = [
        'employee_id', 'period', 'status',
        'reviewed_by', 'reviewed_at', 'review_comment',
    ];

    protected $casts = [
        'period' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    /** The slip this request produced, once approved. */
    public function slip(): HasOne
    {
        return $this->hasOne(SalarySlip::class);
    }
}
