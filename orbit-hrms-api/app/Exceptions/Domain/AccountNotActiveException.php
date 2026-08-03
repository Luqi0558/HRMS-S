<?php

namespace App\Exceptions\Domain;

class AccountNotActiveException extends DomainException
{
    protected int $status = 403;
    protected string $errorCode = 'account_not_active';

    public function __construct(string $status)
    {
        parent::__construct(match ($status) {
            'invited' => 'This account has not completed onboarding yet. Please use your invitation link.',
            'blocked' => 'This account is blocked. Please contact HR or your Super Admin.',
            default => 'This account cannot sign in.',
        });
    }
}
