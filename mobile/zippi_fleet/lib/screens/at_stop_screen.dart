import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';
import '../models/trip.dart';
import '../services/fleet_scope.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/stop_missing.dart';
import '../widgets/common.dart';
import '../widgets/offline_banner.dart';
import '../widgets/second_ticker.dart';
import '../widgets/sos_fab.dart';

/// Screen 6 — At stop, the child list. The most important screen in the app.
///
/// ⚠ THE ERROR THIS SCREEN EXISTS TO PREVENT is tapping the adjacent row and
/// marking the wrong child. Twenty-two children board in about ninety seconds
/// while traffic waits behind the bus, the phone is held one-handed, and the
/// other hand is steadying a child. Everything here is a defence against that:
///
///   · 56dp minimum row height, and a whole-row target rather than a checkbox.
///   · A face on every row, because names are read wrong and faces are not.
///   · A distinguishing detail under every name — the Mehta siblings are the
///     case it exists for.
///   · Rows that change colour AND text the moment they are marked.
///   · A 90-second undo, shown as a countdown on the row itself.
///
/// And the head-count screen afterwards, which is the only thing that catches
/// the mis-tap that got through all of it.
class AtStopScreen extends StatelessWidget {
  final String stopId;

  const AtStopScreen({required this.stopId, super.key});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;

    // ⚠ NEVER `stopById(...)!`. A trip whose server payload carries children
    // but no stops — a row authored before the solver ran, or any trip with an
    // empty `route_schedule` — made this throw "Null check operator used on a
    // null value" and put a red screen in front of an attendant mid-route.
    // A crew member cannot act on a Flutter stack trace; say what is wrong.
    final stop = session.stopById(stopId);

    if (stop == null) return const StopMissingScreen();

    final children = session.childrenAt(stopId);

    final unresolved =
        children.where((c) => c.state == ChildState.expected).toList();

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
                    stop.name,
                    subtitle: '${fmtTime(stop.reachedAt)} · within '
                        '${Config.stopGeofenceMetres} m of the stop',
                    onBack: () => Navigator.of(context).pop(),
                    trailing: Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text('${session.onBoard}',
                            style: Z.number(26, height: 1)),
                        Text('of ${session.trip.childCount} on board',
                            style: Z.text(11,
                                color: Z.muted, weight: FontWeight.w800)),
                      ],
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 6),
                    child: _WaitCard(stop: stop),
                  ),
                  Expanded(
                    child: ListView.separated(
                      padding: const EdgeInsets.fromLTRB(16, 6, 16, 110),
                      itemCount: children.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 8),
                      itemBuilder: (context, i) =>
                          _ChildRow(child: children[i], stop: stop),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    child: stop.departed
                        ? ZBanner(
                            'Departed ${fmtTime(stop.departedAt)}',
                            tone: Tone.good,
                            icon: Icons.check_rounded,
                          )
                        : FilledButton(
                            onPressed: unresolved.isEmpty
                                ? () async {
                                    if (await runGuarded(
                                        context, () => store.departStop(stopId))) {
                                      if (context.mounted) {
                                        Navigator.of(context).pop();
                                      }
                                    }
                                  }
                                : null,
                            child: Text(
                              unresolved.isEmpty
                                  ? 'Depart stop'
                                  : 'Depart stop — ${unresolved.length} '
                                      '${unresolved.length == 1 ? 'child' : 'children'} '
                                      'unresolved',
                              textAlign: TextAlign.center,
                            ),
                          ),
                  ),
                ],
              ),
              const Positioned(right: 16, bottom: 84, child: SosFab()),
            ],
          ),
        ),
      ),
    );
  }
}

/// The wait countdown.
///
/// ⚠ 120 SECONDS BEFORE "NOT AT STOP" UNLOCKS, AND THE COUNTDOWN IS SHOWN.
/// A child running late down a lane is the ordinary case. Without a visible
/// timer an attendant under pressure marks them missing at forty seconds and
/// the bus leaves; with one, the wait is the app's decision rather than
/// theirs, and there is nothing to argue with.
class _WaitCard extends StatelessWidget {
  final TripStop stop;

  const _WaitCard({required this.stop});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final morning = store.session!.trip.direction.isMorning;
    final left = store.waitRemaining(stop);
    final over = left == Duration.zero;

    // On an afternoon trip there is no such thing as "not at stop", so there
    // is nothing to count down to.
    if (!morning) return const SizedBox.shrink();

    final t = toneOf(over ? Tone.warn : Tone.live);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: t.bg,
        borderRadius: BorderRadius.circular(Z.rField),
        border: Border.all(color: t.border, width: 1.5),
      ),
      child: Row(
        children: [
          Icon(over ? Icons.timer_off_outlined : Icons.timer_outlined,
              size: 18, color: t.fg),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              over
                  ? '"Not at stop" is unlocked — morning trips only'
                  : '"Not at stop" unlocks in ${fmtCountdown(left)}',
              style: Z
                  .text(13, color: t.fg, weight: FontWeight.w800)
                  .copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
            ),
          ),
        ],
      ),
    );
  }
}

class _ChildRow extends StatelessWidget {
  final TripChild child;
  final TripStop stop;

  const _ChildRow({required this.child, required this.stop});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);

    final boarded = child.state == ChildState.boarded;
    final notAt = child.state == ChildState.notAtStop;
    final canTap = child.state == ChildState.expected && !stop.departed;

    final canUndo = store.canUndoBoard(child);
    final showNotAt = child.state == ChildState.expected &&
        !stop.departed &&
        store.session!.trip.direction.isMorning &&
        store.waitRemaining(stop) == Duration.zero;

    return Semantics(
      button: canTap,
      label: '${child.name}, ${child.className}, '
          '${child.distinguishingDetail}. ${child.state.label}',
      child: Material(
        color: boarded
            ? Z.greenBg
            : notAt
                ? Z.divider
                : Z.surface,
        borderRadius: BorderRadius.circular(Z.rRow),
        child: InkWell(
          borderRadius: BorderRadius.circular(Z.rRow),
          onTap: canTap
              ? () {
                  // A short buzz per child, BEFORE the round trip. Twenty-two
                  // of them in ninety seconds is how an attendant knows a tap
                  // registered without stopping to look; waiting for the server
                  // to buzz would make the app feel broken at a kerb.
                  HapticFeedback.selectionClick();
                  runGuarded(context, () => store.markBoarded(child.id));
                }
              : null,
          child: Container(
            constraints: const BoxConstraints(minHeight: Z.tapSafety + 16),
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(Z.rRow),
              border: Border.all(
                color: boarded ? Z.green : Z.cardBorder,
                width: boarded ? 2 : 1.5,
              ),
            ),
            child: Row(
              children: [
                // Dimmed for anyone not travelling from this stop — absent and
                // parent-collecting as well as not-at-stop. The eye should skip
                // them when scanning who is still to board.
                ChildAvatar(child, dimmed: notAt || !canTap && !boarded),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(child.name, style: Z.head(17)),
                      Text(
                        '${child.className} · ${child.distinguishingDetail}',
                        style: Z.text(13, color: Z.muted),
                      ),
                      const SizedBox(height: 2),
                      // ⚠ EVERY state gets its own words, not just the three
                      // this row used to know about.
                      //
                      // The branches were boarded / notAtStop / else, and the
                      // else read "Tap to mark boarded". So a child the office
                      // or the parent had already marked ABSENT — and whom
                      // canTap correctly refuses to accept a tap for — still
                      // invited the attendant to tap them. The instruction and
                      // the behaviour disagreed, which at a kerb reads as the
                      // app being broken.
                      //
                      // Anything that is not "waiting to be boarded" now shows
                      // ChildState.label: Absent, Parent collecting, Not at
                      // stop · guardians notified, Arrived at school.
                      Text(
                        boarded
                            ? 'Boarded ${fmtTime(child.stateChangedAt)}'
                            : canTap
                                ? 'Tap to mark boarded'
                                : child.state.label,
                        style: Z.text(13,
                            color: boarded
                                ? Z.green
                                : canTap
                                    ? Z.teal
                                    : Z.muted,
                            weight: FontWeight.w800),
                      ),
                    ],
                  ),
                ),
                if (canUndo) ...[
                  const SizedBox(width: 8),
                  _UndoPill(child: child),
                ],
                if (showNotAt) ...[
                  const SizedBox(width: 8),
                  _NotAtStopPill(child: child),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The mis-tap window, counted down on the row itself.
class _UndoPill extends StatelessWidget {
  final TripChild child;

  const _UndoPill({required this.child});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);

    return SizedBox(
      height: Z.tapMin,
      child: OutlinedButton(
        onPressed: () => runGuarded(context, () => store.undoBoard(child.id)),
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(0, Z.tapMin),
          padding: const EdgeInsets.symmetric(horizontal: 14),
          side: const BorderSide(color: Z.greenBorder, width: 1.5),
          backgroundColor: Z.surface,
        ),
        child: Text(
          'Undo ${fmtCountdown(store.undoRemaining(child))}',
          style: Z
              .text(13, color: Z.green, weight: FontWeight.w800)
              .copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
        ),
      ),
    );
  }
}

/// ⚠ MORNING ONLY. See `FleetStore.markNotAtStop` for why an afternoon
/// equivalent does not and must not exist.
class _NotAtStopPill extends StatelessWidget {
  final TripChild child;

  const _NotAtStopPill({required this.child});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);

    return SizedBox(
      height: Z.tapMin,
      child: OutlinedButton(
        onPressed: () async {
          // A second, deliberate confirmation. This one tells a family their
          // child was not where they said they would be.
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
                      '${child.name} is not at the stop?',
                      body: 'The bus has waited the full '
                          '${Config.stopWait.inSeconds} seconds. Their '
                          'guardians and the school office are told '
                          'immediately, and the bus goes on.',
                      tone: Tone.warn,
                    ),
                    const SizedBox(height: 12),
                    FilledButton(
                      onPressed: () => Navigator.of(sheet).pop(true),
                      child: Text('Yes — ${child.firstName} is not here'),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: () => Navigator.of(sheet).pop(false),
                      child: const Text('Wait a little longer'),
                    ),
                  ],
                ),
              ),
            ),
          );

          if (ok != true || !context.mounted) return;

          await runGuarded(context, () => store.markNotAtStop(child.id));
        },
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(0, Z.tapMin),
          padding: const EdgeInsets.symmetric(horizontal: 14),
          side: const BorderSide(color: Z.amberBorder, width: 1.5),
          backgroundColor: Z.amberBg,
        ),
        child: Text('Not at stop',
            style: Z.text(13, color: Z.amber, weight: FontWeight.w800)),
      ),
    );
  }
}
