<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Flattens three parallel frontend arrays into one table:
 *   technical.skills           -> a row per skill
 *   technical.skillLevels{}    -> the `level` column
 *   technical.currentlyLearning-> the `is_learning` flag
 *
 * `level` is nullable because the onboarding wizard collects skills without
 * proficiency; the Profile screen fills it in afterwards.
 */
class EmployeeSkill extends Model
{
    protected $fillable = ['employee_id', 'skill', 'level', 'is_learning'];

    protected $casts = ['is_learning' => 'boolean'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
