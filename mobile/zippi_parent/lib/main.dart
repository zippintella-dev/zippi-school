import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'screens/home_shell.dart';
import 'screens/login_screen.dart';
import 'services/api_client.dart';
import 'services/auth_store.dart';
import 'services/server_store.dart';
import 'theme.dart';
import 'widgets/common.dart';

/// Zippi Parent — Layer 1 of the school transport platform.
///
/// The app is deliberately thin. Every rule that matters — who may see which
/// child, when a bus position may leave the server (enterprise L29), when a
/// handover code exists (PART A7), whether a date is a school day (PART C3) —
/// is decided server-side. The app renders what it is given and never
/// re-derives a safety rule locally.
void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait only. Every screen here is a single column read one-handed at a
  // kerb; landscape gains nothing and costs the handover code its size, which
  // is the one thing that must stay legible through a bus window.
  SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ])
      // ⚠ The saved server address must be applied BEFORE the first request is
      // built, or the app spends its first screen talking to the compiled-in
      // default and reports a timeout the parent cannot explain.
      .then((_) => ServerStore().load())
      .then((_) => runApp(const ZippiParentApp()));
}

class ZippiParentApp extends StatefulWidget {
  const ZippiParentApp({super.key});

  @override
  State<ZippiParentApp> createState() => _ZippiParentAppState();
}

class _ZippiParentAppState extends State<ZippiParentApp> {
  late final AuthStore _auth = AuthStore();
  late final ApiClient _api = ApiClient(_auth);

  bool? _signedIn;

  @override
  void initState() {
    super.initState();
    _auth.isSignedIn().then((v) {
      if (mounted) setState(() => _signedIn = v);
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Zippi Parent',
      debugShowCheckedModeBanner: false,
      theme: Z.theme(),
      home: switch (_signedIn) {
        null => const _Splash(),
        true => HomeShell(
            api: _api,
            auth: _auth,
            onSignedOut: () => setState(() => _signedIn = false),
          ),
        // ⚠ LoginScreen calls onSignedIn itself once VerifyScreen pops `true`.
        //
        // This used to wrap LoginScreen in a NavigatorPopHandler, which does
        // NOT observe the result of a route pushed onto the ROOT navigator —
        // so a successful sign-in popped straight back to the login screen and
        // sat there. The token was minted, the parent saw the login form again,
        // and only reached the dashboard on the next app launch. Caught on a
        // real device: the server log showed a token issued at 05:49:10 and a
        // fresh OTP request from the login screen 8 seconds later.
        false => LoginScreen(
            api: _api,
            onSignedIn: () => setState(() => _signedIn = true),
          ),
      },
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ZippiMark(),
            SizedBox(height: 18),
            CircularProgressIndicator(strokeWidth: 2, color: Z.turquoise),
          ],
        ),
      ),
    );
  }
}
