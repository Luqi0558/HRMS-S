<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One policy value. Read through SettingsService rather than directly — every
 * consumer wants the whole group as a keyed array, not rows.
 */
class CompanySetting extends Model
{
    protected $fillable = ['group', 'key', 'value'];

    // Preserves each value's type: a number stays a number, a boolean a
    // boolean. Stored as text, "false" would be truthy in PHP.
    protected $casts = ['value' => 'json'];
}
