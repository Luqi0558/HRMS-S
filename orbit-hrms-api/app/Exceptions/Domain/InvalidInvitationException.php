<?php

namespace App\Exceptions\Domain;

class InvalidInvitationException extends DomainException
{
    protected int $status = 422;
    protected string $errorCode = 'invalid_invitation';

    public static function notFound(): self
    {
        return new self('This invitation link is not valid.');
    }

    public static function expired(): self
    {
        return new self('This invitation link has expired. Ask HR to send a new one.');
    }

    public static function alreadyAccepted(): self
    {
        return new self('This invitation has already been used.');
    }
}
