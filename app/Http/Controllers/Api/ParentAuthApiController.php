<?php

namespace App\Http\Controllers\Api;

use App\Models\Guardian;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * PART P1–P7 — OTP auth for the Zippi Parent mobile app.
 *
 * ⚠ PART P7 — THE ERROR CONTRACT IS PART OF THE PRODUCT. Every non-2xx carries
 * a `message` the app renders VERBATIM:
 *
 *   verify ok        200 {status:true,  message, guardian:{...}, token}
 *   wrong code       400 {status:false, message, attempts_remaining:N}
 *   locked           429 {status:false, message}
 *   send throttled   429 {status:false, message}
 *
 * An app that clears the input and shows nothing on a non-2xx is a defect, not
 * a cosmetic issue — it is how a parent ends up guessing at a locked code.
 */
class ParentAuthApiController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** POST /api/parent/otp — PART P4 throttled. */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:24']]);

        // ⚠ Plausibility is checked on the RAW input — see PhoneNumber.
        if (! PhoneNumber::plausible($data['phone'])) {
            return response()->json([
                'status' => false,
                'message' => 'That does not look like a mobile number. Please check it.',
            ], 422);
        }

        $phone = $this->normalise($data['phone']);

        $result = $this->otp->send($phone, 'guardian', $request->ip());

        if (! $result['ok']) {
            return response()->json([
                'status' => false,
                'message' => $result['message'],
                'retry_after' => $result['retry_after'] ?? null,
            ], 429);
        }

        // ⚠ ENUMERATION: identical response whether or not the number is known.
        // Telling a stranger which numbers are parents at a school is a
        // child-safety leak, not merely an information leak.
        return response()->json(['status' => true, 'message' => $result['message']]);
    }

    /** POST /api/parent/otp/verify — issues the bearer token. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:24'],
            'code' => ['required', 'string', 'max:8'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $phone = $this->normalise($data['phone']);
        $result = $this->otp->verify($phone, $data['code'], 'guardian');

        if (! $result['ok']) {
            return response()->json(array_filter([
                'status' => false,
                'message' => $result['message'],
                'attempts_remaining' => $result['attempts_remaining'] ?? null,
            ], fn ($v) => $v !== null), $result['status'] ?? 400);
        }

        $guardian = PhoneNumber::findGuardian($phone);

        if (! $guardian || $guardian->status !== 'active' || ! $guardian->app_access) {
            return response()->json([
                'status' => false,
                'message' => 'This number is not registered for the parent app. '
                           . 'Please contact your school office.',
            ], 403);
        }

        // One token per device name, replaced on re-login so a reinstalled app
        // does not leave a live token behind on a phone that was handed on.
        //
        // ⚠ `?? null` is load-bearing. `nullable` means "may be null IF PRESENT";
        // validate() returns only the keys that were actually sent, so a client
        // omitting device_name entirely made this an undefined-key ErrorException
        // — a 500 on the endpoint that issues the token. Both Flutter apps send
        // the field, which is why it stayed hidden; anything else logging in hit
        // it on the first try. A 5xx here is also the worst possible shape: the
        // apps read 4xx as a refusal to render, but 5xx as a transport failure.
        $device = ($data['device_name'] ?? null) ?: 'mobile';
        $guardian->tokens()->where('name', $device)->delete();

        $token = $guardian->createToken($device)->plainTextToken;

        $guardian->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'status' => true,
            'message' => 'Signed in.',
            'token' => $token,
            'guardian' => [
                'id' => $guardian->id,
                'name' => $guardian->name,
                'phone' => $guardian->phone,
            ],
        ]);
    }

    /** POST /api/parent/logout — revokes only the calling device's token. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['status' => true, 'message' => 'Signed out.']);
    }

    /**
     * POST /api/parent/device-token — PART L10.
     *
     * An FCM token belongs to at most ONE guardian. claimFcmToken() strips it
     * from every other row first; without that, one family's boarding push
     * lands under another guardian's identity.
     */
    public function deviceToken(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string', 'max:255']]);

        Guardian::claimFcmToken($data['fcm_token'], $request->user()->id);

        return response()->json(['status' => true, 'message' => 'Device registered.']);
    }

    /**
     * ⚠ The OTP identity and the guardian lookup MUST use the same canonical
     * form. Keying the code on "+9198…" while the guardian row says "98…" makes
     * the code verify and the account lookup fail — the parent is told their
     * number is not registered when it plainly is. See App\Support\PhoneNumber.
     */
    private function normalise(string $phone): string
    {
        return PhoneNumber::canonical($phone);
    }

}
