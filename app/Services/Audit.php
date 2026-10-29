<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function log(string $action, ?string $subject = null, ?string $details = null): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject' => $subject, 'details' => $details]);
    }
}
