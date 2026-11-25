<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    |
    | The public deployment (dashboard.reservakit.com) puts every page behind
    | the app's own login (Fortify, /login); the local copy leaves it off
    | (user 2026-11-25). The one account is kept in .env and written to the
    | users table by `php artisan dashboard:admin` (run it after changing it).
    |
    */

    'require_login' => (bool) env('DASHBOARD_REQUIRE_LOGIN', false),

    // Sign-up: off unless set - the routes stay (the login page links them) but a sign-up is refused and the link hidden.
    'allow_registration' => (bool) env('DASHBOARD_ALLOW_REGISTRATION', false),

    'admin' => [
        'name' => (string) env('DASHBOARD_ADMIN_NAME', 'admin'),
        'email' => (string) env('DASHBOARD_ADMIN_EMAIL', ''),
        'password' => (string) env('DASHBOARD_ADMIN_PASSWORD', ''),
    ],
];
