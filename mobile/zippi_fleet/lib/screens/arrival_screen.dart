import 'package:flutter/material.dart';

import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/common.dart';
import 'sweep_screen.dart';

/// Screen 8 — At school, arrival. Morning.
///
/// ⚠ MORNING CUSTODY GOES CHILD → INSTITUTION, and that is why there is no
/// code here. The bus delivers children to a school, which does not have to
/// prove its identity to receive a pupil. The receiver check belongs to the
/// afternoon, where custody goes school → *individual*, at a kerb, to whoever
/// turns up — see `drop_stop_screen.dart` and PART A7.
///
/// This has been asked about and re-decided once already. If a morning
/// boarding code is ever genuinely wanted, ADD one; never relocate the
/// afternoon's, which would leave the drop with no receiver verification
/// anywhere.
class ArrivalScreen extends StatelessWidget {
  const ArrivalScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;
    final done = session.disembarkedAt != null;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ScreenHeader(
              session.trip.schoolName,
              subtitle: '${fmtTime(DateTime.now())} · at the school gate',
              onBack: () => Navigator.of(context).pop(),
              trailing: const StatusPill('Geo-fence ✓', tone: Tone.good),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 6, 16, 20),
                children: [
                  ZCard(
                    padding: const EdgeInsets.symmetric(vertical: 20),
                    child: Column(
                      children: [
                        Text('${done ? session.boardedEver : session.onBoard}',
                            style: Z.number(40)),
                        const SizedBox(height: 2),
                        Text(
                          done
                              ? 'children arrived at school'
                              : 'children on board, counted and matched',
                          textAlign: TextAlign.center,
                          style: Z.text(14,
                              color: Z.muted, weight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  if (!done) ...[
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 14),
                      decoration: BoxDecoration(
                        color: Z.bg,
                        borderRadius: BorderRadius.circular(Z.rField),
                        border: Border.all(color: Z.cardBorder, width: 1.5),
                      ),
                      child: Text.rich(
                        TextSpan(
                          text: 'Confirming disembark marks every boarded child ',
                          children: [
                            TextSpan(
                              text: 'arrived at school',
                              style: Z.text(13,
                                  color: Z.ink, weight: FontWeight.w800),
                            ),
                            const TextSpan(
                                text: ' and notifies all guardians.'),
                          ],
                        ),
                        style: Z.text(13, color: Z.muted).copyWith(height: 1.5),
                      ),
                    ),
                    const SizedBox(height: 14),
                    FilledButton(
                      onPressed: () {
                        try {
                          store.confirmDisembark();
                        } on SafetyViolation catch (e) {
                          showBlocked(context, e);
                        }
                      },
                      child: Text(
                          'Confirm disembark — ${session.onBoard} children'),
                    ),
                  ] else ...[
                    ZBanner(
                      '${session.boardedEver} children arrived at school',
                      body: 'All guardians notified. The bus is not done yet — '
                          'sweep it.',
                      tone: Tone.good,
                      icon: Icons.check_rounded,
                    ),
                    const SizedBox(height: 12),
                    // ⚠ Coral, not turquoise. The sweep is the thing that is
                    // still owed, and Invariant #3 says the trip cannot close
                    // without it.
                    CoralButton(
                      'Sweep the bus →',
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const SweepScreen()),
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  // A small window into the Parent app. The two apps are one
                  // product to a family, and an attendant who can see what the
                  // parent sees can answer a phone call at the gate.
                  ZCard(
                    padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('WHAT PARENTS SEE',
                            style: Z
                                .text(13,
                                    color: Z.muted, weight: FontWeight.w800)
                                .copyWith(letterSpacing: 0.6)),
                        const SizedBox(height: 10),
                        Row(
                          children: [
                            Container(
                              width: 40,
                              height: 40,
                              alignment: Alignment.center,
                              decoration: const BoxDecoration(
                                  color: Z.tealSoft, shape: BoxShape.circle),
                              child: Text('A', style: Z.head(17, color: Z.teal)),
                            ),
                            const SizedBox(width: 10),
                            Flexible(
                              child: StatusPill(
                                done
                                    ? 'Arrived at school · '
                                        '${fmtTime(session.disembarkedAt)}'
                                    : 'On the bus · arriving',
                                tone: done ? Tone.good : Tone.live,
                                dot: true,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
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
