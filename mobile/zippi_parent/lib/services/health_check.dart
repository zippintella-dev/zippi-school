import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config.dart';

/// "Can this phone reach the school server, and is it the school server?"
///
/// ⚠ WHY THIS EXISTS AS ITS OWN THING. Every failure downstream of a wrong
/// server address renders the same way — a spinner, then a sentence about the
/// server not responding. That sentence cannot tell apart:
///
///   * the address is stale (the laptop's DHCP lease moved)
///   * the phone is on mobile data, or a guest Wi-Fi that isolates clients
///   * a VPN is routing the phone off the LAN
///   * the server is genuinely down
///
/// During the pilot each of those happened at least once, and each cost a
/// round-trip to somebody with a laptop to work out which. The person holding
/// the phone is standing next to a bus. They need the answer on the phone.
class HealthCheck {
  final http.Client _client;

  HealthCheck({http.Client? client}) : _client = client ?? http.Client();

  /// Never throws. The whole point is to turn a failure into a sentence.
  Future<HealthResult> run() async {
    final base = Config.apiBase;
    final uri = Uri.parse('$base/api/health');

    try {
      final res = await _client
          .get(uri, headers: {'Accept': 'application/json'})
          .timeout(const Duration(seconds: 6));

      if (res.statusCode != 200) {
        return HealthResult(
          false,
          'Reached $base but it answered ${res.statusCode}. '
          'That address is serving something other than Zippi.',
        );
      }

      final body = jsonDecode(res.body);

      if (body is Map && body['service'] == 'zippi-school') {
        return HealthResult(true, 'Connected to $base');
      }

      // Something answered on the port — a router admin page, a different
      // dev server — but it is not this app's server.
      return HealthResult(
        false,
        'Something answered at $base, but it is not the Zippi school server.',
      );
    } on TimeoutException {
      return HealthResult(
        false,
        'No answer from $base within 6 seconds.\n\n'
        'The address is probably stale, or this phone is on a different '
        'network from the school server. Check the address above.',
      );
    } catch (e) {
      return HealthResult(
        false,
        'Could not reach $base.\n\n'
        'Check that this phone is on the same Wi-Fi as the school server, '
        'that no VPN is switched on, and that the address above is right.',
      );
    }
  }
}

class HealthResult {
  final bool ok;
  final String message;

  const HealthResult(this.ok, this.message);
}
