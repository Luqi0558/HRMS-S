<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use, expiring capability to complete one employee's onboarding.
 *
 * Only the SHA-256 hash of the token is stored; the raw value exists in the
 * invite email and nowhere else, so a database dump yields no usable links.
 */
class Invitation extends Model
{
    protected $fillable = [
        'employee_id',
        'email',
        'token_hash',
        'expires_at',
        'accepted_at',
        'invited_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    /** Returns the raw token to email; only its hash is persisted. */
    public static function issueToken(): array
    {
        $raw = Str::random(64);

        return [$raw, hash('sha256', $raw)];
    }

    public function isRedeemable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
