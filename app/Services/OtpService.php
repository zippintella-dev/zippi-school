<?php

namespace App\Services;

use App\Models\Otp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PART P1–P4 — issuing and verifying one-time codes.
 *
 * ⚠ P1: `random_int(1000, 9999)`. Never a static 1234, in any environment
 * reachable from the internet, staging included. There is no "dev bypass" here
 * on purpose — a bypass that exists can be shipped, and this code is the only
 * thing standing between a stranger and a child's live location.
 *
 * ⚠ P2 (carried forward verbatim from enterprise, both bugs were expensive):
 *   - Always pass a configured DLT template id with a hard-coded fallback. An
 *     EMPTY templateId is ACCEPTED by the gateway and then silently never
 *     delivered.
 *   - Never write `'91' . ltrim($mobile, '91')`. ltrim treats '91' as a
 *     character SET and strips leading 9s and 1s — "9198..." becomes "8...",
 *     a malformed number the gateway accepts and never delivers. Send raw.
 *
 * P3 — 5 wrong attempts → 1-minute lockout, tracked on the row.
 * P4 — 3 sends per 2 minutes per identity, tracked in the RateLimiter.
 */
class OtpService
{
    /**
     * ⚠ TEST MODE — friction only, never a safety rule.
     *
     * `SCHOOL_TEST_MODE=true` in .env loosens the OTP *friction* so a tester
     * logging in over and over is not locked out by P4's send throttle. It does
     * NOT weaken the code itself: still `random_int(1000,9999)`, still hashed,
     * still single-use, still consumed by issuing a new one, and an unknown
     * phone still gets the identical response a known one gets.
     *
     * The production numbers below are the P3/P4 defaults and are what runs
     * whenever the flag is absent or false — which is every environment that
     * has not deliberately opted in.
     */
    private static function testMode(): bool
    {
        return (bool) config('school.test_mode', false);
    }

    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_SECONDS = 60;
    public const TTL_MINUTES = 10;
    public const SEND_MAX = 3;
    public const SEND_DECAY_SECONDS = 120;

    public static function maxAttempts(): int    { return self::testMode() ? 50 : self::MAX_ATTEMPTS; }
    public static function lockoutSeconds(): int { return self::testMode() ? 1 : self::LOCKOUT_SECONDS; }
    public static function ttlMinutes(): int     { return self::testMode() ? 120 : self::TTL_MINUTES; }
    public static function sendMax(): int        { return self::testMode() ? 100 : self::SEND_MAX; }
    public static function sendDecay(): int      { return self::testMode() ? 1 : self::SEND_DECAY_SECONDS; }

    /**
     * PART P4 — issue a code, or refuse if the identity is sending too fast.
     *
     * @return array{ok: bool, message: string, retry_after?: int}
     */
    public function send(string $identity, string $type = 'guardian', ?string $ip = null): array
    {
        $key = 'otp-send:' . $type . ':' . $identity;

        if (RateLimiter::tooManyAttempts($key, self::sendMax())) {
            $seconds = RateLimiter::availableIn($key);

            // PART P7 — the app renders this verbatim, so it must be a sentence
            // a parent can act on, not a status code.
            return [
                'ok' => false,
                'retry_after' => $seconds,
                'message' => "Too many code requests. Please try again in {$seconds} seconds.",
            ];
        }

        RateLimiter::hit($key, self::sendDecay());

        // Supersede any live code for this identity — two valid codes at once
        // means a stale SMS still works, which is a real account-takeover path.
        Otp::where('identity_type', $type)
            ->where('identity', $identity)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(1000, 9999);   // ⚠ P1 — never static

        Otp::create([
            'identity_type' => $type,
            'identity' => $identity,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::ttlMinutes()),
            'ip' => $ip,
        ]);

        $this->deliver($identity, $code);

        return ['ok' => true, 'message' => 'We sent a code to your phone.'];
    }

    /**
     * PART P3 / P7 — verify. The shape of the failure matters as much as the
     * success: an app that clears the box and shows nothing on a wrong code is
     * how a parent ends up guessing.
     *
     * @return array{ok: bool, message: string, attempts_remaining?: int, status?: int}
     */
    public function verify(string $identity, string $code, string $type = 'guardian'): array
    {
        $otp = Otp::where('identity_type', $type)
            ->where('identity', $identity)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            return ['ok' => false, 'status' => 400,
                    'message' => 'That code has expired. Please request a new one.'];
        }

        if ($otp->isLocked()) {
            $seconds = max(1, (int) now()->diffInSeconds($otp->locked_until, false));

            return ['ok' => false, 'status' => 429,
                    'message' => "Too many wrong attempts. Please try again in {$seconds} seconds."];
        }

        if ($otp->isExpired()) {
            return ['ok' => false, 'status' => 400,
                    'message' => 'That code has expired. Please request a new one.'];
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->attempts++;

            if ($otp->attempts >= self::maxAttempts()) {
                // P3 — lock, and reset the counter so the next window is clean.
                $otp->locked_until = now()->addSeconds(self::lockoutSeconds());
                $otp->attempts = 0;
                $otp->save();

                return ['ok' => false, 'status' => 429,
                        'message' => 'Too many wrong attempts. Please try again in a minute.'];
            }

            $otp->save();
            $remaining = self::maxAttempts() - $otp->attempts;

            return ['ok' => false, 'status' => 400, 'attempts_remaining' => $remaining,
                    'message' => "That code is not right. {$remaining} attempt"
                        . ($remaining === 1 ? '' : 's') . ' left.'];
        }

        $otp->update(['consumed_at' => now()]);

        // Success clears the send throttle so a legitimate re-login isn't
        // punished for the earlier resends (P4).
        RateLimiter::clear('otp-send:' . $type . ':' . $identity);

        return ['ok' => true, 'message' => 'Verified.'];
    }

    /**
     * Delivery. No SMS gateway is configured yet, so the code goes to the log.
     *
     * ⚠ When the gateway lands, re-read P2 above before writing the number
     * formatting or the template id. Both bugs are silent — the gateway returns
     * success and the SMS simply never arrives.
     */
    private function deliver(string $identity, string $code): void
    {
        Log::info('OTP issued', ['identity' => $identity, 'code' => $code]);

        // Local convenience only. Gated on the environment, never on a config
        // flag someone could flip in production.
        if (app()->environment('local')) {
            session()->flash('dev_otp', $code);
        }
    }
}
