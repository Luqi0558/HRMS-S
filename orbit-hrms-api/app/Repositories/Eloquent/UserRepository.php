<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;

class UserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly User $model)
    {
    }

    public function findActiveByEmail(string $email): ?User
    {
        // Normalised to lower case: MySQL's default collation is already
        // case-insensitive, but normalising here keeps behaviour identical if
        // the connection is ever switched to a binary collation or Postgres.
        return $this->model->newQuery()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();
    }

    public function findById(int $id): ?User
    {
        return $this->model->newQuery()->find($id);
    }

    public function create(array $attributes): User
    {
        $user = $this->model->newInstance();
        $user->fill($attributes);

        // role and status are guarded, so fill() ignores them. Assigning
        // explicitly is deliberate: these decide what the account may do.
        $user->role = $attributes['role'] ?? 'employee';
        $user->status = $attributes['status'] ?? 'invited';
        $user->save();

        return $user;
    }

    public function activateWithPassword(User $user, string $plainPassword): User
    {
        // The model's `hashed` cast handles hashing, so no Hash::make() here —
        // doing both would double-hash and lock the account out.
        $user->password = $plainPassword;
        $user->status = 'active';
        $user->save();

        return $user;
    }

    public function touchLastLogin(User $user): void
    {
        // forceFill + save rather than update(), because last_login_at is not
        // in $fillable and never should be.
        $user->forceFill(['last_login_at' => now()])->save();
    }

    public function emailExists(string $email): bool
    {
        return $this->model->newQuery()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();
    }
}
