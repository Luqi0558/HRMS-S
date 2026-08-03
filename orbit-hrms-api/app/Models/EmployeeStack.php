<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per stack an employee works in. Matches the frontend's
 * `technical.stacks` array.
 */
class EmployeeStack extends Model
{
    protected $fillable = ['employee_id', 'stack'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
