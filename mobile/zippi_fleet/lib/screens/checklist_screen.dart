import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/common.dart';
import '../widgets/swipe_to_begin.dart';
import 'pm_boarding_screen.dart';
import 'stop_list_screen.dart';

/// Screen 4 — Pre-trip checklist, and the swipe that begins the trip.
///
/// ⚠ BLOCKING BY DESIGN. Nothing proceeds until all four are ticked, and each
/// tick is timestamped into the trip record. A checklist that can be skipped
/// when running late is a checklist that is only ever completed when there was
/// time anyway — which is not when a missing fire extinguisher matters.
class ChecklistScreen extends StatefulWidget {
  const ChecklistScreen({super.key});

  @override
  State<ChecklistScreen> createState() => _ChecklistScreenState();
}

class _ChecklistScreenState extends State<ChecklistScreen> {
  int _variant = 0;

  void _begin(FleetStore store) {
    try {
      store.beginTrip();
    } on SafetyViolation catch (e) {
      showBlocked(context, e);
      return;
    }

    // ⚠ 22 families have just been told the bus has left. Say so, on the
    // screen, so that if it was wrong the crew knows immediately rather than
    // finding out from a parent's phone call.
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Trip started. Families have been notified.'),
      ),
    );
  }

  void _open(FleetStore store) {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(
        builder: (_) => store.session!.trip.direction.isMorning
            ? const StopListScreen()
            : const PmBoardingScreen(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ScreenHeader(
              session.trip.title,
              subtitle:
                  'Before the bus moves · ${session.tickedCount} of '
                  '${session.checklist.length} ticked',
              onBack: () => Navigator.of(context).pop(),
            ),
            if (kDebugMode)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
                child: StateChips(
                  labels: const ['Ready to begin', 'Already running elsewhere'],
                  selected: _variant,
                  onSelect: (i) {
                    setState(() => _variant = i);
                    store.openTrip(session.trip, startedElsewhere: i == 1);
                  },
                ),
              ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
                children: [
                  for (var i = 0; i < session.checklist.length; i++) ...[
                    _CheckRow(
                      index: i,
                      enabled: !session.isRunning && !session.startedElsewhere,
                    ),
                    const SizedBox(height: 12),
                  ],
                  const SizedBox(height: 2),
                  Text('Each tick is timestamped into the trip record.',
                      style: Z.text(13, color: Z.faint)),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
              child: _Footer(onBegin: () => _begin(store), onOpen: () => _open(store)),
            ),
          ],
        ),
      ),
    );
  }
}

class _CheckRow extends StatelessWidget {
  final int index;
  final bool enabled;

  const _CheckRow({required this.index, required this.enabled});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final item = store.session!.checklist[index];

    return ZCard(
      radius: Z.rRow,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      onTap: enabled ? () => store.toggleCheck(index) : null,
      child: ConstrainedBox(
        // 56dp minimum. Everything on this screen is one-handed in a bus.
        constraints: const BoxConstraints(minHeight: Z.tapSafety - 24),
        child: Row(
          children: [
            Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(
                color: item.ticked ? Z.turquoise : Z.surface,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(
                    color: item.ticked ? Z.turquoise : Z.cardBorder, width: 2),
              ),
              child: item.ticked
                  ? const Icon(Icons.check_rounded,
                      size: 20, color: Z.onTurquoise)
                  : null,
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(item.label,
                      style:
                          Z.text(15, color: Z.ink, weight: FontWeight.w800)),
                  const SizedBox(height: 2),
                  Text(
                    item.ticked && item.tickedAt != null
                        ? 'Ticked ${fmtTime(item.tickedAt)} · saved to trip record'
                        : item.hint,
                    style: Z.text(13,
                        color: item.ticked ? Z.green : Z.muted,
                        weight: item.ticked ? FontWeight.w700 : FontWeight.w400),
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

class _Footer extends StatelessWidget {
  final VoidCallback onBegin;
  final VoidCallback onOpen;

  const _Footer({required this.onBegin, required this.onOpen});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;

    // ⚠ ONE TRIP PER BUS MAY BE STARTED AT A TIME. The trip is shared state
    // and either device may start it — but only once. A second start would
    // fork the journey record and send a second "the bus has left".
    if (session.startedElsewhere) {
      return ZBanner(
        'This trip is already running on another device',
        body: '${session.startedElsewhereBy} started it at '
            '${session.trip.startedAt}. Open it to join, or call ops if that '
            'looks wrong.',
        tone: Tone.warn,
        action: FilledButton(
          onPressed: onOpen,
          child: const Text('Open the running trip'),
        ),
      );
    }

    if (session.isRunning) {
      return ZBanner(
        'Trip started · ${fmtTime(session.startedAt)}',
        body: '${session.trip.childCount} families just saw "the bus has left".',
        tone: Tone.good,
        icon: Icons.check_rounded,
        action: FilledButton(
          onPressed: onOpen,
          child: const Text('Open the trip'),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          session.allChecksTicked
              ? 'Swipe all the way across — release early and it springs back'
              : 'Finish the checklist to unlock Begin trip',
          textAlign: TextAlign.center,
          style: Z.text(13,
              color: session.allChecksTicked ? Z.teal : Z.faint,
              weight: FontWeight.w800),
        ),
        const SizedBox(height: 10),
        SwipeToBegin(
          enabled: session.allChecksTicked,
          onConfirmed: onBegin,
        ),
      ],
    );
  }
}
