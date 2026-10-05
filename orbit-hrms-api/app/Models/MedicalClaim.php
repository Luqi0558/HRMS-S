<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalClaim extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'type', 'amount', 'description', 'incurred_on',
        'status', 'receipt_path', 'reviewed_by', 'reviewed_at',
        'review_comment', 'approved_amount',
        'viewed_at', 'viewed_by', 'processing_at', 'processing_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'incurred_on' => 'date',
        'reviewed_at' => 'datetime',
        'viewed_at' => 'datetime',
        'processing_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'viewed_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processing_by');
    }

    /**
     * Where an undecided claim is in review: null, 'view' or 'processing'.
     * Only meaningful while status is pending - a decision supersedes it.
     */
    public function reviewStage(): ?string
    {
        if ($this->status !== 'pending') {
            return null;
        }

        return $this->processing_at ? 'processing' : ($this->viewed_at ? 'view' : null);
    }
}
