<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * `role` and `status` are intentionally NOT mass assignable — they decide
     * what the account may do and whether it may sign in at all, so they must
     * be set explicitly rather than picked up from request input.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * An invited user has no password yet, so `status` is what gates sign-in —
     * never the presence of a password.
     */
    public function canSignIn(): bool
    {
        return $this->status === 'active' && $this->password !== null;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        // Hashes on assignment, so a plaintext password can never be persisted
        // by a code path that forgets to call Hash::make().
        'password' => 'hashed',
    ];
}
