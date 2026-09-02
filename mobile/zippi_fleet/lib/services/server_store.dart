import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../config.dart';

/// Where the app is pointed.
///
/// The school server runs on a laptop during the pilot, and its LAN address
/// moves whenever the DHCP lease does. Baking that address into the APK meant
/// every move cost two rebuilds and two reinstalls, so the address is now
/// something a person can correct on the handset in ten seconds.
///
/// ⚠ This is a destination, not a credential — but it shares the same store as
/// the token because both apps already depend on it and adding a second storage
/// plugin for one string is not worth the build surface. Nothing here is
/// secret; nothing here should ever hold anything that is.
///
/// ⚠ Changing the address does NOT change what the holder may do. The token is
/// minted by, and checked against, whichever server it came from — pointing the
/// app somewhere else simply means the old token is not recognised there.
class ServerStore {
  static const _kBase = 'zippi.server.base';

  final FlutterSecureStorage _storage;

  ServerStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  /// Reads the saved address and applies it to [Config]. Call once at startup,
  /// before the first request is built.
  ///
  /// ⚠ Never allowed to stop the app booting. A keystore that does not answer
  /// (a wiped device, a restored backup, a locked profile) leaves the compiled
  /// default in place — a bus crew gets the login screen and a server field
  /// they can fix, not a dead app.
  Future<void> load() async {
    try {
      Config.applyOverride(await _storage.read(key: _kBase));
    } catch (_) {
      Config.applyOverride(null);
    }
  }

  /// Persists and applies. Blank clears the override and returns to the
  /// compiled-in default.
  Future<void> save(String? raw) async {
    final value = Config.normaliseBase(raw);
    Config.applyOverride(value);

    try {
      if (value == null) {
        await _storage.delete(key: _kBase);
      } else {
        await _storage.write(key: _kBase, value: value);
      }
    } catch (_) {
      // Applied in memory for this session even if it could not be written.
      // Losing it on restart is better than refusing the correction now.
    }
  }
}
