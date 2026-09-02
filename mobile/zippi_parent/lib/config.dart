/// Configuration.
///
/// Set the default API base at build time:
///
///   flutter run --dart-define=ZIPPI_API_BASE=http://172.16.4.31:8000
///
/// The fallback is the Android emulator's alias for the host machine's
/// localhost. A real handset on your Wi-Fi cannot use 10.0.2.2 and needs the
/// machine's LAN address — either via the define above, or typed into the
/// server field on the login screen.
class Config {
  /// The address baked in at build time — the DEFAULT, not the last word.
  ///
  /// ⚠ It used to be the only way to point the app anywhere. During the pilot
  /// the school server runs on a laptop whose DHCP lease moves, and every move
  /// broke every installed handset until somebody rebuilt and reinstalled the
  /// APK. `String.fromEnvironment` is a COMPILE-TIME constant, so there was no
  /// way to correct it on the device. A parent cannot rebuild an APK.
  static const String compiledApiBase = String.fromEnvironment(
    'ZIPPI_API_BASE',
    defaultValue: 'http://10.0.2.2:8000',
  );

  /// Set from secure storage at startup by [ServerStore], and from the server
  /// field on the login screen. Null means "use whatever was compiled in".
  static String? _override;

  static String get apiBase => _override ?? compiledApiBase;

  static bool get isOverridden => _override != null;

  static String get apiRoot => '$apiBase/api/parent';

  /// Normalises what a person types: a bare host becomes http://host, trailing
  /// slashes are dropped, and blank clears the override rather than storing an
  /// empty string that would silently break every request.
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

  /// PART F6 — poll while foregrounded, and fetch immediately on resume.
  /// Push is best-effort and the app must never depend on it.
  static const Duration pollInterval = Duration(seconds: 10);

  /// Fail fast. A parent at a kerb needs an answer or an honest error, not a
  /// spinner that hangs for a minute.
  static const Duration requestTimeout = Duration(seconds: 12);
}
