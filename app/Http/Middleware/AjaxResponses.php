<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets every existing form handler answer AJAX callers without changing the handler: a redirect that carries
 * a flash "status"/"error" becomes JSON (200 or 422). Normal browser submissions are untouched, so the same
 * validation, authorisation and business rules run on both paths.
 */
class AjaxResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->expectsJson() || ! $response instanceof RedirectResponse || ! $request->hasSession()) {
            return $response;
        }

        $session = $request->session();
        if ($error = $session->pull('error')) {
            return response()->json(['ok' => false, 'message' => $error], 422);
        }

        return response()->json(['ok' => true, 'message' => $session->pull('status'), 'redirect' => $response->getTargetUrl()]);
    }
}
