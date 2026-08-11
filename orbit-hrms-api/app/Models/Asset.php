<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'category', 'serial_number', 'condition',
        'status', 'assigned_to', 'notes',
    ];

    public function holder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('issued_on');
    }

    /**
     * The assignment that has not been returned, if any.
     *
     * `returned_on IS NULL` is what defines an asset as still out — the status
     * column is a denormalised convenience, this is the truth.
     */
    public function openAssignment(): HasOne
    {
        return $this->hasOne(AssetAssignment::class)->whereNull('returned_on');
    }
}
