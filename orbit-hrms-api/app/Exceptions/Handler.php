<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        /*
         * Tenant identification failures are shaped in TenancyServiceProvider
         * via stancl's $onFail hook — they are thrown inside the middleware
         * stack, where renderable() does not reliably catch them.
         */
        /*
         * Domain-ish HTTP exceptions thrown from services. Without these the
         * API returns a full stack trace with debug on, and a bare
         * {"message":"Server Error"} with it off — neither of which a client
         * can act on.
         */
        $this->renderable(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'Not found.',
                    'error' => 'not_found',
                ], 404);
            }
        });

        $this->renderable(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'You do not have permission to do that.',
                    'error' => 'forbidden',
                ], 403);
            }
        });

        $this->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'unauthenticated',
                ], 401);
            }
        });
    }
}
