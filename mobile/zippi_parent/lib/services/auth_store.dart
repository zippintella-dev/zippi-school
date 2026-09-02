import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the bearer token lives.
///
/// ⚠ Keychain (iOS) / EncryptedSharedPreferences (Android), never plain
/// SharedPreferences. This token grants access to a child's live location and
/// today's handover code; on a rooted or jailbroken device plain preferences
/// are world-readable.
class AuthStore {
  static const _kToken = 'zippi_token';
  static const _kName = 'zippi_guardian_name';
  static const _kChild = 'zippi_selected_child';

  final FlutterSecureStorage _storage;

  AuthStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock,
              ),
            );

  /// Cached in memory so the 10-second poll doesn't hit the Keychain each tick.
  String? _cached;
  bool _loaded = false;

  Future<String?> token() async {
    if (_loaded) return _cached;

    _cached = await _storage.read(key: _kToken);
    _loaded = true;

    return _cached;
  }

  Future<bool> isSignedIn() async => (await token()) != null;

  Future<void> saveToken(String token) async {
    _cached = token;
    _loaded = true;
    await _storage.write(key: _kToken, value: token);
  }

  Future<String?> guardianName() => _storage.read(key: _kName);

  Future<void> saveGuardianName(String name) =>
      _storage.write(key: _kName, value: name);

  /* ---------------- selected child ---------------- */

  /// Which of this guardian's children the app is currently focused on.
  ///
  /// ⚠ A UI CONVENIENCE, NOT A SECURITY BOUNDARY. The boundary is the server's
  /// guardian→children link: `ParentApiController::myChild()` resolves every
  /// request through it and 404s anything else. This value only decides which
  /// of the parent's OWN children is on screen, so a tampered value can at
  /// worst show them a different child of their own.
  ///
  /// Never treat it as authorization, and never send it as one.
  Future<int?> selectedChildId() async {
    final raw = await _storage.read(key: _kChild);
    return raw == null ? null : int.tryParse(raw);
  }

  Future<void> selectChild(int childId) =>
      _storage.write(key: _kChild, value: '$childId');

  Future<void> clearSelectedChild() => _storage.delete(key: _kChild);

  Future<void> clear() async {
    _cached = null;
    _loaded = true;
    await _storage.delete(key: _kToken);
    await _storage.delete(key: _kName);
    await _storage.delete(key: _kChild);
  }
}
