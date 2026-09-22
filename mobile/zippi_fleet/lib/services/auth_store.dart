import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/duty.dart';

/// Where the session lives between launches.
///
/// ⚠ Keychain (iOS) / EncryptedSharedPreferences (Android), never plain
/// SharedPreferences. When the fleet API exists the value here will be a token
/// that grants the right to mark a child boarded and to read 22 children's
/// names, classes and photographs. On a rooted handset — and a school bus phone
/// is exactly the handset that gets rooted — plain preferences are
/// world-readable.
///
/// ⚠ The crew must not be signed out mid-route. A bus with patchy signal and a
/// token that expired at a dead zone is an attendant who cannot mark anyone,
/// so the session is deliberately long-lived and cleared only on explicit sign
/// out or an ops revocation.
class AuthStore {
  static const _kPhone = 'zippi_fleet_phone';
  static const _kName = 'zippi_fleet_name';
  static const _kRole = 'zippi_fleet_role';
  static const _kToken = 'zippi_fleet_token';
  static const _kStaffId = 'zippi_fleet_staff_id';
  static const _kLinks = 'zippi_fleet_links';

  final FlutterSecureStorage _storage;

  AuthStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock,
              ),
            );

  String? _cachedPhone;
  bool _loaded = false;

  Future<String?> phone() async {
    if (_loaded) return _cachedPhone;

    _cachedPhone = await _storage.read(key: _kPhone);
    _loaded = true;

    return _cachedPhone;
  }

  Future<bool> isSignedIn() async => (await phone()) != null;

  Future<void> save({
    required String phone,
    required String name,
    String? token,
  }) async {
    _cachedPhone = phone;
    _loaded = true;

    await _storage.write(key: _kPhone, value: phone);
    await _storage.write(key: _kName, value: name);

    if (token != null) await _storage.write(key: _kToken, value: token);
  }

  /// The bearer token for the role the crew member last picked.
  ///
  /// ⚠ Keychain / EncryptedSharedPreferences, never plain preferences. This
  /// token grants the right to mark a child boarded and to read 22 children's
  /// names, classes and photographs — and a school bus phone is exactly the
  /// handset that gets rooted.
  Future<String?> token() => _storage.read(key: _kToken);

  /// Remembers the chosen role link so the crew do not re-pick every morning.
  ///
  /// ⚠ A CONVENIENCE, NOT AN AUTHORIZATION. The role split is enforced by the
  /// SERVER against the staff row the token belongs to; a tampered value here
  /// can at worst pre-select the wrong card on a picker the crew then correct.
  Future<void> saveRole(CrewAssignment assignment) async {
    await _storage.write(key: _kRole, value: assignment.role.name);

    if (assignment.staffId != null) {
      await _storage.write(key: _kStaffId, value: '${assignment.staffId}');
    }

    if (assignment.token != null) {
      await _storage.write(key: _kToken, value: assignment.token);
    }
  }

  Future<int?> staffId() async {
    final raw = await _storage.read(key: _kStaffId);
    return raw == null ? null : int.tryParse(raw);
  }

  /// Every role link this phone signed in for, each with its own token.
  ///
  /// ⚠ WITHOUT THESE, A RESTORED SESSION KNOWS NOTHING ABOUT ITSELF. The app
  /// used to restore a name and a token and no links at all, and then filled
  /// the gap from the walkthrough fixtures — so a crew member relaunching the
  /// app was offered a driver card for a route at a school that does not
  /// exist, holding an attendant's token. Store them, and there is nothing to
  /// invent.
  Future<void> saveLinks(List<CrewAssignment> links) async {
    if (links.isEmpty) {
      await _storage.delete(key: _kLinks);
      return;
    }

    await _storage.write(
      key: _kLinks,
      value: jsonEncode(links.map((l) => l.toJson()).toList()),
    );
  }

  /// ⚠ Returns empty on ANY failure — a corrupted keystore after an OS update
  /// is the usual way, and a crew member signing in again costs thirty seconds.
  /// What must never happen is a fabricated link standing in for a real one.
  Future<List<CrewAssignment>> links() async {
    try {
      final raw = await _storage.read(key: _kLinks);
      if (raw == null || raw.isEmpty) return const [];

      return (jsonDecode(raw) as List)
          .map((e) => CrewAssignment.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (e) {
      debugPrint('Fleet: could not read the saved role links — $e');
      return const [];
    }
  }

  Future<String?> crewName() => _storage.read(key: _kName);

  Future<String?> lastRole() => _storage.read(key: _kRole);

  Future<void> clear() async {
    _cachedPhone = null;
    _loaded = true;

    await _storage.delete(key: _kPhone);
    await _storage.delete(key: _kName);
    await _storage.delete(key: _kRole);
    await _storage.delete(key: _kToken);
    await _storage.delete(key: _kStaffId);
    await _storage.delete(key: _kLinks);
  }
}
