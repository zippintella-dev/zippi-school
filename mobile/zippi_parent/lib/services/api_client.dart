import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config.dart';
import '../models/family_card.dart';
import '../models/journey_day.dart';
import 'auth_store.dart';

/// Thrown for any non-2xx. `message` is the server's own sentence.
///
/// ⚠ PART P7 — the app renders `message` VERBATIM. An app that clears the input
/// and shows nothing on a non-2xx is a defect, not a cosmetic issue: it is how
/// a parent ends up guessing at a locked code. Never replace these with a
/// generic "Something went wrong".
class ApiException implements Exception {
  final int statusCode;
  final String message;
  final int? attemptsRemaining;
  final int? retryAfter;

  ApiException(
    this.statusCode,
    this.message, {
    this.attemptsRemaining,
    this.retryAfter,
  });

  /// 401 means the token is dead — the caller should sign out, not retry.
  bool get isUnauthenticated => statusCode == 401;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient(this._auth, {http.Client? client})
      : _http = client ?? http.Client();

  final AuthStore _auth;
  final http.Client _http;

  /* ---------------- auth (PART P) ---------------- */

  Future<void> sendOtp(String phone) async {
    await _post('/otp', {'phone': phone}, authed: false);
  }

  /// Returns the guardian's display name and stores the bearer token.
  Future<String> verifyOtp(String phone, String code, String deviceName) async {
    final body = await _post(
      '/otp/verify',
      {'phone': phone, 'code': code, 'device_name': deviceName},
      authed: false,
    );

    await _auth.saveToken(body['token'] as String);

    final guardian = body['guardian'] as Map<String, dynamic>?;
    final name = guardian?['name'] as String? ?? '';
    await _auth.saveGuardianName(name);

    return name;
  }

  Future<void> logout() async {
    try {
      await _post('/logout', const {});
    } catch (_) {
      // A dead or already-revoked token still means "signed out" locally.
      // Never trap someone in the app because the network refused.
    }
    await _auth.clear();
  }

  /// PART L10 — the server strips this token from every other guardian first.
  Future<void> registerDeviceToken(String fcmToken) async {
    await _post('/device-token', {'fcm_token': fcmToken});
  }

  /* ---------------- data ---------------- */

  Future<List<FamilyCard>> familyDashboard() async {
    final body = await _get('/family-dashboard');

    return (body['children'] as List)
        .map((e) => FamilyCard.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<FamilyCard> child(int childId) async {
    final body = await _get('/child/$childId');

    return FamilyCard.fromJson(body['child'] as Map<String, dynamic>);
  }

  Future<List<JourneyDay>> journey(int childId) async {
    final body = await _get('/child/$childId/journey');

    return (body['days'] as List)
        .map((e) => JourneyDay.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  /// PART A1/A2. `direction` is Morning | Afternoon | Both.
  Future<String> markAbsent(
    int childId, {
    required String serviceDate,
    required String direction,
    String? reasonCode,
  }) async {
    final body = await _post('/child/$childId/absence', {
      'service_date': serviceDate,
      'direction': direction,
      if (reasonCode != null && reasonCode.isNotEmpty) 'reason_code': reasonCode,
    });

    return body['message'] as String? ?? 'Marked absent.';
  }

  Future<String> undoAbsence(int childId, {required String serviceDate}) async {
    final body = await _send(
      'DELETE',
      '/child/$childId/absence',
      {'service_date': serviceDate},
    );

    return body['message'] as String? ?? 'Absence removed.';
  }

  /* ---------------- plumbing ---------------- */

  Future<Map<String, dynamic>> _get(String path) => _send('GET', path, null);

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body, {
    bool authed = true,
  }) =>
      _send('POST', path, body, authed: authed);

  Future<Map<String, dynamic>> _send(
    String method,
    String path,
    Map<String, dynamic>? body, {
    bool authed = true,
  }) async {
    final uri = Uri.parse('${Config.apiRoot}$path');

    final headers = <String, String>{
      'Accept': 'application/json',
      if (body != null) 'Content-Type': 'application/json',
    };

    if (authed) {
      final token = await _auth.token();
      if (token != null) headers['Authorization'] = 'Bearer $token';
    }

    final request = http.Request(method, uri)..headers.addAll(headers);
    if (body != null) request.body = jsonEncode(body);

    late http.Response response;

    try {
      final streamed = await _http.send(request).timeout(Config.requestTimeout);
      response = await http.Response.fromStream(streamed);
    } on TimeoutException {
      throw ApiException(0,
          'The school server is taking too long to respond. Please try again.');
    } catch (_) {
      throw ApiException(
          0, 'No connection. Check your internet and try again.');
    }

    Map<String, dynamic> decoded;

    try {
      decoded = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      // An HTML error page (a 500, or a captive-portal splash) lands here.
      throw ApiException(
          response.statusCode, 'Something went wrong at the school server.');
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }

    throw ApiException(
      response.statusCode,
      decoded['message'] as String? ?? 'Request failed.',
      attemptsRemaining: decoded['attempts_remaining'] as int?,
      retryAfter: decoded['retry_after'] as int?,
    );
  }
}
