<?php

namespace App\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Makes a create-style form submission happen exactly once. The form carries a server-generated random key (required, and it must be a UUID); the key
 * is claimed inside the same transaction that creates the record, so a double-click, a retry, or two concurrent requests with the same key create one
 * record: the loser waits on the unique index, then is told which record already exists. The claim also records who submitted, for which action and a
 * hash of the submitted details, so a key can only ever repeat the very same submission: reusing it for different details is refused, never accepted
 * and never silently swallowed. If the work fails, the whole transaction (key included) rolls back and the same form can be submitted again safely.
 * Content is never matched: two genuine entries with the same amount and date are both accepted when they come from two different forms.
 *
 * Internal service calls (ExpenseVerifier, InvestmentService, ...) do not go through the guard; it protects the browser form endpoints only.
 */
class SubmissionGuard
{
    /**
     * @param  array<string, mixed>  $payload  everything the user submitted that defines the transaction (the key itself excluded)
     * @param  Closure(): Model  $work
     *
     * @throws DuplicateSubmission when the key was already used by this same submission
     * @throws ValidationException when the key is missing, malformed, or was used for different details
     */
    public static function once(?string $key, string $action, ?int $userId, array $payload, Closure $work): Model
    {
        if ($key === null || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['submission_key' => 'This form is out of date. Reload the page and submit again.']);
        }
        $hash = self::fingerprint($payload);

        try {
            return DB::transaction(function () use ($key, $action, $userId, $hash, $work): Model {
                $claimed = DB::table('submission_keys')->insertOrIgnore([
                    'key' => $key, 'action' => $action, 'user_id' => $userId, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($claimed === 0) {
                    $row = DB::table('submission_keys')->where('key', $key)->first();
                    if (! $row || $row->action !== $action || (int) $row->user_id !== (int) $userId || $row->payload_hash !== $hash) {
                        throw new SubmissionKeyReused;
                    }

                    throw new DuplicateSubmission($row->subject_type ? $row->subject_type.' #'.$row->subject_id : 'an earlier submission');
                }

                $subject = $work();
                DB::table('submission_keys')->where('key', $key)->update(['subject_type' => class_basename($subject), 'subject_id' => $subject->getKey()]);

                return $subject;
            });
        } catch (DuplicateSubmission $e) {
            // Logged after the transaction ended, so the entry is not rolled back with it. It names the record, never an amount or a party.
            Audit::log('submission.duplicate_blocked', $e->reference, $action);

            throw $e;
        } catch (SubmissionKeyReused $e) {
            Audit::log('submission.key_reuse_refused', null, $action);

            throw ValidationException::withMessages(['submission_key' => 'This form was already used for different details. Reload the page and submit again.']);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function fingerprint(array $payload): string
    {
        $normalise = function (mixed $value) use (&$normalise): mixed {
            if (is_array($value)) {
                ksort($value);

                return array_map($normalise, $value);
            }

            return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        };

        return hash('sha256', json_encode($normalise($payload)));
    }
}
