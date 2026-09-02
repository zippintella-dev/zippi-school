import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../models/trip.dart';
import '../services/fleet_scope.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/offline_banner.dart';
import '../widgets/route_map.dart';
import '../widgets/sos_fab.dart';

/// Screen 14 — the driver's only trip screen.
///
/// ⚠ NO CHILD-MARKING CONTROLS ANYWHERE, AND THAT IS THE WHOLE POINT OF THE
/// ROLE SPLIT. A driver marking children is a driver looking at a phone while
/// children board around a vehicle. What they get instead is where they are,
/// where they are going, how many are aboard, how fast they are going, and the
/// SOS.
///
/// The head count is read-only here for a reason too: it is the number a
/// driver needs to know before pulling away, and none of it is editable.
class DriverScreen extends StatefulWidget {
  const DriverScreen({super.key});

  @override
  State<DriverScreen> createState() => _DriverScreenState();
}

class _DriverScreenState extends State<DriverScreen> {
  /// ⚠ SIMULATED IN DEMO MODE, AND LABELLED AS SUCH ON SCREEN.
  ///
  /// Production reads this from the platform location stream, which is also
  /// where the position on the map and the geo-fences come from. A speedometer
  /// that looks real and is not is worse than no speedometer, so this one says
  /// what it is.
  double _speed = 34;
  Timer? _drift;
  StreamSubscription<Object>? _fixes;

  /// True until the device has produced a fix, so the readout can say so
  /// rather than showing a number it invented.
  bool _awaitingFix = true;

  @override
  void initState() {
    super.initState();

    if (Config.demoMode) {
      final rng = Random();
      _awaitingFix = false;
      _drift = Timer.periodic(const Duration(milliseconds: 900), (_) {
        if (!mounted) return;
        setState(() => _speed =
            (_speed + (rng.nextDouble() - 0.42) * 5).clamp(24, 47));
      });
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    if (Config.demoMode || _fixes != null) return;

    // ⚠ The real speed, off the device. The store separately pushes these same
    // fixes to the server, where anything over the school's limit becomes an
    // exception row on the Control Tower (PART R3) — an overspeed pattern is
    // something ops act on, not a popup a driver dismisses at 50 km/h.
    //
    // `read`, not `of`: taking a dependency here would restart the stream on
    // every notify.
    final store = FleetScope.read(context);

    _fixes = store.location.watch().listen((p) {
      if (!mounted) return;
      setState(() {
        _speed = (p.speed * 3.6).clamp(0, 200);
        _awaitingFix = false;
      });
    });
  }

  @override
  void dispose() {
    _drift?.cancel();
    _fixes?.cancel();
    super.dispose();
  }

  Future<void> _openMaps(TripStop? stop) async {
    if (stop == null) return;

    final uri = Uri.parse(
      'https://www.google.com/maps/dir/?api=1'
      '&destination=${stop.latitude},${stop.longitude}&travelmode=driving',
    );

    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;
    final next = session.nextStop;
    final over = !_awaitingFix && _speed > Config.speedLimitKmh;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (store.offline) OfflineBanner(queued: store.queuedEvents),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 12, 16, 8),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${session.trip.routeCode} · '
                          '${session.trip.direction.isMorning ? 'Morning' : 'Afternoon'}',
                          style: Z.head(19),
                        ),
                        Text(
                          store.busLabel.isEmpty
                              ? 'Driver mode'
                              : 'Driver mode · Bus ${store.busLabel}',
                          style: Z.text(13, color: Z.muted),
                        ),
                      ],
                    ),
                  ),
                  const StatusPill('Live', tone: Tone.live, dot: true),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(Z.rCard),
                    child: SizedBox(
                      height: 330,
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(Z.rCard),
                          border: Border.all(color: Z.cardBorder, width: 1.5),
                        ),
                        child: RouteMap(
                          stops: session.stops,
                          busLatitude: next?.latitude,
                          busLongitude: next?.longitude,
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 10),
                  // The one line a driver reads at a red light. Words, not a
                  // dashboard.
                  Container(
                    padding: const EdgeInsets.fromLTRB(18, 14, 18, 14),
                    decoration: BoxDecoration(
                      color: Z.strip,
                      borderRadius: BorderRadius.circular(Z.rRow),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          next == null
                              ? '${session.onBoard} on board · all stops done'
                              : '${session.onBoard} on board · next: ${next.name}',
                          style: Z.head(18, color: Colors.white),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          next == null
                              ? 'Hand over to the attendant for the sweep.'
                              : 'Due ${next.scheduledTime} · '
                                  '${session.childrenAt(next.id).length} children '
                                  'board there',
                          style: Z.text(13, color: Z.tealSoftBorder),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  IntrinsicHeight(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Expanded(
                          child: _SpeedCard(
                            speed: _speed,
                            over: over,
                            awaitingFix: _awaitingFix,
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            children: [
                              Expanded(
                                child: FilledButton.icon(
                                  onPressed: () => _openMaps(next),
                                  icon: const Icon(Icons.navigation_rounded,
                                      size: 18),
                                  label: const Text('Google Maps'),
                                  style: FilledButton.styleFrom(
                                    backgroundColor: Z.turquoise,
                                    foregroundColor: Z.onTurquoise,
                                    shape: RoundedRectangleBorder(
                                      borderRadius:
                                          BorderRadius.circular(Z.rRow),
                                    ),
                                    minimumSize:
                                        const Size.fromHeight(Z.tapSafety),
                                    textStyle: const TextStyle(
                                        fontFamily: Z.display,
                                        fontSize: 15,
                                        fontWeight: FontWeight.w700),
                                  ),
                                ),
                              ),
                              const SizedBox(height: 10),
                              const Expanded(child: Center(child: SosFab())),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'No child marking on this device. The attendant marks '
                    'children — you drive.',
                    textAlign: TextAlign.center,
                    style: Z.text(12, color: Z.faint).copyWith(height: 1.5),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// ⚠ Colour AND words. "42" in red is a number a driver may not resolve at a
/// glance in sunlight; "Over the 40 km/h school-bus limit — slow down" is an
/// instruction.
class _SpeedCard extends StatelessWidget {
  final double speed;
  final bool over;
  final bool awaitingFix;

  const _SpeedCard({
    required this.speed,
    required this.over,
    this.awaitingFix = false,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      decoration: BoxDecoration(
        color: over ? Z.redBg : Z.surface,
        borderRadius: BorderRadius.circular(Z.rRow),
        border: Border.all(
            color: over ? Z.red : Z.cardBorder, width: over ? 2 : 1.5),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          // ⚠ "—" until there is a fix. A speedometer that shows a number it
          // invented is worse than one that admits it does not know.
          Text(awaitingFix ? '—' : '${speed.round()}',
              style: Z.number(48, color: over ? Z.red : Z.ink, height: 1)),
          Text('km/h',
              style: Z.text(13, color: Z.muted, weight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text(
            awaitingFix
                ? 'Waiting for GPS'
                : over
                    ? 'Over the ${Config.speedLimitKmh} km/h school-bus limit — '
                        'slow down'
                    : 'Under the ${Config.speedLimitKmh} km/h limit',
            style: Z.text(12,
                color: over
                    ? Z.red
                    : awaitingFix
                        ? Z.muted
                        : Z.green,
                weight: FontWeight.w800),
          ),
          if (Config.demoMode) ...[
            const SizedBox(height: 4),
            Text('simulated — demo build, no server',
                style: Z.text(11, color: Z.faint)),
          ],
        ],
      ),
    );
  }
}
