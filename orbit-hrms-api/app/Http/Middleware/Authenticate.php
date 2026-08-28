<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Where to send an unauthenticated visitor — nowhere, in this application.
     *
     * This is a token API with no login page: `login` is a POST route that
     * returns a bearer token, not a named page to redirect to. The scaffolded
     * version of this method called route('login') for any request that did
     * not expect JSON, which cannot resolve and threw
     * RouteNotFoundException — so a missing or expired token produced a 500,
     * with a full stack trace written to the log, instead of a plain 401.
     *
     * It only showed for requests without an `Accept: application/json`
     * header, which made it look intermittent: most of the client sends that
     * header, but file downloads (check-in photos, claim receipts) did not, and
     * the attendance ledger requests every photo on the page at once. One
     * expired token there meant a burst of 500s and megabytes of stack traces.
     *
     * Returning null makes the framework render its 401 for every caller.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
