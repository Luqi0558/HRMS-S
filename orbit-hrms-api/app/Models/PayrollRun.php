<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A company payroll cycle for one calendar month. */
class PayrollRun extends Model
{
    protected $fillable = [
        'period', 'status', 'prepared_by', 'submitted_by', 'submitted_at',
        'released_by', 'released_at', 'paid_by', 'paid_at', 'notes',
    ];

    protected $casts = [
        'period' => 'date', 'submitted_at' => 'datetime', 'released_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function preparer(): BelongsTo { return $this->belongsTo(Employee::class, 'prepared_by'); }
    public function submitter(): BelongsTo { return $this->belongsTo(Employee::class, 'submitted_by'); }
    public function releaser(): BelongsTo { return $this->belongsTo(Employee::class, 'released_by'); }
    public function payer(): BelongsTo { return $this->belongsTo(Employee::class, 'paid_by'); }

    public function requests(): HasMany { return $this->hasMany(SalarySlipRequest::class); }

    public function slips(): HasMany { return $this->hasMany(SalarySlip::class); }
}
