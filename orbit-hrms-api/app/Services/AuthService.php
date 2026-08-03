<?php

namespace App\Services;

use App\Exceptions\Domain\AccountNotActiveException;
use App\Exceptions\Domain\InvalidCredentialsException;
use App\Models\User;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly EmployeeRepositoryInterface $employees,
    ) {
    }

    /**
     * @return array{user: User, token: string}
     *
     * @throws InvalidCredentialsException|AccountNotActiveException
     */
    public function login(string $email, string $password, string $deviceName = 'spa'): array
    {
        $user = $this->users->findActiveByEmail($email);

        // Hash::check against a dummy when the user is missing, so a request
        // for an unknown address costs the same time as one for a known
        // address. Returning early here would leak account existence through
        // response timing regardless of the identical error message.
        if (! $user || $user->password === null) {
            Hash::check($password, '$2y$12$usesomesillystringfoeindsdsdsddsdsdsdsdsdsds');

            throw new InvalidCredentialsException();
        }

        if (! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException();
        }

        // Checked only after the password verifies. Doing it first would let an
        // attacker enumerate blocked or invited accounts without credentials.
        if ($user->status !== 'active') {
            throw new AccountNotActiveException($user->status);
        }

        $this->users->touchLastLogin($user);

        return [
            'user' => $this->withEmployee($user),
            'token' => $user->createToken($deviceName)->plainTextToken,
        ];
    }

    /**
     * Revokes only the token that made this request, so signing out on one
     * device leaves other sessions intact.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }
    }

    public function logoutEverywhere(User $user): void
    {
        $user->tokens()->delete();
    }

    public function currentUser(User $user): User
    {
        return $this->withEmployee($user);
    }

    private function withEmployee(User $user): User
    {
        $employee = $this->employees->findByUserId($user->id);
        // setRelation, not load(): the relation may legitimately be null (a
        // user without an employment record), and this keeps that explicit.
        $user->setRelation('employee', $employee);

        return $user;
    }
}
