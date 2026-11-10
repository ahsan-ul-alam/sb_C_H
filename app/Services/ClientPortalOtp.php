<?php

namespace App\Services;

use App\Mail\ClientInformationCode;
use App\Models\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email one-time codes for the Client Portal login. Same discipline as the client information API: only a keyed hash of the code is stored (never the code),
 * a challenge is bound to the browser session that asked for it, each attempt is counted by one atomic UPDATE and a correct code is consumed by a second one,
 * so two simultaneous correct submissions can never both log in. request() answers every SB number the same way (a masked address and a challenge for this
 * session), so nothing reveals whether a client exists, is active or has a verified email.
 */
class ClientPortalOtp
{
    /**
     * @return array{masked: string, throttled: bool}
     */
    public static function request(mixed $sbNumber, string $binding, string $ip): array
    {
        $sbNumber = is_string($sbNumber) ? strtoupper(trim($sbNumber)) : '';
        $client = SbNumber::isValid($sbNumber) ? Client::where('sb_number', $sbNumber)->first() : null;
        $eligible = $client !== null && ! $client->isArchived() && $client->hasVerifiedEmail();
        $fakeMask = self::fakeMask($sbNumber);
        $masked = $eligible ? self::maskEmail((string) $client->email) : $fakeMask;

        $limits = ['portal-ip:'.$ip => 20, 'portal-session:'.$binding => 8];
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                ClientPortalAudit::record('portal.otp.throttled', $client?->id, 'ip/session limit');

                return ['masked' => $masked, 'throttled' => true];
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 600);
        }
        if (! $eligible) {
            ClientPortalAudit::record('portal.otp.ineligible', $client?->id, $client === null ? 'unknown or malformed SB number' : 'inactive or no verified email');

            return ['masked' => $masked, 'throttled' => false];
        }
        if (! ClientInformationOtp::mailIsDeliverable()) {
            ClientPortalAudit::record('portal.otp.undeliverable', $client->id, 'mailer is not a real delivery transport');

            return ['masked' => $masked, 'throttled' => false];
        }

        $sessionHash = self::sessionHash($binding);
        $recent = DB::table('client_portal_challenges')->where('client_id', $client->id)->where('created_at', '>', now()->subHour())->orderByDesc('id')->get();
        $latest = $recent->first();
        if ($latest !== null && $latest->consumed_at === null && now()->lt(Carbon::parse($latest->created_at)->addSeconds((int) config('portal.resend_cooldown_seconds')))) {
            ClientPortalAudit::record('portal.otp.cooldown', $client->id);

            return ['masked' => $masked, 'throttled' => false];
        }
        if ($recent->count() >= (int) config('portal.otp_per_client_per_hour')) {
            ClientPortalAudit::record('portal.otp.client_limit', $client->id);

            return ['masked' => $masked, 'throttled' => false];
        }

        $nonce = Str::random(40);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $challengeId = DB::transaction(function () use ($client, $nonce, $code, $sessionHash, $ip) {
            // A new code supersedes every earlier open one for this client.
            DB::table('client_portal_challenges')->where('client_id', $client->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);

            return DB::table('client_portal_challenges')->insertGetId(['client_id' => $client->id, 'nonce' => $nonce, 'session_hash' => $sessionHash, 'ip' => $ip,
                'code_hash' => self::codeHash($nonce, $client->sb_number, $sessionHash, $code), 'expires_at' => now()->addSeconds((int) config('portal.otp_ttl_seconds')), 'created_at' => now()]);
        });
        session(['portal.challenge' => $nonce]);
        ClientPortalAudit::record('portal.otp.issued', $client->id);

        try {
            Mail::to((string) $client->email)->send(new ClientInformationCode($client->name, $code, intdiv((int) config('portal.otp_ttl_seconds'), 60)));
            DB::table('client_portal_challenges')->where('id', $challengeId)->update(['sent_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Client portal code email failed', ['exception' => $e::class]);
            ClientPortalAudit::record('portal.otp.send_failed', $client->id);
        }

        return ['masked' => $masked, 'throttled' => false];
    }

    /** The verified client, or null for every kind of failure (the caller is never told which). */
    public static function verify(?string $nonce, mixed $code, string $binding): ?Client
    {
        $code = is_string($code) ? trim($code) : '';
        if ($nonce === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $sessionHash = self::sessionHash($binding);
        $row = DB::table('client_portal_challenges')->where('nonce', $nonce)->where('session_hash', $sessionHash)->first();
        if ($row === null) {
            return null;
        }
        $client = Client::find($row->client_id);
        if ($client === null || $client->isArchived() || ! $client->hasVerifiedEmail()) {
            return null;
        }
        $counted = DB::table('client_portal_challenges')->where('id', $row->id)->whereNull('consumed_at')->whereNotNull('sent_at')
            ->where('attempts', '<', (int) config('portal.otp_max_attempts'))->where('expires_at', '>', now())->update(['attempts' => DB::raw('attempts + 1')]);
        if ($counted !== 1) {
            ClientPortalAudit::record('portal.otp.refused', $client->id, 'closed, expired or too many attempts');

            return null;
        }
        if (! hash_equals($row->code_hash, self::codeHash($nonce, $client->sb_number, $sessionHash, $code))) {
            ClientPortalAudit::record('portal.otp.wrong_code', $client->id);

            return null;
        }
        if (DB::table('client_portal_challenges')->where('id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) !== 1) {
            ClientPortalAudit::record('portal.otp.replay', $client->id);

            return null;
        }
        ClientPortalAudit::record('portal.login', $client->id);

        return $client;
    }

    public static function codeHash(string $nonce, string $sbNumber, string $sessionHash, string $code): string
    {
        return hash_hmac('sha256', implode('|', ['portal', $nonce, $sbNumber, $sessionHash, $code]), (string) config('app.key'));
    }

    /** A random secret kept only in this browser's server-side session: the challenge is bound to it, so it survives session-id regeneration but cannot be used from another browser. */
    public static function binding(): string
    {
        if (! session()->has('portal.binding')) {
            session(['portal.binding' => Str::random(40)]);
        }

        return (string) session('portal.binding');
    }

    public static function sessionHash(string $binding): string
    {
        return hash_hmac('sha256', $binding, (string) config('app.key'));
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $tld = str_contains($domain, '.') ? substr($domain, (int) strrpos($domain, '.')) : '';

        return mb_substr($local, 0, 1).'*****@'.mb_substr($domain, 0, 1).'*****'.$tld;
    }

    /** A believable masked address for an input that matches nothing, so the screen looks the same. */
    private static function fakeMask(string $input): string
    {
        $h = hash_hmac('sha256', $input, (string) config('app.key'));

        return chr(97 + hexdec(substr($h, 0, 2)) % 26).'*****@'.chr(97 + hexdec(substr($h, 2, 2)) % 26).'*****.com';
    }
}
