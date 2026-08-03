<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModulePayment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_module_id', 'amount', 'paid_on', 'method',
        'reference', 'payer', 'notes', 'logged_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(ProjectModule::class, 'project_module_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'logged_by');
    }
}
