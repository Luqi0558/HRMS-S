<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignment extends Model
{
    protected $fillable = [
        'asset_id', 'employee_id', 'employee_name',
        'issued_on', 'return_due', 'returned_on',
        'condition_out', 'condition_in', 'notes', 'issued_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'return_due' => 'date',
        'returned_on' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issued_by');
    }

    /** Still out — the asset has not come back. */
    public function isOpen(): bool
    {
        return $this->returned_on === null;
    }
}
