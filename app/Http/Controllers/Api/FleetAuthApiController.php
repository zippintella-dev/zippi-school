<?php

namespace App\Http\Controllers\Api;

use App\Models\SchoolStaff;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * PART P1–P7 — OTP auth for the Zippi Fleet vehicle app (Layer 3).
 *
 * Deliberately the same shape as ParentAuthApiController: the same person may
 * hold both apps, and a second sign-in idiom is a second thing to explain in
 * the one training session anybody gets.
 *
 * ⚠ THE OTP TYPE IS `staff`, NOT `guardian`. OtpService keys codes by
 * (identity, type), so a driver's code and the same number's parent code are
 * separate rows with separate attempt counters. Sharing the type would let a
 * wrong code typed in one app lock the other one out.
 *
 * ⚠ PART P6 — ONE PHONE, MANY ROLES. `school_staff` is UNIQUE(school_id, phone),
 * so an attendant at one school and a driver at another are already two rows
 * keyed by the same number. Those rows ARE the spec's role links, and this
 * endpoint returns one token PER LINK.
 *
 * A token therefore identifies a ROLE, not a person — which is what makes
 * `FleetTripService::assertMayMarkChildren()` trustworthy: there is no field in
 * the request for a client to claim a role with. The Fleet app keeps the token
 * for the link the operator picked and discards the rest.
 *
 * When the spec's `users` + `role_links` tables are built, this endpoint issues
 * one token and the role becomes a claim on it. Until then, one token per link
 * is the shape that cannot be spoofed.
 */
class FleetAuthApiController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** POST /api/fleet/otp */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:24']]);

        if (! PhoneNumber::plausible($data['phone'])) {
            return response()->json([
                'status' => false,
                'message' => 'That does not look like a mobile number. Please check it.',
            ], 422);
        }

        $phone = PhoneNumber::canonical($data['phone']);

        $result = $this->otp->send($phone, 'staff', $request->ip());

        if (! $result['ok']) {
            return response()->json([
                'status' => false,
                'message' => $result['message'],
                'retry_after' => $result['retry_after'] ?? null,
            ], 429);
        }

        // ⚠ ENUMERATION: identical response whether or not the number belongs to
        // a crew member. Telling a stranger which numbers drive a school's buses
        // is a staff-safety leak — those people are alone with children.
        return response()->json(['status' => true, 'message' => $result['message']]);
    }

    /** POST /api/fleet/otp/verify */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:24'],
            'code' => ['required', 'string', 'max:8'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $phone = PhoneNumber::canonical($data['phone']);
        $result = $this->otp->verify($phone, $data['code'], 'staff');

        if (! $result['ok']) {
            return response()->json(array_filter([
                'status' => false,
                'message' => $result['message'],
                'attempts_remaining' => $result['attempts_remaining'] ?? null,
            ], fn ($v) => $v !== null), $result['status'] ?? 400);
        }

        $links = SchoolStaff::roleLinksFor($phone);

        if ($links->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'This number is not registered as bus crew. '
                           . 'Please contact your transport office.',
            ], 403);
        }

        // ⚠ `?? null` is load-bearing — see the note in ParentAuthApiController.
        // `nullable` only means "may be null if present"; validate() omits keys
        // that were never sent, so a client without device_name turned this into
        // a 500 on the endpoint that mints the crew's token.
        $device = ($data['device_name'] ?? null) ?: 'mobile';

        $roles = $links->map(function (SchoolStaff $staff) use ($device) {
            // One token per device name per role, replaced on re-login so a
            // reinstalled app does not leave a live token behind on a phone
            // that has been handed on to somebody else.
            $staff->tokens()->where('name', $device)->delete();

            return [
                'staff_id' => $staff->id,
                'name' => $staff->name,
                'role' => $staff->role,                 // driver | attendant
                'can_mark_children' => $staff->canMarkChildren(),
                'school' => [
                    'id' => $staff->school?->id,
                    'name' => $staff->school?->name,
                    'timezone' => $staff->school?->timezone,
                ],
                'token' => $staff->createToken($device)->plainTextToken,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Signed in.',
            'name' => $links->first()->name,
            'phone' => $links->first()->phone,
            'roles' => $roles,
        ]);
    }

    /** POST /api/fleet/logout — revokes only the calling device's token. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['status' => true, 'message' => 'Signed out.']);
    }

    /**
     * POST /api/fleet/device-token — PART L10 / F1.
     *
     * ⚠ An FCM token belongs to at most ONE crew row. Stripping it from every
     * other row first is what stops a stand-in driver's phone from receiving
     * the regular driver's dispatch pushes after a handover.
     */
    public function deviceToken(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string', 'max:255']]);

        SchoolStaff::where('fcm_token', $data['fcm_token'])
            ->where('id', '!=', $request->user()->id)
            ->update(['fcm_token' => null]);

        $request->user()->forceFill(['fcm_token' => $data['fcm_token']])->save();

        return response()->json(['status' => true, 'message' => 'Device registered.']);
    }
}
