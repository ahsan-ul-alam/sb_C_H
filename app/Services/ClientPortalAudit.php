<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * Audit lines for the Client Portal. Never given a code. The acting staff user (admin preview) is recorded when signed in; public client events have none.
 */
class ClientPortalAudit
{
    public static function record(string $action, ?int $clientId, ?string $details = null, ?int $userId = null): void
    {
        AuditLog::create(['user_id' => $userId ?? auth()->id(), 'action' => mb_substr($action, 0, 60), 'subject' => $clientId ? 'client '.$clientId : null, 'details' => $details]);
    }
}
