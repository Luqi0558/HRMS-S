<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * The sites allowed to call this API, as a comma-separated list in
     * FRONTEND_URLS. Set it to the deployed frontend, e.g.
     *
     *     FRONTEND_URLS=https://orbit-hrms.vercel.app
     *
     * Several are allowed so a preview deployment can be added without a code
     * change. Falls back to '*' only when the variable is unset, which keeps
     * local development working — on a public host, leaving it unset means any
     * website on the internet can call this API with a token it has obtained.
     *
     * Note the port counts: https://lpsapps.com and https://lpsapps.com:7567
     * are different origins to a browser.
     */
    'allowed_origins' => array_filter(
        array_map('trim', explode(',', (string) env('FRONTEND_URLS', '*')))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
