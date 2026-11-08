<?php

namespace App\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Makes a create-style form submission happen exactly once. The form carries a server-generated random key; the key is claimed inside the same
 * transaction that creates the record, so a double-click, a retry, or two concurrent requests with the same key create one record: the loser
 * waits on the unique index, then is told which record already exists. If the work fails, the whole transaction (key included) rolls back and the
 * same form can be submitted again safely. Content is never matched: two genuine entries with the same amount and date are both accepted when
 * they come from two different forms.
 */
class SubmissionGuard
{
    /**
     * @param  Closure(): Model  $work
     *
     * @throws DuplicateSubmission when the key was already used
     */
    public static function once(?string $key, string $action, ?int $userId, Closure $work): Model
    {
        if ($key === null || $key === '') {
            return $work();
        }

        try {
            return DB::transaction(function () use ($key, $action, $userId, $work): Model {
                $claimed = DB::table('submission_keys')->insertOrIgnore([
                    'key' => $key, 'action' => $action, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($claimed === 0) {
                    $row = DB::table('submission_keys')->where('key', $key)->first();

                    throw new DuplicateSubmission($row && $row->subject_type ? $row->subject_type.' #'.$row->subject_id : 'an earlier submission');
                }

                $subject = $work();
                DB::table('submission_keys')->where('key', $key)->update(['subject_type' => class_basename($subject), 'subject_id' => $subject->getKey()]);

                return $subject;
            });
        } catch (DuplicateSubmission $e) {
            // Logged after the transaction ended, so the audit entry is not rolled back with it.
            Audit::log('submission.duplicate_blocked', $e->reference, $action);

            throw $e;
        }
    }
}
