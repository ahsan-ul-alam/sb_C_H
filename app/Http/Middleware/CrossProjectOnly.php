<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For screens that add up or list several projects at once (company-wide stock, movements, reports): a user who is limited to certain projects is refused,
 * because those pages cannot show them only their own part. Users with cross-project access, and users with no project limit, pass unchanged.
 */
class CrossProjectOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->seesAllProjects(), 403, 'This page covers all projects; your access is limited to your assigned projects.');

        return $next($request);
    }
}
