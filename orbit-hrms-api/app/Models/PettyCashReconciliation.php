<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashReconciliation extends Model
{
    protected $fillable = [
        'date', 'counted_amount', 'book_balance', 'variance', 'note', 'counted_by',
    ];

    protected $casts = [
        'date' => 'date',
        'counted_amount' => 'decimal:2',
        'book_balance' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    public function counter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'counted_by');
    }
}
