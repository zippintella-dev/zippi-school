<?php

namespace App\Http\Controllers\Parent;

use App\Models\Guardian;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * PART P1–P4 / H4 — Zippi Parent login: phone → OTP → Family Dashboard.
 *
 * ⚠ Deliberately extends the framework Controller, NOT App\Http\Controllers\
 * \Controller. That base class resolves activeSchool() from the signed-in
 * `users` row and injects ops sidebar badges — none of which exist for a parent,
 * and reaching for them would couple the family app to the ops app.
 *
 * ⚠ ENUMERATION: an unknown phone gets the same "we sent a code" response as a
 * known one. Telling a stranger which numbers are parents at a given school is
 * a child-safety leak, not just an information leak.
 */
class ParentAuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function show()
    {
        return Auth::guard('guardian')->check()
            ? redirect()->route('parent.dashboard')
            : view('parent.login');
    }

    /** PART P4 — send a code. Throttled per phone number. */
    public function sendCode(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:24'],
        ]);

        // Same guard as the API, on the RAW input — an over-long number is a
        // typo, and "we sent you a code" for a number that cannot exist leaves
        // a parent waiting for an SMS that will never arrive.
        if (! PhoneNumber::plausible($data['phone'])) {
            return back()->withErrors([
                'phone' => 'That does not look like a mobile number. Please check it.',
            ])->withInput();
        }

        $phone = $this->normalise($data['phone']);

        $result = $this->otp->send($phone, 'guardian', $request->ip());

        if (! $result['ok']) {
            return back()->withErrors(['phone' => $result['message']])->withInput();
        }

        // Same response whether or not the number is known — see the class note.
        $request->session()->put('parent_otp_phone', $phone);

        return redirect()->route('parent.verify.form');
    }

    public function verifyForm(Request $request)
    {
        if (! $request->session()->get('parent_otp_phone')) {
            return redirect()->route('parent.login');
        }

        return view('parent.verify', [
            'phone' => $request->session()->get('parent_otp_phone'),
        ]);
    }

    /** PART P3 / P7 — verify. Wrong codes say how many tries remain. */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'],
        ]);

        $phone = $request->session()->get('parent_otp_phone');

        if (! $phone) {
            return redirect()->route('parent.login');
        }

        $result = $this->otp->verify($phone, $data['code'], 'guardian');

        if (! $result['ok']) {
            return back()->withErrors(['code' => $result['message']]);
        }

        // The code was correct. Only NOW does an unknown number diverge — and
        // it diverges into a dead end, not into "no such parent".
        $guardian = PhoneNumber::findGuardian($phone);

        if (! $guardian || $guardian->status !== 'active' || ! $guardian->app_access) {
            $request->session()->forget('parent_otp_phone');

            return redirect()->route('parent.login')->withErrors([
                'phone' => 'This number is not registered for the parent app. '
                         . 'Please contact your school office.',
            ]);
        }

        Auth::guard('guardian')->login($guardian, true);

        $guardian->forceFill(['last_login_at' => now()])->save();

        $request->session()->regenerate();
        $request->session()->forget('parent_otp_phone');

        return redirect()->route('parent.dashboard');
    }

    public function resend(Request $request)
    {
        $phone = $request->session()->get('parent_otp_phone');

        if (! $phone) {
            return redirect()->route('parent.login');
        }

        $result = $this->otp->send($phone, 'guardian', $request->ip());

        return $result['ok']
            ? back()->with('ok', 'We sent a new code.')
            : back()->withErrors(['code' => $result['message']]);
    }

    public function logout(Request $request)
    {
        Auth::guard('guardian')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('parent.login');
    }

    /**
     * ⚠ PART P2 — do NOT "normalise" by writing `'91' . ltrim($phone, '91')`.
     * ltrim treats '91' as a character SET: "+919812345678" loses its leading
     * 9s and 1s and becomes something the SMS gateway accepts and never
     * delivers. Strip formatting only, and leave the digits alone.
     */
    private function normalise(string $phone): string
    {
        return PhoneNumber::canonical($phone);
    }

}
