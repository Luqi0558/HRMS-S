<?php

namespace App\Exceptions\Domain;

class InvalidCredentialsException extends DomainException
{
    protected int $status = 401;
    protected string $errorCode = 'invalid_credentials';

    public function __construct()
    {
        // Deliberately identical whether the email is unknown or the password
        // is wrong — distinguishing them turns the login form into an account
        // enumeration oracle.
        parent::__construct('These credentials do not match our records.');
    }
}
