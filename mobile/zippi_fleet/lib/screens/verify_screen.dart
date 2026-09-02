import 'dart:async';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';
import '../models/duty.dart';
import '../services/demo_data.dart';
import '../services/fleet_api.dart';
import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Screen 1b — OTP.
///
/// ⚠ CODE LENGTH FOLLOWS THE SERVER, NOT THE DRAWING. `OtpService` issues
/// `random_int(1000, 9999)` — four digits, per PART P1. If that ever becomes
/// six, change it there and here, both or neither.
const int kOtpLength = 4;

/// ⚠ THE ERROR STATES ARE THE FEATURE (PART P3/P4/P7). Five wrong tries lock
/// the code for a minute and the screen says how many are left; a screen that
/// silently clears the boxes is how somebody guesses their way into a lockout
/// on a bus that is about to leave.
class VerifyScreen extends StatefulWidget {
  final String phone;

  const VerifyScreen({required this.phone, super.key});

  @override
  State<VerifyScreen> createState() => _VerifyScreenState();
}

class _VerifyScreenState extends State<VerifyScreen> {
  final _code = TextEditingController();
  final _focus = FocusNode();

  String? _error;
  int _attempts = 0;
  bool _locked = false;

  /// PART P4 — a live countdown, not a dead error message.
  int _resendIn = 0;
  Timer? _ticker;

  /// ⚠ DEMO MODE ONLY. Live, the code is minted and hashed by the SERVER and
  /// delivered by SMS; this device never sees it. In a no-backend walkthrough
  /// there is nothing to deliver, so one is minted here and shown — which is
  /// also what the Laravel app does in `local`, where there is no SMS gateway.
  ///
  /// Gated on BOTH demo mode and [kDebugMode]: a release build must never print
  /// a login code on the login screen.
  late String _demoCode = _mint();

  bool _busy = false;

  static String _mint() => (1000 + Random().nextInt(9000)).toString();

  @override
  void initState() {
    super.initState();
    _startCooldown(30);
    WidgetsBinding.instance.addPostFrameCallback((_) => _focus.requestFocus());
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _code.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _startCooldown(int seconds) {
    _ticker?.cancel();
    setState(() => _resendIn = seconds);

    _ticker = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();
      setState(() => _resendIn--);
      if (_resendIn <= 0) t.cancel();
    });
  }

  Future<void> _submit() async {
    if (_locked || _busy) return;

    final code = _code.text.trim();

    if (code.length < kOtpLength) {
      setState(() => _error = 'Enter the $kOtpLength-digit code.');
      return;
    }

    final store = FleetScope.of(context);
    final api = store.api;

    /* ---------------- demo ---------------- */

    if (api == null) {
      if (code != _demoCode) {
        _attempts++;

        setState(() {
          // Keep the digits on screen. Wiping them on a wrong code makes a
          // one-digit typo feel like total failure.
          if (_attempts >= 5) {
            _locked = true;
            _error = 'Too many wrong codes. Wait a minute, then send a new one.';
            _startCooldown(60);
          } else {
            _error = 'That code is wrong · ${5 - _attempts} '
                '${5 - _attempts == 1 ? 'try' : 'tries'} left';
          }
        });

        return;
      }

      store.signIn(name: DemoData.crewName, phoneNumber: widget.phone);
      Navigator.of(context).pop(true);
      return;
    }

    /* ---------------- live ---------------- */

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final json = await api.verifyOtp(widget.phone, code,
          deviceName: _deviceName());

      // ⚠ PART P6 — ONE TOKEN PER ROLE LINK. The server mints a token for each
      // staff row this number owns, which is why a role cannot be spoofed: the
      // token IS the role, and there is no role field in any later request.
      final links = ((json['roles'] as List?) ?? const [])
          .map((r) => CrewAssignment.fromJson(r as Map<String, dynamic>))
          .toList();

      store.signIn(
        name: json['name'] as String? ?? 'Crew',
        phoneNumber: json['phone'] as String? ?? widget.phone,
        // Only meaningful when there is exactly one link; otherwise the role
        // picker chooses, and choosing swaps the token.
        token: links.length == 1 ? links.first.token : null,
        links: links,
      );

      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on SafetyViolation catch (e) {
      // ⚠ The SERVER's sentence, verbatim — it knows how many attempts remain
      // and how long a lockout lasts. Ours would be a guess.
      if (mounted) {
        setState(() {
          _error = e.message;
          _busy = false;
        });
      }
    } on FleetTransportException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _busy = false;
        });
      }
    }
  }

  String _deviceName() => switch (Theme.of(context).platform) {
        TargetPlatform.iOS => 'iPhone',
        TargetPlatform.android => 'Android',
        _ => 'mobile',
      };

  Future<void> _resend() async {
    // ⚠ Issuing a new code consumes the old one (PART P2). Two live codes for
    // one number is two codes an attacker may guess. Server-side that is
    // OtpService; here it is the mint below.
    final api = FleetScope.of(context).api;

    if (api != null) {
      try {
        await api.sendOtp(widget.phone);
      } on Object catch (e) {
        if (mounted) setState(() => _error = '$e');
        return;
      }
    }

    if (!mounted) return;

    setState(() {
      _demoCode = _mint();
      _attempts = 0;
      _locked = false;
      _error = null;
      _code.clear();
    });

    _startCooldown(30);

    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text('We sent a new code.')));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 460),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ScreenHeader('Enter OTP',
                      onBack: () => Navigator.of(context).pop(false)),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text.rich(
                          TextSpan(
                            text: 'We sent a $kOtpLength-digit code by SMS to ',
                            children: [
                              TextSpan(
                                text: widget.phone,
                                style: Z.text(14,
                                    color: Z.ink, weight: FontWeight.w800),
                              ),
                            ],
                          ),
                          style: Z.text(14, color: Z.muted),
                        ),
                        if (kDebugMode && Config.demoMode) ...[
                          const SizedBox(height: 14),
                          ZBanner(
                            'Demo build · your code is $_demoCode',
                            body: 'No server, so the code is minted on this '
                                'phone. A release build never shows this, and '
                                'a live build never knows it.',
                            tone: Tone.warn,
                          ),
                        ],
                        const SizedBox(height: 22),
                        _boxes(),
                        if (_error != null) ...[
                          const SizedBox(height: 12),
                          Text(_error!,
                              textAlign: TextAlign.center,
                              style: Z.text(14,
                                  color: Z.coralText, weight: FontWeight.w700)),
                        ],
                        const SizedBox(height: 22),
                        FilledButton(
                          onPressed: _locked || _busy ? null : _submit,
                          child: _busy
                              ? const SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2, color: Z.onTurquoise),
                                )
                              : const Text('Verify & continue'),
                        ),
                        const SizedBox(height: 16),
                        Center(
                          child: _resendIn > 0
                              ? Text.rich(
                                  TextSpan(
                                    text: "Didn't get it? ",
                                    children: [
                                      TextSpan(
                                        text: 'Resend in 0:'
                                            '${_resendIn.toString().padLeft(2, '0')}',
                                        style: Z
                                            .text(14,
                                                color: Z.faint,
                                                weight: FontWeight.w700)
                                            .copyWith(fontFeatures: const [
                                          FontFeature.tabularFigures()
                                        ]),
                                      ),
                                    ],
                                  ),
                                  style: Z.text(14, color: Z.muted),
                                )
                              : TextButton(
                                  onPressed: _resend,
                                  child: Text('Send a new code',
                                      style: Z.text(14,
                                          color: Z.teal,
                                          weight: FontWeight.w800)),
                                ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// One hidden field drives four drawn boxes so the OS SMS autofill still
  /// works — splitting into four real fields breaks `oneTimeCode`, which is the
  /// difference between a 3-second sign-in and someone switching apps to copy a
  /// code by hand while a bus waits.
  Widget _boxes() {
    return Stack(
      alignment: Alignment.center,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(kOtpLength, (i) {
            final filled = i < _code.text.length;
            final active = i == _code.text.length && _focus.hasFocus && !_locked;

            return Padding(
              padding: EdgeInsets.only(right: i == kOtpLength - 1 ? 0 : 10),
              child: Container(
                width: 56,
                height: 60,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: Z.surface,
                  borderRadius: BorderRadius.circular(Z.rOtp),
                  border: Border.all(
                    color: _locked
                        ? Z.redBorder
                        : (active ? Z.turquoise : Z.cardBorder),
                    width: active ? 2 : 1.5,
                  ),
                ),
                child: filled
                    ? Text(_code.text[i], style: Z.number(24))
                    : active
                        ? Container(width: 2, height: 24, color: Z.turquoise)
                        : null,
              ),
            );
          }),
        ),
        Positioned.fill(
          child: Opacity(
            opacity: 0,
            child: TextField(
              controller: _code,
              focusNode: _focus,
              enabled: !_locked,
              keyboardType: TextInputType.number,
              autofillHints: const [AutofillHints.oneTimeCode],
              maxLength: kOtpLength,
              showCursor: false,
              enableInteractiveSelection: false,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(counterText: ''),
              onChanged: (v) {
                setState(() {});
                if (v.length == kOtpLength) _submit();
              },
            ),
          ),
        ),
      ],
    );
  }
}
