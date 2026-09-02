import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';
import '../services/api_client.dart';
import '../services/health_check.dart';
import '../services/server_store.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'verify_screen.dart';

/// Screen 1 of the canvas — "Login".
///
/// ⚠ An unknown number must not be distinguishable from a known one here.
/// Telling a stranger which numbers are parents at a given school is a
/// child-safety leak, not merely an information leak. The server returns the
/// same response either way; this screen must not add a check of its own.
class LoginScreen extends StatefulWidget {
  final ApiClient api;

  /// Called once VerifyScreen reports a successful sign-in, so the app can swap
  /// the whole tree over to the dashboard. Without this the verify route pops
  /// back to here and the parent is left staring at the login form with a
  /// perfectly good token already in the Keychain.
  final VoidCallback onSignedIn;

  const LoginScreen({required this.api, required this.onSignedIn, super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phone = TextEditingController();
  bool _busy = false;
  String? _error;

  /// ⚠ An Indian mobile is 10 digits, 12 with the country code. Bounding this
  /// matters: without an upper bound a mistyped extra digit is accepted, an OTP
  /// is dispatched to a number that cannot exist, and the app tells the parent
  /// "we sent you a code" while they wait for an SMS that never arrives. Found
  /// exactly that way on a real device — a stray keystroke produced a 14-digit
  /// number and the server duly issued a code for it.
  static const _minDigits = 10;
  static const _maxDigits = 13;

  /// Lets a parent retarget the app when the school server's address moves.
  ///
  /// ⚠ Quiet, but reachable. Quiet because it is not part of signing in and a
  /// parent should never wonder whether they are meant to touch it; reachable
  /// because when it IS wrong, every screen reports a timeout and this is the
  /// only thing that fixes it — and a parent cannot rebuild an APK.

  /// Runs the health check and shows the answer. See HealthCheck for why the
  /// phone has to be able to answer this on its own.
  bool _testing = false;

  Future<void> _testConnection() async {
    setState(() => _testing = true);
    final result = await HealthCheck().run();
    if (!mounted) return;
    setState(() => _testing = false);

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(result.ok ? 'Connected' : 'Cannot reach the server',
            style: Z.head(18)),
        content: Text(result.message, style: Z.text(14)),
        actions: [
          if (!result.ok)
            TextButton(
              onPressed: () { Navigator.of(ctx).pop(); _editServer(); },
              child: const Text('Change address'),
            ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }

  Future<void> _editServer() async {
    final controller = TextEditingController(
      text: Config.isOverridden ? Config.apiBase : '',
    );

    final saved = await showDialog<String?>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('School server', style: Z.head(18)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              "The address of your school's server. Ask the school office if "
              'you are not sure — leave it blank to go back to the built-in '
              'default.',
              style: Z.text(13, color: Z.muted),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: controller,
              autofocus: true,
              keyboardType: TextInputType.url,
              autocorrect: false,
              decoration: InputDecoration(
                hintText: Config.compiledApiBase,
                border: const OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(null),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(controller.text),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (saved == null) return;

    await ServerStore().save(saved);
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final raw = _phone.text.trim();
    final digits = raw.replaceAll(RegExp(r'\D'), '');

    if (digits.length < _minDigits || digits.length > _maxDigits) {
      setState(() => _error = digits.length < _minDigits
          ? 'Please enter your full mobile number.'
          : 'That number has too many digits — please check it.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    // The field holds the local number; the +91 prefix is fixed in the UI.
    final phone = raw.startsWith('+') ? raw : '+91$digits';

    try {
      await widget.api.sendOtp(phone);

      if (!mounted) return;

      final signedIn = await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => VerifyScreen(api: widget.api, phone: phone),
      ));

      if (signedIn == true) widget.onSignedIn();
    } on ApiException catch (e) {
      // PART P7 — render the server's sentence verbatim.
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

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
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 48, 24, 8),
                  child: Column(
                    children: [
                      const ZippiMark(),
                      const SizedBox(height: 14),
                      Text('zippi',
                          style: Z.head(32, color: Z.teal)
                              .copyWith(letterSpacing: 0.5)),
                      const SizedBox(height: 4),
                      Text('Know where the school bus is, always.',
                          textAlign: TextAlign.center,
                          style: Z.text(15, color: Z.muted)),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text('Mobile number',
                          style: Z.text(14,
                              color: Z.ink, weight: FontWeight.w800)),
                      const SizedBox(height: 14),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // Fixed +91 affix, as drawn in the canvas.
                          Container(
                            height: Z.hField,
                            padding: const EdgeInsets.symmetric(horizontal: 16),
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              color: Z.surface,
                              borderRadius: BorderRadius.circular(Z.rField),
                              border: Border.all(color: Z.cardBorder, width: 1.5),
                            ),
                            child: Text('+91',
                                style: Z.text(16,
                                    color: Z.ink, weight: FontWeight.w700)),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: TextField(
                              controller: _phone,
                              keyboardType: TextInputType.phone,
                              autofillHints: const [
                                AutofillHints.telephoneNumberLocal
                              ],
                              inputFormatters: [
                                FilteringTextInputFormatter.allow(
                                    RegExp(r'[0-9 ]')),
                                LengthLimitingTextInputFormatter(13),
                              ],
                              textInputAction: TextInputAction.done,
                              onSubmitted: (_) => _busy ? null : _submit(),
                              style: Z.text(16,
                                  color: Z.ink, weight: FontWeight.w700),
                              decoration: const InputDecoration(
                                hintText: '98765 43210',
                              ),
                            ),
                          ),
                        ],
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 10),
                        Text(_error!,
                            style: Z.text(13,
                                color: Z.coralText, weight: FontWeight.w700)),
                      ],
                      const SizedBox(height: 14),
                      Text(
                        "We'll send a code by SMS. No password to remember.",
                        style: Z.text(13, color: Z.muted),
                      ),
                      const SizedBox(height: 14),
                      FilledButton(
                        onPressed: _busy ? null : _submit,
                        child: _busy
                            ? const SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2, color: Z.onTurquoise),
                              )
                            : const Text('Send OTP'),
                      ),
                      const SizedBox(height: 14),
                      Text.rich(
                        TextSpan(
                          text: 'Your school gives Zippi your number — ',
                          children: [
                            TextSpan(
                              text: 'need help?',
                              style: Z.text(13,
                                  color: Z.teal, weight: FontWeight.w700),
                            ),
                          ],
                        ),
                        textAlign: TextAlign.center,
                        style: Z.text(13, color: Z.muted),
                      ),
                      const SizedBox(height: 18),
                      // The server the app is pointed at. Shown always, not
                      // hidden behind a gesture: when it is wrong every screen
                      // times out, and a parent needs to SEE that it is wrong
                      // before they can fix it.
                      InkWell(
                        onTap: _editServer,
                        borderRadius: BorderRadius.circular(8),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                              vertical: 6, horizontal: 8),
                          child: Text(
                            'Server: ${Config.apiBase}'
                            '${Config.isOverridden ? '' : ' (default)'}  ·  Change',
                            textAlign: TextAlign.center,
                            style: Z.text(12, color: Z.muted),
                          ),
                        ),
                      ),
                      TextButton(
                        onPressed: _testing ? null : _testConnection,
                        child: Text(_testing ? 'Testing…' : 'Test connection',
                            style: Z.text(12, weight: FontWeight.w700)),
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
}
