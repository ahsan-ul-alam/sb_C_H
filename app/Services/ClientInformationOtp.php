<?php

namespace App\Services;

use App\Mail\ClientInformationCode;
use App\Models\Client;
use App\Models\ClientApiIntegration;
use App\Models\ClientInfoChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Two-step email verification for the client information API.
 *
 * request(): whatever the outcome the caller gets the same shape (a request_id and an expiry), so nothing reveals whether the client exists, has
 * a verified email, or is granted to this integration. A code is created and emailed only when all of those hold and the email can really be delivered.
 * verify(): one atomic conditional UPDATE counts each attempt and refuses once the limit, the expiry, a prior use or an unsent code applies; a second
 * conditional UPDATE consumes the code, so two simultaneous correct submissions can never both succeed.
 */
class ClientInformationOtp
{
    public const PURPOSE = 'client_information';

    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_PER_HOUR = 5;

    /** @return array{request_id: string, expires_in: int} */
    public static function request(ClientApiIntegration $integration, mixed $sbNumber): array
    {
        $client = SbNumber::isValid($sbNumber) ? Client::where('sb_number', $sbNumber)->first() : null;
        $generic = fn (string $id) => ['request_id' => $id, 'expires_in' => self::TTL_SECONDS];

        if ($client === null || ! $integration->mayAccess($client) || ! $client->hasVerifiedEmail() || $client->isArchived()) {
            ClientApiAudit::record('request_ineligible', $integration->id, $client?->id);

            return $generic(Str::random(40));
        }
        if (! self::mailIsDeliverable()) {
            ClientApiAudit::record('request_undeliverable', $integration->id, $client->id, null, 'mailer is not a real delivery transport');

            return $generic(Str::random(40));
        }

        $recent = ClientInfoChallenge::where('client_id', $client->id)->where('client_api_integration_id', $integration->id)->where('created_at', '>', now()->subHour())->orderByDesc('id')->get();
        $latest = $recent->first();
        if ($latest !== null && $latest->consumed_at === null && $latest->expires_at->isFuture() && $latest->created_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            ClientApiAudit::record('request_cooldown', $integration->id, $client->id, $latest->request_id);

            return $generic($latest->request_id);
        }
        if ($recent->count() >= self::MAX_PER_HOUR) {
            ClientApiAudit::record('request_throttled', $integration->id, $client->id);

            return $generic(Str::random(40));
        }

        $requestId = Str::random(40);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $challenge = DB::transaction(function () use ($client, $integration, $requestId, $code) {
            // A new code replaces any earlier one for this client and integration.
            ClientInfoChallenge::where('client_id', $client->id)->where('client_api_integration_id', $integration->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);

            return ClientInfoChallenge::create([
                'request_id' => $requestId, 'client_api_integration_id' => $integration->id, 'client_id' => $client->id, 'purpose' => self::PURPOSE,
                'code_hash' => self::codeHash($requestId, $client->sb_number, $integration->id, self::PURPOSE, $code), 'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            ]);
        });
        ClientApiAudit::record('code_issued', $integration->id, $client->id, $requestId);

        // Emailed after the response is sent (same timing for every outcome); the code is held only in memory. Until it is marked sent, it cannot be verified.
        $name = $client->name;
        $email = (string) $client->email;
        $sent = false;
        app()->terminating(function () use ($challenge, $email, $name, $code, &$sent): void {
            if ($sent) {
                return;
            }
            $sent = true;
            try {
                Mail::to($email)->send(new ClientInformationCode($name, $code, intdiv(self::TTL_SECONDS, 60)));
                $challenge->forceFill(['sent_at' => now()])->save();
            } catch (Throwable $e) {
                Log::warning('Client information code email failed', ['request_id' => $challenge->request_id, 'exception' => $e::class]);
                ClientApiAudit::record('code_send_failed', $challenge->client_api_integration_id, $challenge->client_id, $challenge->request_id);
            }
        });

        return $generic($requestId);
    }

    /** The verified client, or null for every kind of failure (the caller is never told which). */
    public static function verify(ClientApiIntegration $integration, mixed $sbNumber, mixed $requestId, mixed $code): ?Client
    {
        $fail = function (string $why, ?Client $client = null, ?string $rid = null) use ($integration): null {
            ClientApiAudit::record('verify_failed', $integration->id, $client?->id, $rid, $why);

            return null;
        };
        if (! SbNumber::isValid($sbNumber) || ! is_string($requestId) || ! preg_match('/^[A-Za-z0-9]{40}$/', $requestId) || ! is_string($code) || ! preg_match('/^\d{6}$/', $code)) {
            return $fail('malformed');
        }
        $client = Client::where('sb_number', $sbNumber)->first();
        if ($client === null || ! $integration->mayAccess($client)) {
            return $fail('not permitted');
        }
        $challenge = ClientInfoChallenge::where('request_id', $requestId)->where('client_id', $client->id)->where('client_api_integration_id', $integration->id)->where('purpose', self::PURPOSE)->first();
        if ($challenge === null) {
            return $fail('unknown request', $client);
        }

        // Counts this attempt and checks every precondition in one statement.
        $counted = ClientInfoChallenge::whereKey($challenge->id)->whereNull('consumed_at')->whereNotNull('sent_at')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('expires_at', '>', now())->update(['attempts' => DB::raw('attempts + 1')]);
        if ($counted !== 1) {
            return $fail('not open', $client, $requestId);
        }
        if (! hash_equals($challenge->code_hash, self::codeHash($requestId, $client->sb_number, $integration->id, self::PURPOSE, $code))) {
            return $fail('wrong code', $client, $requestId);
        }
        if (ClientInfoChallenge::whereKey($challenge->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) !== 1) {
            return $fail('already used', $client, $requestId);
        }
        ClientApiAudit::record('verified', $integration->id, $client->id, $requestId);

        return $client;
    }

    public static function codeHash(string $requestId, string $sbNumber, int $integrationId, string $purpose, string $code): string
    {
        return hash_hmac('sha256', implode('|', [$requestId, $sbNumber, $integrationId, $purpose, $code]), (string) config('app.key'));
    }

    /** The log and array mailers would put the code in a file or drop it silently, so a challenge is never created on them (outside tests). */
    public static function mailIsDeliverable(): bool
    {
        return app()->environment('testing') || ! in_array(config('mail.default'), ['log', 'array', 'null', null, ''], true);
    }
}
