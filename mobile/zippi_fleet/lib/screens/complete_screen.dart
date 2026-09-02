import 'package:flutter/material.dart';

import '../models/trip.dart';
import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'at_stop_screen.dart';
import 'drop_stop_screen.dart';

/// Screen 13 — Trip complete, and the refusal.
///
/// ⚠ INVARIANT #2 MADE VISIBLE. A trip cannot complete while a child is
/// unaccounted for. The refusal names them — "Cannot complete: Aarav Mehta is
/// still on board" — and offers the way to them, because "1 child unaccounted"
/// is a number somebody dismisses and a name is a person somebody finds.
class CompleteScreen extends StatefulWidget {
  const CompleteScreen({super.key});

  @override
  State<CompleteScreen> createState() => _CompleteScreenState();
}

class _CompleteScreenState extends State<CompleteScreen> {
  SafetyViolation? _blocked;

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;
    final morning = session.trip.direction.isMorning;
    final done = session.completedAt != null;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ScreenHeader(
              'Complete the trip',
              subtitle: [
                session.trip.routeCode,
                session.trip.direction.label,
                if (store.busLabel.isNotEmpty) 'Bus ${store.busLabel}',
              ].join(' · '),
              onBack: () => Navigator.of(context).pop(),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 6, 16, 20),
                children: [
                  GridView.count(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    crossAxisCount: 2,
                    crossAxisSpacing: 10,
                    mainAxisSpacing: 10,
                    childAspectRatio: 1.7,
                    children: [
                      _Stat('${session.boardedEver}', 'boarded', Z.ink),
                      if (morning)
                        _Stat(
                            '${session.children.where((c) => c.state == ChildState.arrivedAtSchool).length}',
                            'arrived at school',
                            Z.green)
                      else
                        _Stat('${session.handedOverCount}',
                            'handed over, verified', Z.green),
                      _Stat('${session.absentCount}',
                          morning ? 'not travelling' : 'absent, school informed',
                          Z.faint),
                      _Stat('${session.returnedCount}', 'returned to school',
                          Z.amber),
                    ],
                  ),
                  const SizedBox(height: 12),
                  ZCard(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                    child: Column(
                      children: [
                        DetailRow(
                          'Sweep',
                          session.sweptAt == null
                              ? 'Not done'
                              : 'Done ${fmtTime(session.sweptAt)} · photo on record',
                          valueColor:
                              session.sweptAt == null ? Z.red : Z.green,
                        ),
                        const DetailRow('Overrides', 'None', last: true),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (done)
                    ZBanner(
                      'Trip complete',
                      body: 'Every child is accounted for. Good work today.',
                      tone: Tone.good,
                      icon: Icons.check_rounded,
                    )
                  else if (_blocked != null) ...[
                    ZBanner(
                      _blocked!.message,
                      tone: Tone.danger,
                      emphatic: true,
                    ),
                    const SizedBox(height: 12),
                    // ⚠ A route straight to each named child. The refusal
                    // arrives with ids (the server sends ids); resolving them
                    // against the loaded roster is what turns "cannot complete"
                    // into "here is the child, go and find them".
                    for (final id in _blocked!.childIds) ...[
                      if (session.childById(id) case final child?) ...[
                        CoralButton(
                          "Open ${child.firstName}'s stop",
                          onPressed: () => _goToChild(context, store, child),
                        ),
                        const SizedBox(height: 8),
                      ],
                    ],
                    const SizedBox(height: 4),
                    OutlinedButton(
                      onPressed: () => setState(() => _blocked = null),
                      child: const Text('Try again'),
                    ),
                  ] else
                    FilledButton(
                      onPressed: store.busy
                          ? null
                          : () async {
                              try {
                                await store.completeTrip();
                                if (mounted) setState(() => _blocked = null);
                              } on SafetyViolation catch (e) {
                                // ⚠ Rendered INLINE rather than in a sheet: the
                                // refusal is the screen's main content here,
                                // and it carries the buttons that resolve it.
                                if (mounted) setState(() => _blocked = e);
                              }
                            },
                      child: const Text('Complete trip'),
                    ),
                ],
              ),
            ),
            if (done)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: FilledButton(
                  onPressed: () {
                    store.closeTrip();
                    Navigator.of(context)
                        .popUntil((route) => route.isFirst);
                  },
                  child: const Text("Back to today's trips"),
                ),
              ),
          ],
        ),
      ),
    );
  }

  void _goToChild(BuildContext context, FleetStore store, TripChild child) {
    final morning = store.session!.trip.direction.isMorning;

    store.reachStop(child.stopId);

    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => morning
            ? AtStopScreen(stopId: child.stopId)
            : DropStopScreen(stopId: child.stopId),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  final String value;
  final String label;
  final Color color;

  const _Stat(this.value, this.label, this.color);

  @override
  Widget build(BuildContext context) {
    return ZCard(
      radius: Z.rRow,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(value, style: Z.number(30, color: color)),
          Flexible(
            child: Text(label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Z.text(13, color: Z.muted, weight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}
