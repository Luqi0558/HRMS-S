<?php

namespace App\Exceptions\Domain;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Base for expected business-rule failures (bad credentials, expired token).
 * Rendering itself keeps controllers free of try/catch and guarantees every
 * such failure leaves the API in the same JSON shape.
 */
abstract class DomainException extends Exception
{
    protected int $status = 400;
    protected string $errorCode = 'domain_error';

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
        ], $this->status);
    }
}
