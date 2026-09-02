import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../models/trip.dart';
import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/common.dart';
import '../widgets/offline_banner.dart';
import '../widgets/second_ticker.dart';
import '../widgets/sos_fab.dart';
import 'stop_list_screen.dart';

/// Screen 9 — Boarding at school. Afternoon. The mirror of screen 6.
///
/// ⚠ GROUPED BY CLASS, NOT BY STOP, because that is the order children come
/// out of the building. A list sorted by stop would have the attendant
/// scanning twenty-two rows for each child who appears, which is exactly the
/// hunting that produces a wrong-row tap.
class PmBoardingScreen extends StatelessWidget {
  const PmBoardingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;

    // ⚠ YOUNGEST CLASS FIRST, and the order never changes from one afternoon
    // to the next. Nursery and the lower grades are dismissed first and are the
    // children least able to find their own bus, so they are at the top of the
    // screen where the attendant's thumb already is. Roster order would put
    // UKG somewhere past the fold.
    final groups = <String, List<TripChild>>{};
    for (final c in session.children) {
      groups.putIfAbsent(c.className, () => []).add(c);
    }

    final ordered = groups.keys.toList()
      ..sort((a, b) => _classRank(a).compareTo(_classRank(b)));

    final unresolved =
        session.children.where((c) => c.state == ChildState.expected).length;

    return Scaffold(
      body: SafeArea(
        child: SecondTicker(
          builder: (context) => Stack(
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (store.offline) OfflineBanner(queued: store.queuedEvents),
                  ScreenHeader(
                    'Afternoon boarding',
                    subtitle: 'Children come out class by class',
                    onBack: () => Navigator.of(context).pop(),
                    trailing: _DepartureCountdown(),
                  ),
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(16, 6, 16, 110),
                      children: [
                        for (final className in ordered) ...[
                          Padding(
                            padding: const EdgeInsets.fromLTRB(6, 10, 6, 6),
                            child: Text(className.toUpperCase(),
                                style: Z
                                    .text(13,
                                        color: Z.muted,
                                        weight: FontWeight.w800)
                                    .copyWith(letterSpacing: 0.6)),
                          ),
                          for (final child in groups[className]!) ...[
                            _PmRow(child: child),
                            const SizedBox(height: 8),
                          ],
                        ],
                      ],
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (session.lockedInAt != null) ...[
                          ZBanner(
                            'Locked in · ${fmtTime(session.lockedInAt)}',
                            body: 'Un-boarded children are marked absent and '
                                'the school office has been notified.',
                            tone: Tone.good,
                            icon: Icons.check_rounded,
                          ),
                          const SizedBox(height: 10),
                          FilledButton(
                            onPressed: () =>
                                Navigator.of(context).pushReplacement(
                              MaterialPageRoute(
                                  builder: (_) => const StopListScreen()),
                            ),
                            child: const Text('Open the stop list →'),
                          ),
                        ] else ...[
                          FilledButton(
                            onPressed: store.canLockIn
                                ? () => _lockIn(context, store, unresolved)
                                : null,
                            child: Text(
                              unresolved > 0
                                  ? 'Lock in & depart — $unresolved will be '
                                      'marked absent'
                                  : 'Lock in & depart',
                              textAlign: TextAlign.center,
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'Unlocks when every child is boarded or absent, or '
                            'at departure time. An expected child who did not '
                            'board is the school\'s problem to resolve before '
                            'the bus leaves.',
                            textAlign: TextAlign.center,
                            style:
                                Z.text(12, color: Z.faint).copyWith(height: 1.5),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
              const Positioned(right: 16, bottom: 96, child: SosFab()),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _lockIn(
      BuildContext context, FleetStore store, int unresolved) async {
    if (unresolved > 0) {
      // ⚠ The confirmation names the consequence, not the action. "Lock in?"
      // asks nothing; "3 children will be marked absent and the office told"
      // is a decision.
      final ok = await showModalBottomSheet<bool>(
        context: context,
        backgroundColor: Z.bg,
        showDragHandle: true,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(Z.rCard)),
        ),
        builder: (sheet) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                ZBanner(
                  '$unresolved ${unresolved == 1 ? 'child has' : 'children have'} '
                  'not come out',
                  body: 'They will be marked absent and the school office is '
                      'notified now, before the bus moves.',
                  tone: Tone.warn,
                ),
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: () => Navigator.of(sheet).pop(true),
                  child: const Text('Lock in & depart'),
                ),
                const SizedBox(height: 8),
                OutlinedButton(
                  onPressed: () => Navigator.of(sheet).pop(false),
                  child: const Text('Keep waiting'),
                ),
              ],
            ),
          ),
        ),
      );

      if (ok != true || !context.mounted) return;
    }

    var marked = 0;

    final done = await runGuarded(context, () async {
      marked = await store.lockInAndDepart();
    });

    if (!done || !context.mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(marked == 0
            ? 'Roster locked. Every child is accounted for.'
            // ⚠ Says the office was told. That notification IS the action.
            : 'Roster locked. $marked marked absent, school office notified.'),
      ),
    );
  }
}

/// Sorts "Nursery / LKG / UKG / Class 1A / Class 10B" the way a school does.
///
/// The pre-primary years come first and carry no number; everything else sorts
/// by grade, then section. Anything unrecognised falls to the end rather than
/// silently landing in the middle of the numbered grades.
int _classRank(String className) {
  const preSchool = ['nursery', 'lkg', 'ukg'];

  final lower = className.toLowerCase().trim();
  final pre = preSchool.indexOf(lower);
  if (pre >= 0) return pre;

  final match = RegExp(r'(\d+)\s*([A-Za-z]?)').firstMatch(className);
  if (match == null) return 9999;

  final grade = int.parse(match.group(1)!);
  final section = match.group(2) ?? '';

  // +10 keeps every numbered grade after the pre-primary block.
  return (grade + 10) * 100 +
      (section.isEmpty ? 0 : section.toUpperCase().codeUnitAt(0));
}

class _DepartureCountdown extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final left = store.departureRemaining();
    final reached = left == Duration.zero;
    final soon = left.inSeconds <= 120;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Text(
          reached ? 'Departure time reached' : 'Departs in ${fmtCountdown(left)}',
          style: Z
              .text(13,
                  color: soon ? Z.coralText : Z.teal, weight: FontWeight.w800)
              .copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
        ),
        Text('scheduled ${store.session!.trip.scheduledStart}',
            style: Z.text(12, color: Z.faint)),
      ],
    );
  }
}

class _PmRow extends StatelessWidget {
  final TripChild child;

  const _PmRow({required this.child});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final locked = store.session!.lockedInAt != null;

    final (bg, border, label, labelColor) = switch (child.state) {
      ChildState.boarded => (Z.greenBg, Z.green, '✓ BOARDED', Z.green),
      ChildState.expected => (Z.surface, Z.cardBorder, '☐ NOT BOARDED', Z.coralText),
      // ⚠ Blue, not grey. "Parent collecting" is a child who is fine and
      // accounted for; greying it in with the absences invites an attendant to
      // wait for someone who is already in a car.
      ChildState.parentCollecting =>
        (Z.blueBg, Z.blueBorder, 'PARENT COLLECTING', Z.blue),
      _ => (Z.surface, Z.cardBorder, child.state.label.toUpperCase(), Z.faint),
    };

    final dimmed = child.state == ChildState.absent;
    final tappable = !locked &&
        (child.state == ChildState.expected ||
            child.state == ChildState.boarded);

    return Opacity(
      opacity: dimmed ? 0.6 : 1,
      child: Material(
        color: bg,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: tappable
              ? () {
                  HapticFeedback.selectionClick();
                  runGuarded(context, () => store.togglePmBoarded(child.id));
                }
              : null,
          child: Container(
            constraints: const BoxConstraints(minHeight: Z.tapSafety),
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              border: Border.all(
                color: border,
                width: child.state == ChildState.boarded ? 2 : 1.5,
              ),
            ),
            child: Row(
              children: [
                ChildAvatar(child, size: 44, dimmed: dimmed),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(child.name, style: Z.head(16)),
                      if (child.note != null)
                        Text(child.note!,
                            style: Z.text(12, color: Z.muted),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Text(label,
                    style: Z
                        .text(12, color: labelColor, weight: FontWeight.w800)
                        .copyWith(letterSpacing: 0.4)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
