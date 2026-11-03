<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function log(string $action, ?string $subject = null, ?string $details = null): void
    {
        // The columns are varchar(60): MySQL rejects a longer value (SQLite silently accepts it), so an audit line must never
        // be able to fail the business action it describes. The full text belongs in details.
        $subject = $subject !== null && mb_strlen($subject) > 60 ? mb_substr($subject, 0, 57).'…' : $subject;
        AuditLog::create(['user_id' => auth()->id(), 'action' => mb_substr($action, 0, 60), 'subject' => $subject, 'details' => $details]);
    }
}
