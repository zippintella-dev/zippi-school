import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../config.dart';
import 'fleet_store.dart' show SafetyViolation;

/// A transport-level failure: no network, a timeout, a 5xx.
///
/// ⚠ DELIBERATELY NOT A [SafetyViolation]. A refusal from the server means "the
/// rules say no"; this means "I could not ask". Collapsing the two would let a
/// dead zone read as permission denied — or, far worse, let a screen treat a
/// timeout as a completed handover.
class FleetTransportException implements Exception {
  final String message;
  final bool isOffline;

  const FleetTransportException(this.message, {this.isOffline = false});

  @override
  String toString() => message;
}

/// The Laravel `/api/fleet` client.
///
/// ⚠ ONE ERROR CONTRACT (PART P7). Every 4xx from the fleet API carries a
/// `message` written to be read by an attendant at a kerb, and this client
/// turns it into a [SafetyViolation] carrying that message VERBATIM. The app
/// never composes its own sentence for a server refusal: the server knows
/// which child is still on board, and the phone does not.
///
/// ⚠ AND EVERY MUTATION RETURNS THE WHOLE TRIP. The endpoints answer with the
/// full trip payload rather than a delta, so the app's state is replaced from
/// the server after every write instead of being patched locally and hoped
/// about. That is what makes the server the authority in practice and not just
/// in the comments — a phone that has been offline, or is running a build from
/// March, converges on the next successful call.
class FleetApi {
  final http.Client _client;

  /// The bearer token for ONE role link. The token identifies a role, not a
  /// person — see FleetAuthApiController. Swapping roles swaps the token.
  String? token;

  FleetApi({http.Client? client, this.token}) : _client = client ?? http.Client();

  Uri _uri(String path, [Map<String, String>? query]) =>
      Uri.parse('${Config.apiRoot}$path').replace(queryParameters: query);

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  /* ---------------- auth ---------------- */

  Future<void> sendOtp(String phone) =>
      _post('/otp', {'phone': phone}, authed: false);

  /// Returns the crew member and their role links, each with its own token.
  Future<Map<String, dynamic>> verifyOtp(
    String phone,
    String code, {
    String deviceName = 'mobile',
  }) =>
      _post('/otp/verify', {
        'phone': phone,
        'code': code,
        'device_name': deviceName,
      }, authed: false);

  Future<void> logout() => _post('/logout', const {});

  Future<Map<String, dynamic>> me() => _get('/me');

  /* ---------------- read ---------------- */

  Future<Map<String, dynamic>> duties({String? date}) =>
      _get('/duties', date == null ? null : {'date': date});

  Future<Map<String, dynamic>> trip(String tripId) => _get('/trips/$tripId');

  /* ---------------- write ---------------- */

  Future<Map<String, dynamic>> checklist(String tripId, List<Map<String, String>> items) =>
      _post('/trips/$tripId/checklist', {'items': items});

  Future<Map<String, dynamic>> start(String tripId, {double? lat, double? lng}) =>
      _post('/trips/$tripId/start', _pos(lat, lng));

  Future<Map<String, dynamic>> reachStop(String tripId, String stopId,
          {double? lat, double? lng}) =>
      _post('/trips/$tripId/stops/$stopId/reach', _pos(lat, lng));

  Future<Map<String, dynamic>> departStop(String tripId, String stopId) =>
      _post('/trips/$tripId/stops/$stopId/depart', const {});

  /// ⚠ Like [handover], the boarding code is POSTed and compared against a hash
  /// server-side. It is never held on this device and never comes back in a GET.
  Future<Map<String, dynamic>> board(
    String tripId,
    String childId, {
    String? method,
    String? code,
    double? lat,
    double? lng,
    DateTime? clientReportedAt,
    bool offline = false,
  }) =>
      _post('/trips/$tripId/children/$childId/board', {
        if (method != null) 'method': method,
        if (code != null) 'code': code,
        ..._pos(lat, lng),
        // ⚠ Sent, never trusted for ordering (PART L4). The server stores it in
        // its own column and orders by server time, so a handset with a wrong
        // clock cannot reorder a custody record.
        if (clientReportedAt != null)
          'client_reported_at': clientReportedAt.toIso8601String(),
        'offline': offline,
      });

  Future<Map<String, dynamic>> undoBoard(String tripId, String childId) =>
      _send('DELETE', '/trips/$tripId/children/$childId/board', const {});

  Future<Map<String, dynamic>> notAtStop(String tripId, String childId) =>
      _post('/trips/$tripId/children/$childId/not-at-stop', const {});

  Future<Map<String, dynamic>> headCount(String tripId, int counted) =>
      _post('/trips/$tripId/head-count', {'counted': counted});

  Future<Map<String, dynamic>> disembark(String tripId) =>
      _post('/trips/$tripId/disembark', const {});

  Future<Map<String, dynamic>> lockIn(String tripId) =>
      _post('/trips/$tripId/lock-in', const {});

  /// ⚠ The code is POSTed and compared against a hash server-side. It is never
  /// held on this device and never appears in a GET payload.
  Future<Map<String, dynamic>> handover(
    String tripId,
    String childId, {
    required String method,
    String? code,
    int? authorizedReceiverId,
    double? lat,
    double? lng,
    bool offline = false,
  }) =>
      _post('/trips/$tripId/children/$childId/handover', {
        'method': method,
        if (code != null) 'code': code,
        if (authorizedReceiverId != null)
          'authorized_receiver_id': authorizedReceiverId,
        ..._pos(lat, lng),
        'offline': offline,
      });

  Future<Map<String, dynamic>> escalate(String tripId, String childId) =>
      _post('/trips/$tripId/children/$childId/escalate', const {});

  Future<Map<String, dynamic>> returnToSchool(String tripId, String childId) =>
      _post('/trips/$tripId/children/$childId/return-to-school', const {});

  Future<Map<String, dynamic>> complete(String tripId) =>
      _post('/trips/$tripId/complete', const {});

  Future<Map<String, dynamic>> sos(
    String tripId, {
    required String type,
    bool drill = false,
    double? lat,
    double? lng,
  }) =>
      _post('/trips/$tripId/sos', {
        'type': type,
        'drill': drill,
        ..._pos(lat, lng),
      });

  /// ⚠ THE LIVE MAP IN THE PARENT APP IS THIS CALL. It writes the bus position
  /// of record; the server decides per family whether it may be shown.
  Future<void> ping(String tripId,
          {required double lat, required double lng, int? speedKmph}) =>
      _post('/trips/$tripId/ping', {
        'latitude': lat,
        'longitude': lng,
        if (speedKmph != null) 'speed_kmph': speedKmph,
      });

  /// Invariant #3 — multipart, because the sweep carries a photograph.
  Future<Map<String, dynamic>> sweep(
    String tripId, {
    required File photo,
    double? lat,
    double? lng,
  }) async {
    final request = http.MultipartRequest('POST', _uri('/trips/$tripId/sweep'))
      ..headers.addAll({
        'Accept': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      })
      ..files.add(await http.MultipartFile.fromPath('photo', photo.path));

    if (lat != null) request.fields['latitude'] = '$lat';
    if (lng != null) request.fields['longitude'] = '$lng';

    try {
      final streamed = await request.send().timeout(
            // Longer than the rest: this one is uploading a photograph over a
            // patchy mobile connection.
            const Duration(seconds: 45),
          );

      return _decode(await http.Response.fromStream(streamed));
    } on SocketException {
      throw const FleetTransportException(
        'No connection. The sweep will be sent when you are back in signal.',
        isOffline: true,
      );
    }
  }

  /* ---------------- plumbing ---------------- */

  Map<String, dynamic> _pos(double? lat, double? lng) => {
        if (lat != null) 'latitude': lat,
        if (lng != null) 'longitude': lng,
      };

  Future<Map<String, dynamic>> _get(String path, [Map<String, String>? query]) =>
      _send('GET', path, null, query: query);

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body,
          {bool authed = true}) =>
      _send('POST', path, body, authed: authed);

  Future<Map<String, dynamic>> _send(
    String method,
    String path,
    Map<String, dynamic>? body, {
    Map<String, String>? query,
    bool authed = true,
  }) async {
    if (authed && token == null) {
      throw const FleetTransportException('Not signed in.');
    }

    final request = http.Request(method, _uri(path, query))
      ..headers.addAll(_headers);

    if (body != null) request.body = jsonEncode(body);

    try {
      final streamed = await _client
          .send(request)
          .timeout(Config.requestTimeout);

      return _decode(await http.Response.fromStream(streamed));
    } on SocketException {
      throw const FleetTransportException(
        'No connection. Carry on — this will sync when you are back in signal.',
        isOffline: true,
      );
    } on http.ClientException {
      throw const FleetTransportException(
        'The connection dropped. Try again.',
        isOffline: true,
      );
    }
  }

  Map<String, dynamic> _decode(http.Response response) {
    Map<String, dynamic> json;

    try {
      json = jsonDecode(response.body) as Map<String, dynamic>;
    } on FormatException {
      // A proxy login page, a 502 from a load balancer, an HTML error page.
      throw FleetTransportException(
        'The server sent something unexpected (${response.statusCode}).',
      );
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return json;
    }

    final message = (json['message'] as String?) ??
        'Something went wrong (${response.statusCode}).';

    // ⚠ 5xx IS NOT A REFUSAL. A crashed server has not decided that a child may
    // not be released — it has failed to answer. Presenting that as a safety
    // refusal would teach the crew that refusals are noise.
    if (response.statusCode >= 500) {
      throw FleetTransportException(message);
    }

    // ⚠ 4xx IS the refusal, and its sentence is the product. 423 is the SOS
    // lock, 409 is "already running elsewhere", 422 is an invariant.
    throw SafetyViolation(
      message,
      childIds: (json['child_ids'] as List?)?.map((e) => '$e').toList() ??
          const [],
    );
  }

  void close() => _client.close();
}
