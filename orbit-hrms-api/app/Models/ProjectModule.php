<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectModule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id', 'name', 'slug', 'description', 'lead_id', 'status',
        'delivered_at', 'budget', 'commission_type', 'commission_value',
        'commission_status',
    ];

    protected $casts = [
        'delivered_at' => 'date',
        'budget' => 'decimal:2',
        'commission_value' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'project_module_members')
            ->withTimestamps();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ModulePayment::class);
    }
}
