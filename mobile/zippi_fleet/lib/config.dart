/// Build-time configuration.
///
///   flutter run --dart-define=ZIPPI_API_BASE=http://192.168.0.246:8000
///
/// The default is the Android emulator's alias for the host machine's
/// localhost. A real handset on your Wi-Fi cannot use 10.0.2.2 and must be
/// given the machine's LAN address via --dart-define.
class Config {
  /// The address baked in at build time. This is only the DEFAULT now.
  ///
  /// ⚠ It used to be the whole story, and that was the wrong shape for a school
  /// running the server on a laptop. A DHCP lease moves and every handset stops
  /// working until somebody rebuilds and reinstalls two APKs — which happened
  /// three times in two days during the pilot. `String.fromEnvironment` is a
  /// COMPILE-TIME constant, so there was no way to correct it on the device.
  static const String compiledApiBase = String.fromEnvironment(
    'ZIPPI_API_BASE',
    defaultValue: 'http://10.0.2.2:8000',
  );

  /// Set from secure storage at startup by [ServerStore], and from the server
  /// field on the login screen. Null means "use whatever was compiled in".
  static String? _override;

  static String get apiBase => _override ?? compiledApiBase;

  static bool get isOverridden => _override != null;

  static String get apiRoot => '$apiBase/api/fleet';

  /// Normalises what a person types at a kerb: a bare host becomes http://host,
  /// a trailing slash is dropped, and blank clears the override rather than
  /// storing an empty string that would silently break every request.
  static String? normaliseBase(String? raw) {
    var v = (raw ?? '').trim();
    if (v.isEmpty) return null;
    if (!v.startsWith('http://') && !v.startsWith('https://')) v = 'http://$v';
    while (v.endsWith('/')) {
      v = v.substring(0, v.length - 1);
    }
    return v;
  }

  static void applyOverride(String? raw) => _override = normaliseBase(raw);

  /// ⚠ DEMO MODE IS NOW OPT-IN. The fleet API exists.
  ///
  /// `routes/api.php` carries `/api/fleet`, and by default this app talks to
  /// it: every boarding, handover, sweep and SOS is a real write to the same
  /// rows the dashboard's live board and the Zippi Parent app read. That is the
  /// whole point of the link — there is no sync step and no second copy of a
  /// child's state.
  ///
  ///   flutter run --dart-define=ZIPPI_DEMO=true
  ///
  /// turns the server off and runs the in-memory walkthrough from
  /// `services/demo_data.dart` instead. Useful for showing the fifteen screens
  /// on a laptop with no backend; useless for anything a real bus does, because
  /// nothing it records leaves the handset.
  ///
  /// ⚠ In demo mode the app is inventing its own answers, and the safety rules
  /// in FleetStore are the only ones being applied. Never demonstrate a
  /// compliance behaviour from a demo build.
  static const bool demoMode = bool.fromEnvironment(
    'ZIPPI_DEMO',
    defaultValue: false,
  );

  /// Fail fast. A crew at a kerb needs an answer or an honest error, not a
  /// spinner that hangs for a minute.
  static const Duration requestTimeout = Duration(seconds: 12);

  /* ---------------- safety rules with a number in them ---------------- */
  //
  // These mirror `config/school.php` and the four invariants. They are
  // duplicated here only so the UI can draw a countdown; the SERVER decides
  // whether an action is allowed. Never let a local constant be the only thing
  // standing between a child and a stranger.

  /// ⚠ TEST MODE — waiting times only, never a safety rule.
  ///
  ///   flutter run --dart-define=ZIPPI_TEST_MODE=true
  ///
  /// A walkthrough at a desk spends most of its time watching countdowns that
  /// exist for a bus at a kerb. This shortens THE WAITS and nothing else.
  ///
  /// ⚠ It does not remove a single check. The handover code is still demanded
  /// before a child is released, the head count still has to be entered, the
  /// sweep still has to be performed and photographed, and a trip still cannot
  /// complete with a child unaccounted for. Those live on the server anyway —
  /// `FleetTripService` re-decides every rule from the database on every write,
  /// so a tampered client changes nothing about what is allowed.
  ///
  /// Defaults to false, so a release build is the real thing unless somebody
  /// deliberately passes the define.
  static const bool testMode = bool.fromEnvironment(
    'ZIPPI_TEST_MODE',
    defaultValue: false,
  );

  /// PART F2 — how long the bus waits at a morning stop before "Not at stop"
  /// unlocks for a child who has not appeared.
  static Duration get stopWait =>
      testMode ? const Duration(seconds: 5) : const Duration(seconds: 120);

  /// A mis-tap window. Long enough to notice the wrong row went green, short
  /// enough that it cannot be used to un-board a child who has walked away.
  static Duration get boardUndo =>
      testMode ? const Duration(seconds: 30) : const Duration(seconds: 90);

  /// Invariant #1 — how long the bus waits at a drop stop before the escalation
  /// ladder expires and *Return to school* becomes the only action.
  static Duration get escalationWindow =>
      testMode ? const Duration(seconds: 15) : const Duration(minutes: 3);

  /// Guardian call-out rungs inside that window.
  static Duration get escalationCall1 =>
      testMode ? const Duration(seconds: 5) : const Duration(seconds: 90);
  static Duration get escalationCall2 =>
      testMode ? const Duration(seconds: 10) : const Duration(seconds: 150);

  /// The school-bus speed limit the driver screen reddens above.
  static const int speedLimitKmh = 40;

  /// SOS is a hold, never a tap.
  static const Duration sosHold = Duration(seconds: 2);

  /// Wrong handover codes before the keypad locks (PART A7 / P3).
  static const int handoverAttempts = 5;

  /// Wrong MORNING BOARDING codes before that keypad locks.
  ///
  /// ⚠ Locking here leads somewhere different from the afternoon. A locked
  /// handover keypad sends the attendant to another verification route and, if
  /// none works, back to school with the child safely aboard. A locked boarding
  /// keypad must send them to the photo roster and let the child ON — the bus is
  /// about to leave and the child is on the pavement. Same number, opposite
  /// consequence for being wrong.
  static const int boardingAttempts = 5;

  /// Geo-fence radius for "you are at the stop".
  static const int stopGeofenceMetres = 150;
}
