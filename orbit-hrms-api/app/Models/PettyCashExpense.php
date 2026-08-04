<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashExpense extends Model
{
    protected $fillable = [
        'amount', 'date', 'category', 'payee', 'description', 'receipt_path',
        'logged_by', 'status', 'approved_by', 'approved_at', 'review_comment',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function logger(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'logged_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }
}
