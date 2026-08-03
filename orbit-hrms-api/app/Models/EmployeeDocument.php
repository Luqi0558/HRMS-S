<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per document slot. `type` values are the snake_case of the frontend's
 * upload keys, so `profile.cnicFront` becomes type 'cnic_front'.
 *
 * The file itself is held by spatie/laravel-medialibrary; this row carries the
 * HR state around it (verified, rejected, expiring).
 */
class EmployeeDocument extends Model
{
    /** Frontend upload key => stored type. */
    public const TYPE_MAP = [
        'photo' => 'photo',
        'cv' => 'cv',
        'cnicFront' => 'cnic_front',
        'cnicBack' => 'cnic_back',
        'education' => 'education',
        'experience' => 'experience',
        'offerLetter' => 'offer_letter',
        'contract' => 'contract',
        'certificates' => 'certificates',
        'experienceLetters' => 'experience_letters',
    ];

    protected $fillable = [
        'employee_id',
        'type',
        'title',
        'media_id',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'mime_type',
        'status',
        'verified_by',
        'verified_at',
        'rejection_reason',
        'expires_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
