import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../models/trip.dart';
import '../services/fleet_scope.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/offline_banner.dart';
import '../widgets/sos_fab.dart';
import 'arrival_screen.dart';
import 'at_stop_screen.dart';
import 'drop_stop_screen.dart';
import 'head_count_screen.dart';
import 'sweep_screen.dart';

/// Screen 5 — the home screen of a running trip.
///
/// ⚠ THE SEQUENCE IS THE ROUTE. Stops render in authored order and are never
/// re-ordered, never sorted by distance, never collapsed. A NEXT pill marks the
/// first unvisited stop; everything above it is history and everything below is
/// not yet the crew's problem.
class StopListScreen extends StatelessWidget {
  const StopListScreen({super.key});

  Future<void> _navigateTo(TripStop stop) async {
    // ⚠ HANDS OFF TO GOOGLE MAPS RATHER THAN NAVIGATING IN-APP. A driver
    // already trusts one navigation app; a second one competing for their
    // attention at a junction is a worse outcome than leaving the app.
    final uri = Uri.parse(
      'https://www.google.com/maps/dir/?api=1'
      '&destination=${stop.latitude},${stop.longitude}'
      '&travelmode=driving',
    );

    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;
    final morning = session.trip.direction.isMorning;
    final next = session.nextStop;

    return Scaffold(
      body: SafeArea(
        child: Stack(
          children: [
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (store.offline) OfflineBanner(queued: store.queuedEvents),
                ScreenHeader(
                  '${session.trip.routeCode} · '
                  '${morning ? 'Morning' : 'Afternoon'}',
                  subtitle: [
                    if (store.busLabel.isNotEmpty) 'Bus ${store.busLabel}',
                    'started ${fmtTime(session.startedAt)}',
                  ].join(' · '),
                  onBack: () => Navigator.of(context).pop(),
                  trailing:
                      const StatusPill('Live', tone: Tone.live, dot: true),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 6, 16, 6),
                  child: CountHeader(
                      count: session.onBoard,
                      total: session.trip.childCount),
                ),
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 6, 16, 100),
                    children: [
                      for (final stop in session.stops) ...[
                        _StopRow(
                          stop: stop,
                          isNext: stop == next,
                          onNavigate: () => _navigateTo(stop),
                        ),
                        const SizedBox(height: 10),
                      ],
                      if (session.allStopsDone) ...[
                        const SizedBox(height: 6),
                        FilledButton(
                          onPressed: () => Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (_) => morning
                                  ? const HeadCountScreen()
                                  : const SweepScreen(),
                            ),
                          ),
                          child: Text(morning
                              ? 'Head-count check →'
                              : 'Sweep the bus →'),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
            // ⚠ Always visible, above the list, on every trip screen.
            const Positioned(right: 16, bottom: 20, child: SosFab()),
          ],
        ),
      ),
    );
  }
}

class _StopRow extends StatelessWidget {
  final TripStop stop;
  final bool isNext;
  final VoidCallback onNavigate;

  const _StopRow({
    required this.stop,
    required this.isNext,
    required this.onNavigate,
  });

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;
    final morning = session.trip.direction.isMorning;

    final children = session.childrenAt(stop.id);
    final resolved =
        children.where((c) => !c.state.isUnaccounted).length;

    final (status, statusColor) = switch (true) {
      _ when stop.departed => ('Departed ${fmtTime(stop.departedAt)}', Z.green),
      _ when stop.reached => ('Reached ${fmtTime(stop.reachedAt)}', Z.green),
      _ when isNext => ('Bus is heading here', Z.teal),
      _ => ('Not reached', Z.faint),
    };

    return Opacity(
      opacity: stop.departed ? 0.6 : 1,
      child: ZCard(
        borderColor: isNext ? Z.turquoise : Z.cardBorder,
        borderWidth: isNext ? 2 : 1.5,
        padding: const EdgeInsets.fromLTRB(16, 12, 12, 12),
        onTap: () {
          store.reachStop(stop.id);

          // The school stop is not a kerb with a child list. In the morning it
          // is the arrival screen; in the afternoon it is where boarding
          // happened before the bus ever left.
          if (stop.isSchool && morning) {
            Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const ArrivalScreen()),
            );
            return;
          }

          if (stop.isSchool) return;

          Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => morning
                  ? AtStopScreen(stopId: stop.id)
                  : DropStopScreen(stopId: stop.id),
            ),
          );
        },
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: Z.tapSafety),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Row(
                      children: [
                        Flexible(
                            child: Text(stop.name,
                                style: Z.head(17),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis)),
                        if (isNext) ...[
                          const SizedBox(width: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 4),
                            decoration: BoxDecoration(
                              color: Z.turquoise,
                              borderRadius: BorderRadius.circular(Z.rPill),
                            ),
                            child: Text('NEXT',
                                style: Z
                                    .text(11,
                                        color: Z.onTurquoise,
                                        weight: FontWeight.w800)
                                    .copyWith(letterSpacing: 0.6)),
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 2),
                    Text(
                      stop.isSchool
                          ? '${stop.scheduledTime} · '
                              '${morning ? 'All children disembark' : 'Boarding at school'}'
                          : '${stop.scheduledTime} · ${children.length} '
                              '${children.length == 1 ? 'child' : 'children'}'
                              '${stop.reached ? ' · $resolved resolved' : ''}',
                      style: Z.text(13, color: Z.muted),
                    ),
                    const SizedBox(height: 2),
                    Text(status,
                        style: Z.text(13,
                            color: statusColor, weight: FontWeight.w800)),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              IconButton(
                onPressed: onNavigate,
                tooltip: 'Navigate to ${stop.name}',
                icon: const Icon(Icons.navigation_rounded, color: Z.teal),
                style: IconButton.styleFrom(
                  backgroundColor: Z.tealSoft,
                  minimumSize: const Size(Z.tapMin, Z.tapMin),
                  shape: const CircleBorder(),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
