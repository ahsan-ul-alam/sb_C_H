<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApiIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operator-side management of integrations. A credential is "<key_id>.<secret>"; the secret is shown once and only its SHA-256 is kept
 * (the secret is 48 random characters, so a fast hash is sufficient and lets the lookup stay constant-time).
 */
class ClientApiIntegrations
{
    /** @return array{integration: ClientApiIntegration, credential: string} */
    public static function create(string $name): array
    {
        $keyId = Str::lower(Str::random(12));
        $secret = Str::random(48);
        $integration = ClientApiIntegration::create(['name' => $name, 'key_id' => $keyId, 'secret_hash' => self::hash($secret)]);
        ClientApiAudit::record('integration_created', $integration->id, null, null, $name);

        return ['integration' => $integration, 'credential' => $keyId.'.'.$secret];
    }

    /** Replaces the secret; the previous credential stops working immediately. */
    public static function rotate(ClientApiIntegration $integration): string
    {
        $secret = Str::random(48);
        $integration->update(['secret_hash' => self::hash($secret), 'rotated_at' => now()]);
        ClientApiAudit::record('integration_rotated', $integration->id);

        return $integration->key_id.'.'.$secret;
    }

    public static function revoke(ClientApiIntegration $integration): void
    {
        $integration->update(['is_active' => false, 'revoked_at' => now()]);
        // Codes already sent to clients die with the credential.
        DB::table('client_info_challenges')->where('client_api_integration_id', $integration->id)->whereNull('consumed_at')->update(['expires_at' => now()]);
        ClientApiAudit::record('integration_revoked', $integration->id);
    }

    public static function grant(ClientApiIntegration $integration, Client $client): void
    {
        $integration->clients()->syncWithoutDetaching([$client->id]);
        ClientApiAudit::record('grant_added', $integration->id, $client->id);
    }

    public static function withdraw(ClientApiIntegration $integration, Client $client): void
    {
        $integration->clients()->detach($client->id);
        DB::table('client_info_challenges')->where('client_api_integration_id', $integration->id)->where('client_id', $client->id)->whereNull('consumed_at')->update(['expires_at' => now()]);
        ClientApiAudit::record('grant_withdrawn', $integration->id, $client->id);
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
