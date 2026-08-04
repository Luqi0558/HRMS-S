<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends Model
{
    protected $fillable = [
        'employee_id', 'base_salary', 'house_allowance', 'medical_allowance',
        'effective_from', 'created_by', 'reason',
    ];

    /**
     * Pay is encrypted at rest. The cast means these read back as plain
     * numbers in PHP but are unreadable in a database dump — at the cost of
     * not being summable in SQL, so payroll totals are added in code.
     */
    protected $casts = [
        'base_salary' => 'encrypted',
        'house_allowance' => 'encrypted',
        'medical_allowance' => 'encrypted',
        'effective_from' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
