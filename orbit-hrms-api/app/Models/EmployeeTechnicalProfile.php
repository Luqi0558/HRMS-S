<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The developer profile — onboarding step two, plus the extra fields the
 * Profile screen adds later (primary stack, employment type, probation).
 *
 * Mirrors the frontend's `onboarding.technical` object. Its `stacks`, `skills`
 * and `currentlyLearning` arrays live in employee_stacks / employee_skills
 * rather than JSON columns here, so "who knows React?" stays an indexed query.
 */
class EmployeeTechnicalProfile extends Model
{
    protected $fillable = [
        'employee_id',
        'designation',
        'primary_stack',
        'experience_years',
        'employment_type',
        'probation_status',
        'github_url',
        'portfolio_url',
        'linkedin_url',
        'submitted_at',
    ];

    protected $casts = [
        'experience_years' => 'decimal:1',
        'submitted_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Both hang off the employee, not this row — the frontend keys them by
     * employee and they must survive a technical profile being rebuilt.
     */
    public function stacks(): HasMany
    {
        return $this->hasMany(EmployeeStack::class, 'employee_id', 'employee_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_id', 'employee_id');
    }
}
