import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'config.dart';
import 'screens/duty_screen.dart';
import 'screens/login_screen.dart';
import 'screens/role_screen.dart';
import 'services/auth_store.dart';
import 'services/fleet_api.dart';
import 'services/fleet_scope.dart';
import 'services/fleet_store.dart';
import 'services/server_store.dart';
import 'theme.dart';
import 'widgets/common.dart';

/// Zippi Fleet — Layer 3 of the school transport platform.
///
/// The vehicle app. Two people use it on two devices during the same trip: the
/// attendant, who marks children and verifies who collects them, and the
/// driver, who drives. The trip is shared state and either device may start it,
/// but only one may.
///
/// ⚠ WHERE THE RULES LIVE. Today they live in `services/fleet_store.dart`,
/// because there is no fleet API for them to live behind — see `config.dart`.
/// When there is one, the server becomes the authority and the store becomes an
/// optimistic mirror that warns the crew early. What must never happen is the
/// rules moving *into the widgets*: a rule spread across fifteen screens is a
/// rule with fifteen chances to be forgotten on the sixteenth.
void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait only. Every screen is a single column read one-handed while
  // standing in a moving vehicle; landscape gains nothing and costs the child
  // rows their height, which is the app's main defence against marking the
  // wrong child.
  SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ])
      // ⚠ The saved server address must be applied BEFORE the first request is
      // built, or the app spends its first screen talking to the compiled-in
      // default and reports a timeout the crew cannot explain.
      .then((_) => ServerStore().load())
      .then((_) => runApp(const ZippiFleetApp()));
}

class ZippiFleetApp extends StatefulWidget {
  const ZippiFleetApp({super.key});

  @override
  State<ZippiFleetApp> createState() => _ZippiFleetAppState();
}

class _ZippiFleetAppState extends State<ZippiFleetApp> {
  late final AuthStore _auth = AuthStore();

  /// ⚠ NULL IN DEMO MODE, AND THAT SINGLE NULL IS THE WHOLE SWITCH. With an
  /// api the store writes to `/api/fleet` and the server decides; without one
  /// it applies its own rules to in-memory data and nothing leaves the phone.
  late final FleetApi? _api = Config.demoMode ? null : FleetApi();

  late final FleetStore _store = FleetStore(auth: _auth, api: _api);

  bool _restored = false;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  /// A crew member does not sign in again every morning. The session is
  /// restored from secure storage; the role and bus are re-picked, because
  /// yesterday's assignment is not evidence about today's.
  ///
  /// ⚠ A FAILED *OR SLOW* READ FALLS THROUGH TO THE LOGIN SCREEN, never to the
  /// splash. EncryptedSharedPreferences on a cheap Android handset does fail —
  /// a corrupted keystore after an OS update is the usual way — and it can also
  /// simply not answer, which a bare `await` would wait on forever.
  ///
  /// An app sitting on a spinner at 6:40 AM is a bus that does not leave.
  /// Signing in again costs thirty seconds; a dead splash costs the morning
  /// run. Hence both the catch and the timeout.
  static const _restoreBudget = Duration(seconds: 3);

  Future<void> _restore() async {
    String? phone;
    String? name;
    String? token;

    try {
      phone = await _auth.phone().timeout(_restoreBudget);
      name = await _auth.crewName().timeout(_restoreBudget);
      token = await _auth.token().timeout(_restoreBudget);
    } on Exception catch (e) {
      debugPrint('Fleet: could not read the saved session — $e');
    }

    if (!mounted) return;

    // ⚠ A phone WITHOUT a token is not a signed-in crew member in live mode.
    // Restoring the name alone would land them on the duty board with no
    // bearer token, where every call 401s and nothing explains why.
    final usable = phone != null && (Config.demoMode || token != null);

    if (usable) {
      _store.signIn(
        name: name ?? 'Crew',
        phoneNumber: phone,
        token: token,
      );
    }

    setState(() => _restored = true);
  }

  @override
  void dispose() {
    _store.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FleetScope(
      store: _store,
      child: MaterialApp(
        title: 'Zippi Fleet',
        debugShowCheckedModeBanner: false,
        theme: Z.theme(),
        home: Builder(
          builder: (context) {
            // Reading through the scope is what subscribes this subtree to the
            // store, so sign-in and role selection swap the tree without any
            // route juggling.
            final store = FleetScope.of(context);

            if (!_restored) return const _Splash();

            if (store.crewName == null) return const LoginScreen();

            if (store.assignment == null) return const RoleScreen();

            return const DutyScreen();
          },
        ),
      ),
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
            FleetMark(),
            SizedBox(height: 18),
            CircularProgressIndicator(strokeWidth: 2, color: Z.turquoise),
          ],
        ),
      ),
    );
  }
}
