<?php

namespace App\Http\Middleware;

use App\Models\ClientApiIntegration;
use App\Services\ClientApiAudit;
use App\Services\ClientApiIntegrations;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server authentication for the client information API: HTTPS only in production, then "Authorization: Bearer <key_id>.<secret>".
 * Every failure looks the same, so a caller learns nothing about which part was wrong.
 */
class AuthenticateClientApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->isSecure()) {
            return response()->json(['success' => false, 'message' => 'HTTPS is required.'], 403);
        }

        $integration = $this->integrationFor((string) $request->bearerToken());
        if ($integration === null) {
            ClientApiAudit::record('auth_failed');

            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $integration->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('client_api_integration', $integration);

        return $next($request);
    }

    private function integrationFor(string $token): ?ClientApiIntegration
    {
        [$keyId, $secret] = array_pad(explode('.', $token, 2), 2, '');
        $integration = $keyId === '' ? null : ClientApiIntegration::where('key_id', $keyId)->first();
        // Compare against a dummy hash when the key is unknown so the response time does not reveal whether a key exists.
        $expected = $integration->secret_hash ?? str_repeat('0', 64);
        $matches = hash_equals($expected, ClientApiIntegrations::hash($secret));

        return $matches && $integration !== null && $integration->isUsable() ? $integration : null;
    }
}
