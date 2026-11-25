<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every dashboard page needs the app's login (Fortify, /login) when config dashboard.require_login is on - the public
 * deployment. Read on each request (not when the routes load), so a config change or a test can switch it.
 */
class RequireLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('dashboard.require_login') && ! $request->user()) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
