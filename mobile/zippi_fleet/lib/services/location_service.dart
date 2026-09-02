import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

/// Where the bus is.
///
/// ⚠ THE DEVICE IS THE TRACKER, and three separate safety behaviours read from
/// it:
///
///   · the **Zippi Parent live map** — `/ping` writes `buses.latitude` and the
///     server decides, per family, whether it may be shown (enterprise L29);
///   · the **stop geo-fence** — "you are within 150 m of the stop";
///   · **Invariant #3** — the sweep refuses unless the bus is at the school
///     gate.
///
/// ⚠ SO A POSITION IS NEVER SUBSTITUTED. If the fix is unavailable this returns
/// null and the caller sends nothing; the server then refuses the sweep, which
/// is the correct outcome. Filling in a stop's published coordinates instead —
/// which is the obvious shortcut — would make every geo-fence pass everywhere
/// and quietly delete the check.
class LocationService {
  Position? _last;

  /// The most recent fix, or null if there has never been one.
  Position? get last => _last;

  /// True once the OS has granted a while-in-use permission.
  bool granted = false;

  /// Asks for permission, once.
  ///
  /// ⚠ Asked at the START of a trip, not at the sweep. A crew member who first
  /// meets the permission dialog while standing at the school gate with a bus
  /// full of children is a crew member who taps "deny" to make it go away.
  Future<bool> ensurePermission() async {
    // ⚠ NEVER THROWS. This is called on the path that starts a trip, and a
    // handset with location services off, a device without a GPS chip, or a
    // test with no platform binding must all degrade to "no position" rather
    // than stopping a bus from starting its run. The safety consequence of no
    // position — the sweep being refused at the gate — is handled where it
    // belongs, by the server.
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        granted = false;
        return false;
      }

      var permission = await Geolocator.checkPermission();

      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      granted = permission == LocationPermission.always ||
          permission == LocationPermission.whileInUse;

      return granted;
    } on Object catch (e) {
      debugPrint('Fleet: location unavailable — $e');
      granted = false;
      return false;
    }
  }

  /// A fresh fix, or null.
  ///
  /// ⚠ Bounded. A GPS read that hangs must not hold up an attendant confirming
  /// a sweep, so it gives up and returns null — and null means "send nothing",
  /// which the server treats as "cannot verify you are at the gate".
  Future<Position?> current({
    Duration timeout = const Duration(seconds: 8),
  }) async {
    if (!granted && !await ensurePermission()) return null;

    try {
      _last = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          distanceFilter: 0,
        ),
      ).timeout(timeout);

      return _last;
    } on Object catch (e) {
      debugPrint('Fleet: no position — $e');
      return null;
    }
  }

  /// A stream of fixes while a trip is running.
  ///
  /// 25 m is a compromise: fine enough that a parent's map moves as the bus
  /// turns into their road, coarse enough not to flatten the battery on a
  /// mid-range Android over a two-hour route.
  Stream<Position> watch() => Geolocator.getPositionStream(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          distanceFilter: 25,
        ),
      ).map((p) {
        _last = p;
        return p;
      });
}
