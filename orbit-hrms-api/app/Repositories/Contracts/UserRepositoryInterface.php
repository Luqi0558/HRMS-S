<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface UserRepositoryInterface
{
    /**
     * Excludes soft-deleted rows. Because `email` is only indexed and not
     * unique at the database level (soft deletes make a unique constraint
     * reject re-hires), this is what enforces "one live account per address"
     * on the read side.
     */
    public function findActiveByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    public function create(array $attributes): User;

    /**
     * Sets the first password and flips an invited account to active. One
     * method rather than two so the two writes cannot drift apart — an active
     * account with no password would be able to bypass the sign-in check.
     */
    public function activateWithPassword(User $user, string $plainPassword): User;

    public function touchLastLogin(User $user): void;

    public function emailExists(string $email): bool;
}
