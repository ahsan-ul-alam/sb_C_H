<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Audit trail of the client information API: who (which integration), which client, what happened, from where. It never receives a code,
 * a credential or any client data.
 */
class ClientApiAudit
{
    public static function record(string $event, ?int $integrationId = null, ?int $clientId = null, ?string $requestId = null, ?string $detail = null): void
    {
        DB::table('client_api_audits')->insert([
            'client_api_integration_id' => $integrationId, 'client_id' => $clientId, 'event' => $event, 'request_id' => $requestId,
            'ip' => request()->ip(), 'detail' => $detail === null ? null : mb_substr($detail, 0, 250), 'created_at' => now(),
        ]);
    }
}
