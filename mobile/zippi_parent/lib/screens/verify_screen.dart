import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Screen 2 of the canvas — "OTP".
///
/// ⚠ CODE LENGTH: the canvas draws SIX boxes. The server issues FOUR digits —
/// `OtpService` uses `random_int(1000, 9999)`, which PART P1 specifies
/// literally. Six boxes against a four-digit code would make login impossible,
/// so the box count follows the SERVER, not the drawing.
///
/// Changing to six is a two-line change if that is the decision: this constant,
/// and `random_int(100000, 999999)` in `app/Services/OtpService.php`. Change
/// both or neither.
const int kOtpLength = 4;

/// ⚠ THE ERROR STATES ARE THE FEATURE HERE (PART P3/P4/P7). The server says how
/// many attempts remain and how long a throttle lasts; both are rendered
/// verbatim. An app that clears the boxes and shows nothing on a wrong code is
/// how a parent ends up guessing until they are locked out.
class VerifyScreen extends StatefulWidget {
  final ApiClient api;
  final String phone;

  const VerifyScreen({required this.api, required this.phone, super.key});

  @override
  State<VerifyScreen> createState() => _VerifyScreenState();
}

class _VerifyScreenState extends State<VerifyScreen> {
  final _code = TextEditingController();
  final _focus = FocusNode();

  bool _busy = false;
  String? _error;

  /// PART P4 — surfaced as a live countdown, not a dead error message.
  int _resendIn = 0;
  Timer? _ticker;

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
    final code = _code.text.trim();

    if (code.length < kOtpLength) {
      setState(() => _error = 'Enter the $kOtpLength-digit code.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await widget.api.verifyOtp(widget.phone, code, _deviceName());

      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;

      // Keep the digits on screen. Wiping them on a wrong code makes a
      // one-digit typo feel like total failure.
      setState(() => _error = e.message);

      if (e.retryAfter != null) _startCooldown(e.retryAfter!);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    setState(() => _error = null);

    try {
      await widget.api.sendOtp(widget.phone);
      _startCooldown(30);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('We sent a new code.')),
        );
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
      if (e.retryAfter != null) _startCooldown(e.retryAfter!);
    }
  }

  String _deviceName() => switch (Theme.of(context).platform) {
        TargetPlatform.iOS => 'iPhone',
        TargetPlatform.android => 'Android',
        _ => 'mobile',
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                ScreenHeader(
                  'Enter OTP',
                  onBack: () => Navigator.of(context).pop(false),
                ),
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
                            const TextSpan(text: ' · '),
                            TextSpan(
                              text: 'change',
                              style: Z.text(14,
                                  color: Z.teal, weight: FontWeight.w700),
                            ),
                          ],
                        ),
                        style: Z.text(14, color: Z.muted),
                      ),
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
                        onPressed: _busy ? null : _submit,
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
                                      style: Z.text(14,
                                          color: Z.faint,
                                          weight: FontWeight.w700),
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
    );
  }

  /// The boxed digits. A single hidden field drives them so the OS SMS autofill
  /// still works — splitting into N real fields breaks `oneTimeCode`, which is
  /// the difference between a 3-second login and a parent switching apps to
  /// copy the code by hand.
  Widget _boxes() {
    return Stack(
      alignment: Alignment.center,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(kOtpLength, (i) {
            final filled = i < _code.text.length;
            final active = i == _code.text.length && _focus.hasFocus;

            return Padding(
              padding: EdgeInsets.only(right: i == kOtpLength - 1 ? 0 : 9),
              child: Container(
                width: 48,
                height: 56,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: Z.surface,
                  borderRadius: BorderRadius.circular(Z.rOtp),
                  border: Border.all(
                    color: active ? Z.turquoise : Z.cardBorder,
                    width: active ? 2 : 1.5,
                  ),
                ),
                child: filled
                    ? Text(
                        _code.text[i],
                        style: Z.text(22,
                                color: Z.ink, weight: FontWeight.w800)
                            .copyWith(
                          fontFeatures: const [FontFeature.tabularFigures()],
                        ),
                      )
                    : active
                        ? Container(width: 2, height: 22, color: Z.turquoise)
                        : null,
              ),
            );
          }),
        ),
        // Invisible but real: holds focus, the caret and the autofill contract.
        Positioned.fill(
          child: Opacity(
            opacity: 0,
            child: TextField(
              controller: _code,
              focusNode: _focus,
              keyboardType: TextInputType.number,
              autofillHints: const [AutofillHints.oneTimeCode],
              maxLength: kOtpLength,
              showCursor: false,
              enableInteractiveSelection: false,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(counterText: ''),
              onChanged: (v) {
                setState(() {});
                if (v.length == kOtpLength && !_busy) _submit();
              },
            ),
          ),
        ),
      ],
    );
  }
}
