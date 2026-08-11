<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A job title offered in the employee dropdowns.
 *
 * `is_active` rather than deletion: a designation removed from the list is
 * still recorded on the employees who hold it, and hard-deleting would either
 * orphan those records or force a rename nobody asked for.
 */
class Designation extends Model
{
    protected $fillable = ['name', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
