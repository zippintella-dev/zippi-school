import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../config.dart';
import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/common.dart';
import '../widgets/second_ticker.dart';

/// Screen 11 — the escalation ladder.
///
/// ⚠ THE ATTENDANT IS NOT BEING ASKED TO DECIDE ANYTHING, and the screen says
/// so in its first two lines. Standing at a kerb with a child whose guardian
/// has not come is the most pressured moment in this job; a screen that offered
/// choices there would be a screen that produced the wrong one. So it offers a
/// timeline to watch, a countdown, and — only when the window has run out — one
/// action.
///
/// ⚠ GUARDIAN NUMBERS STAY MASKED. The calls are placed by Zippi, not from the
/// crew's phone. An attendant who can read a parent's mobile number off a
/// screen is a parent's mobile number in a stranger's call history.
class EscalationScreen extends StatelessWidget {
  final String childId;

  const EscalationScreen({required this.childId, super.key});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final child = store.session!.childById(childId)!;

    return Scaffold(
      body: SafeArea(
        child: SecondTicker(
          builder: (context) {
            final elapsed = store.escalationElapsed(child);
            final remaining = store.escalationRemaining(child);
            final expired = store.escalationExpired(child);
            final waitUntil =
                child.escalationStartedAt?.add(Config.escalationWindow);

            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                ScreenHeader(
                  'Nobody is here for ${child.firstName}',
                  subtitle: '${child.firstName} stays on the bus. '
                      'You do not have to decide anything.',
                  onBack: () => Navigator.of(context).pop(),
                ),
                if (kDebugMode)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
                    child: Row(
                      children: [
                        TextButton(
                          onPressed: () {
                            // Debug only: rewind the start so the ladder can be
                            // watched without standing at a kerb for 3 minutes.
                            child.escalationStartedAt = child
                                .escalationStartedAt!
                                .subtract(const Duration(seconds: 30));
                            store.beginEscalation(child.id);
                          },
                          child: Text('Skip 30 s',
                              style: Z.text(13,
                                  color: Z.muted, weight: FontWeight.w800)),
                        ),
                        TextButton(
                          onPressed: () {
                            child.escalationStartedAt = DateTime.now();
                            store.beginEscalation(child.id);
                          },
                          child: Text('Restart',
                              style: Z.text(13,
                                  color: Z.muted, weight: FontWeight.w800)),
                        ),
                      ],
                    ),
                  ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
                  child: expired
                      ? ZBanner(
                          'Countdown expired',
                          body: 'No guardian could be reached. '
                              '${child.firstName} stays on the bus with you — '
                              'the only remaining action is below.',
                          tone: Tone.danger,
                          emphatic: true,
                        )
                      : ZCard(
                          padding: const EdgeInsets.symmetric(vertical: 18),
                          child: Column(
                            children: [
                              Text('BUS WAITS ANOTHER',
                                  style: Z
                                      .text(13,
                                          color: Z.muted,
                                          weight: FontWeight.w800)
                                      .copyWith(letterSpacing: 0.6)),
                              Text(fmtCountdown(remaining),
                                  style: Z.number(48,
                                      color: Z.coralText, height: 1.1)),
                              Text(
                                'until ${fmtTime(waitUntil)} · guardians '
                                'already notified',
                                textAlign: TextAlign.center,
                                style: Z.text(13, color: Z.muted),
                              ),
                            ],
                          ),
                        ),
                ),
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(24, 8, 24, 12),
                    children: [
                      for (final rung in _rungs(child.firstName))
                        _Rung(rung: rung, elapsed: elapsed),
                      const SizedBox(height: 8),
                      Padding(
                        padding: const EdgeInsets.only(left: 26),
                        child: Text(
                          'Guardian numbers stay masked. Calls are placed by '
                          'Zippi, not from your phone.',
                          style: Z.text(12, color: Z.faint).copyWith(height: 1.5),
                        ),
                      ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                  child: expired
                      ? Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            CoralButton(
                              'Return to school with ${child.firstName}',
                              height: 60,
                              onPressed: () {
                                try {
                                  store.returnToSchool(child.id);
                                  Navigator.of(context).pop();
                                } on SafetyViolation catch (e) {
                                  showBlocked(context, e);
                                }
                              },
                            ),
                            const SizedBox(height: 8),
                            Text(
                              'School and guardians are told the bus is coming '
                              'back. This is audited.',
                              textAlign: TextAlign.center,
                              style: Z.text(12, color: Z.faint),
                            ),
                          ],
                        )
                      // ⚠ Present but inert while the window runs. Hiding it
                      // would leave an attendant wondering whether there is any
                      // way out at all; showing it locked says there is one,
                      // and when.
                      : FilledButton(
                          onPressed: null,
                          child: const Text(
                              'Return to school — unlocks if no one comes'),
                        ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  static List<({Duration at, String time, String label})> _rungs(String name) => [
        (
          at: Duration.zero,
          time: 'T+0:00',
          label: 'Nobody is here for $name. Guardians notified. '
              'The bus waits.',
        ),
        (
          at: Config.escalationCall1,
          time: 'T+${fmtCountdown(Config.escalationCall1)}',
          label: 'Calling guardian 1 — number stays masked',
        ),
        (
          at: Config.escalationCall2,
          time: 'T+${fmtCountdown(Config.escalationCall2)}',
          label: 'Calling guardian 2 — number stays masked',
        ),
        (
          at: Config.escalationWindow,
          time: 'T+${fmtCountdown(Config.escalationWindow)}',
          label: 'Countdown expired — return to school',
        ),
      ];
}

class _Rung extends StatelessWidget {
  final ({Duration at, String time, String label}) rung;
  final Duration elapsed;

  const _Rung({required this.rung, required this.elapsed});

  @override
  Widget build(BuildContext context) {
    final reached = elapsed >= rung.at;
    final rungs = EscalationScreen._rungs('');
    final index = rungs.indexWhere((r) => r.time == rung.time);
    final next = index >= 0 && index + 1 < rungs.length ? rungs[index + 1] : null;

    final active = reached && (next == null || elapsed < next.at);
    final done = reached && !active;

    return Opacity(
      opacity: reached ? 1 : 0.45,
      child: Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 14,
              height: 14,
              margin: const EdgeInsets.only(top: 3),
              decoration: BoxDecoration(
                color: done
                    ? Z.teal
                    : active
                        ? Z.coral
                        : Z.cardBorder,
                shape: BoxShape.circle,
                border: Border.all(
                  color: active ? Z.coralSoft : Colors.transparent,
                  width: 3,
                ),
              ),
            ),
            const SizedBox(width: 12),
            SizedBox(
              width: 58,
              child: Text(rung.time,
                  style: Z.number(14, color: active ? Z.coralText : Z.ink)),
            ),
            Expanded(
              child: Text(rung.label,
                  style: Z.text(14, color: Z.ink).copyWith(height: 1.5)),
            ),
          ],
        ),
      ),
    );
  }
}
