<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal details from the onboarding wizard's first step.
 *
 * Field names match the frontend's `onboarding.personal` object exactly
 * (snake_cased), so the API payload maps straight across without translation.
 */
class EmployeeProfile extends Model
{
    protected $fillable = [
        'employee_id',
        'phone',
        'cnic',
        'address',
        'emergency_contact',
        'bank_account',
        'tax_number',
    ];

    /**
     * National ID, bank and tax details are encrypted at rest. Laravel handles
     * this transparently on read/write — but note these columns can no longer
     * be searched or indexed, which is why none of them carry an index.
     */
    protected $casts = [
        'cnic' => 'encrypted',
        'bank_account' => 'encrypted',
        'tax_number' => 'encrypted',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
