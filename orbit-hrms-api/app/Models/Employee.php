<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The employment record. Its sign-in identity, if it has one, is `user`.
 */
class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'employee_code',
        'name',
        'email',
        'designation',
        'department',
        'birthday',
        'joining_date',
        'exit_date',
        'employment_type',
        'probation_status',
        'status',
    ];

    protected $casts = [
        'birthday' => 'date',
        'joining_date' => 'date',
        'exit_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function technicalProfile(): HasOne
    {
        return $this->hasOne(EmployeeTechnicalProfile::class);
    }

    public function stacks(): HasMany
    {
        return $this->hasMany(EmployeeStack::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
