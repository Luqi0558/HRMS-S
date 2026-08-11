<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A technical stack offered during onboarding. Deactivated, never deleted. */
class Stack extends Model
{
    protected $fillable = ['name', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
