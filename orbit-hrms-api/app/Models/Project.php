<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'manager_id', 'status'];

    /** Slugs are in URLs, so route model binding resolves by them. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(ProjectModule::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'project_members')
            ->withTimestamps();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
