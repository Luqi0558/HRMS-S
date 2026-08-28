<?php

namespace App\Exceptions;

use App\Exceptions\Domain\DomainException;
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
        // Domain exceptions are expected outcomes, not faults: an expired
        // invitation link or a wrong password is the system working. Each one
        // renders its own 4xx response, so logging them at ERROR fills the log
        // with normal traffic and buries the failures worth reading.
        DomainException::class,
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
        /*
         * Domain exceptions are expected outcomes, not faults: an expired
         * invitation link or a wrong password is the system working as
         * designed, and each renders its own 4xx response. Returning false
         * stops the default logging — without this the log fills with normal
         * traffic and buries the failures actually worth reading.
         */
        $this->reportable(function (DomainException $e) {
            return false;
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

        /*
         * Unconditional, deliberately.
         *
         * This used to be guarded on expectsJson(), and anything without an
         * `Accept: application/json` header fell through to the framework's
         * own unauthenticated(), which redirects to route('login'). There is
         * no route named 'login' in a token API — it is a POST that returns a
         * bearer token — so that call threw RouteNotFoundException and a
         * missing or expired token produced a 500 with a full stack trace in
         * the log rather than a 401.
         *
         * It looked intermittent because almost every caller does send that
         * header. File downloads did not, and the attendance ledger fetches
         * one per photo on the page, so a single expired token turned into a
         * burst of 500s and megabytes of stack traces.
         *
         * Nothing in this application serves HTML, so there is no case where
         * redirecting is the right answer.
         */
        $this->renderable(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'unauthenticated',
            ], 401);
        });
    }
}
