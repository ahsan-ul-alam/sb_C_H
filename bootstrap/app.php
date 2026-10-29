<?php

use App\Http\Middleware\AjaxResponses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Forwarded headers are trusted ONLY from the proxy IPs/CIDRs listed in TRUSTED_PROXIES (comma separated).
        // Unset (default) = trust nobody. Never use "*" unless the app is reachable solely through the proxy.
        if ($proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))) {
            $middleware->trustProxies(at: $proxies);
        }
        $middleware->web(append: [AjaxResponses::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
