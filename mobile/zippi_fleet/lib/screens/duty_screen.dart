import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../models/duty.dart';
import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/common.dart';
import '../widgets/offline_banner.dart';
import '../widgets/second_ticker.dart';
import 'checklist_screen.dart';
import 'driver_screen.dart';
import 'pm_boarding_screen.dart';
import 'stop_list_screen.dart';

/// Screen 3 — Duty dashboard.
///
/// ⚠ READ-ONLY, AND THAT IS THE DESIGN. A trip cannot be started from here.
/// Tapping opens it; beginning it needs the pre-trip checklist and then a
/// deliberate swipe. A "Start" button on a list of four trips is a button that
/// starts the wrong one, and starting the wrong one tells 22 families their
/// bus has left when it has not.
class DutyScreen extends StatefulWidget {
  const DutyScreen({super.key});

  @override
  State<DutyScreen> createState() => _DutyScreenState();
}

class _DutyScreenState extends State<DutyScreen> {
  /// Debug-only view switch so the empty and unreachable states can be seen
  /// without waiting for a day with no trips on it, or a dead zone.
  int _variant = 0;

  /// So the first-load kick below fires once per mount, not on every notify.
  bool _kicked = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    if (_kicked) return;
    _kicked = true;

    // ⚠ THE BOARD READS ITSELF WHEN IT IS SHOWN. The role picker's fetch can
    // fail — a dead zone at the depot gate is the ordinary case — and until
    // this existed, a crew member who then walked into signal sat on a board
    // that had failed once and would never ask again: the only other refresh
    // paths were a pull gesture and a button that did not actually fetch.
    //
    // `read`, not `of`: a dependency here would re-fire this on every notify.
    final store = FleetScope.read(context);
    if (!store.dutiesLoaded) {
      WidgetsBinding.instance
          .addPostFrameCallback((_) => store.refreshDuties());
    }
  }

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final assignment = store.assignment!;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (store.offline) OfflineBanner(queued: store.queuedEvents),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 20, 16, 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text("Today's trips", style: Z.head(26)),
                        const SizedBox(height: 2),
                        Text(
                          [
                            fmtDay(DateTime.now()),
                            if (store.busLabel.isNotEmpty) 'Bus ${store.busLabel}',
                            assignment.role.label,
                          ].join(' · '),
                          style: Z.text(14, color: Z.muted),
                        ),
                      ],
                    ),
                  ),
                  _CrewMenu(store: store),
                ],
              ),
            ),
            if (kDebugMode)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 6, 16, 2),
                child: StateChips(
                  labels: const ["Today's board", 'No trips', "Can't load"],
                  selected: _variant,
                  onSelect: (i) => setState(() => _variant = i),
                ),
              ),
            Expanded(child: _body(store)),
          ],
        ),
      ),
    );
  }

  /// ⚠ FOUR STATES, NOT TWO — and the two that were missing are the ones the
  /// crew hit first on a bad morning.
  ///
  /// "No trips today · this vehicle has nothing scheduled" is a statement about
  /// the school's timetable, and it may only be made when the server has
  /// actually made it. An empty [FleetStore.duties] on its own does not mean
  /// that: it is also what a board looks like before it has ever loaded, and
  /// what it looks like after a fetch died in a dead zone. Showing the
  /// timetable sentence for a failed fetch tells a crew member their bus is
  /// free when it has a run on it, and points them at the transport office
  /// instead of at the signal bar.
  Widget _body(FleetStore store) {
    final nothing = store.duties.isEmpty;

    // Debug-only overrides so both failure screens can be seen on demand.
    final unreachable =
        _variant == 2 || (nothing && store.dutiesError != null);
    final empty = _variant == 1 || (nothing && store.dutiesLoaded);
    final loading = nothing && !store.dutiesLoaded;

    // ⚠ A BOARD ALREADY IN HAND IS NEVER REPLACED BY AN ERROR. If a refresh
    // fails with trips on screen, the trips stay and the offline strip at the
    // top says why — taking the day's work away because one poll missed is
    // worse than showing a board that is a few minutes old.
    if (unreachable) {
      return ErrorState(
        store.dutiesError ??
            'Could not reach the server, so today\'s trips have not loaded. '
                'This is not "no trips" — it is "not known yet".',
        onRetry: store.refreshDuties,
      );
    }

    if (loading) return const _Checking();

    if (empty) {
      return EmptyState(
        icon: Icons.directions_bus_rounded,
        title: 'No trips today',
        body: 'This vehicle has nothing scheduled. If that looks wrong, call '
            'your transport office.',
        action: SizedBox(
          height: Z.hChip,
          child: OutlinedButton(
            // ⚠ IT FETCHES. This was a bare `setState`, which re-rendered the
            // label with the current clock and did nothing else — a button
            // whose only effect was to claim it had just checked. The time
            // shown is now the time of the last answer from the server.
            onPressed: store.busy ? null : store.refreshDuties,
            style: OutlinedButton.styleFrom(
              minimumSize: const Size(0, Z.hChip),
              padding: const EdgeInsets.symmetric(horizontal: 20),
            ),
            child: Text(
                store.busy
                    ? 'Checking…'
                    : 'Refresh · checked ${fmtTime(store.dutiesCheckedAt)}',
                style: Z.text(14, color: Z.teal, weight: FontWeight.w800)),
          ),
        ),
      );
    }

    return RefreshIndicator(
      // The board is what a crew member re-checks when the office says
      // something changed.
      onRefresh: store.refreshDuties,
      color: Z.teal,
      child: Builder(builder: (context) {
        final board = _Board.from(store.duties);

        return ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          children: [
            // ⚠ ONE TRIP IS THE ANSWER TO "what now?".
            //
            // The board used to be a flat list of equal cards. One bus runs
            // 2–3 tiers per direction, so a full day is up to six of them, and
            // a crew member at a kerb had to read all six and work out which
            // one they were standing in front of. The answer is almost always
            // exactly one trip — the running one, or the next to depart — so
            // that one is now large and first, and the rest fold below it.
            if (board.now != null) ...[
              _SectionLabel(
                board.now!.status == TripStatus.running ? 'RIGHT NOW' : 'NEXT',
              ),
              _TripCard(trip: board.now!),
              const SizedBox(height: 18),
            ],

            if (board.later.isNotEmpty) ...[
              _SectionLabel('LATER TODAY · ${board.later.length}'),
              for (final trip in board.later) ...[
                _TripCard(trip: trip),
                const SizedBox(height: 12),
              ],
              const SizedBox(height: 6),
            ],

            if (board.finished.isNotEmpty) ...[
              _SectionLabel('EARLIER TODAY · ${board.finished.length}'),
              for (final trip in board.finished) ...[
                _TripCard(trip: trip),
                const SizedBox(height: 10),
              ],
            ],

            const SizedBox(height: 4),
            Text(
              'Tap a trip to open it. Trips cannot be started from here.',
              textAlign: TextAlign.center,
              style: Z.text(13, color: Z.faint),
            ),
          ],
        );
      }),
    );
  }
}

/// The board before its first answer.
///
/// ⚠ WORDS, NOT A SPINNER. "Never a bare spinner that hangs" — a crew member
/// looking at an indeterminate circle cannot tell a slow read from a dead one,
/// and this screen is read at 6:40 AM by somebody deciding whether to wait or
/// to ring the depot.
class _Checking extends StatelessWidget {
  const _Checking();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Text(
          'Checking today\'s board…',
          textAlign: TextAlign.center,
          style: Z.text(15, color: Z.muted),
        ),
      ),
    );
  }
}

/// The board split three ways: what to do now, what is still to come, and what
/// is already a record.
///
/// ⚠ A RUNNING TRIP ALWAYS WINS. If a trip is under way it is what the crew is
/// standing in, whatever the clock says about the next departure — a bus that
/// left late must not be pushed below a trip that has not started.
///
/// ⚠ At most ONE trip is promoted. Two large cards is the same scanning problem
/// in a bigger typeface.
class _Board {
  final DutyTrip? now;
  final List<DutyTrip> later;
  final List<DutyTrip> finished;

  const _Board(this.now, this.later, this.finished);

  factory _Board.from(List<DutyTrip> duties) {
    final open = duties.where((t) => t.status.openable).toList();
    final done = duties.where((t) => !t.status.openable).toList();

    final running = open.where((t) => t.status == TripStatus.running).toList();

    final upcoming = open.where((t) => t.status != TripStatus.running).toList()
      // Earliest departure first. A null departure sorts last rather than
      // crashing the comparison — a trip the server could not solve a time for
      // is still a trip the crew must be able to see.
      ..sort((a, b) => (a.departsAt ?? DateTime(2100))
          .compareTo(b.departsAt ?? DateTime(2100)));

    if (running.isNotEmpty) {
      return _Board(running.first, [...running.skip(1), ...upcoming], done);
    }

    if (upcoming.isNotEmpty) {
      return _Board(upcoming.first, upcoming.skip(1).toList(), done);
    }

    return _Board(null, const [], done);
  }
}

/// A quiet divider. Words, not just spacing — a crew member scanning in
/// sunlight needs the grouping stated, not implied by a gap.
class _SectionLabel extends StatelessWidget {
  final String text;

  const _SectionLabel(this.text);

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(2, 0, 2, 8),
      child: Text(
        text,
        style: Z.text(11, color: Z.faint, weight: FontWeight.w800)
            .copyWith(letterSpacing: 0.8),
      ),
    );
  }
}

/// Opens a trip and lands on the right screen for the role and the direction.
///
/// ⚠ THE ROLE BRANCH IS THE POINT. A driver never reaches a child list, not
/// because the buttons are hidden but because the route to them does not
/// exist on their device.
Future<void> openTrip(
    BuildContext context, FleetStore store, DutyTrip trip) async {
  if (!trip.status.openable) return;

  if (!await runGuarded(context, () => store.openTrip(trip))) return;

  final session = store.session;
  if (session == null || !context.mounted) return;

  Widget destination() {
    if (store.role == FleetRole.driver) return const DriverScreen();

    if (!session.isRunning) return const ChecklistScreen();

    return trip.direction.isMorning
        ? const StopListScreen()
        : (session.lockedInAt == null
            ? const PmBoardingScreen()
            : const StopListScreen());
  }

  Navigator.of(context)
      .push(MaterialPageRoute(builder: (_) => destination()));
}

class _TripCard extends StatelessWidget {
  final DutyTrip trip;

  const _TripCard({required this.trip});

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final running = trip.status == TripStatus.running;

    // Done, cancelled and moved trips collapse to a single line. They are a
    // record; there is nothing to do to them.
    if (!trip.status.openable) {
      return Opacity(
        opacity: trip.status.opacity,
        child: ZCard(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(trip.recordTitle, style: Z.head(17)),
                    const SizedBox(height: 2),
                    Text(
                      trip.note ??
                          '${trip.routeName} · ${trip.childLabel} · ${trip.stopCount} stops',
                      style: Z.text(13, color: Z.muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              StatusPill(trip.status.pill,
                  tone: trip.status == TripStatus.done ? Tone.good : Tone.warn),
            ],
          ),
        ),
      );
    }

    return Opacity(
      opacity: trip.status.opacity,
      child: ZCard(
        onTap: () => openTrip(context, store, trip),
        padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
        borderColor: running ? Z.turquoise : Z.cardBorder,
        borderWidth: running ? 2 : 1.5,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(trip.title, style: Z.head(19))),
                const SizedBox(width: 10),
                StatusPill(
                  trip.status.pill,
                  tone: running ? Tone.live : Tone.neutral,
                  dot: running,
                  outlined: !running,
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(trip.subtitle, style: Z.text(14, color: Z.muted)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 14,
              runSpacing: 4,
              children: [
                Text(
                  running
                      ? 'Started ${trip.startedAt}'
                      : 'Departs ${trip.scheduledStart}',
                  style: Z.text(14, color: Z.ink, weight: FontWeight.w700),
                ),
                Text(trip.childLabel,
                    style: Z.text(14, color: Z.ink, weight: FontWeight.w700)),
                Text('${trip.stopCount} stops',
                    style: Z.text(14, color: Z.ink, weight: FontWeight.w700)),
                Text('Bell ${trip.bellTime}',
                    style: Z.text(14, color: Z.ink, weight: FontWeight.w700)),
              ],
            ),
            if (trip.departsAt != null) ...[
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerLeft,
                child: SecondTicker(builder: (_) => _LeaveChip(trip: trip)),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// "Leave in 18 min" → "Leave in 1:52" → "Leave now".
///
/// ⚠ It turns coral at two minutes rather than counting silently. The chip is
/// the only thing on this screen that says the bus is about to be late, and a
/// late bus is a bell missed by 22 children.
class _LeaveChip extends StatelessWidget {
  final DutyTrip trip;

  const _LeaveChip({required this.trip});

  @override
  Widget build(BuildContext context) {
    final left = trip.departsAt!.difference(DateTime.now());
    final soon = left.inSeconds <= 120;
    final now = left.inSeconds <= 0;

    final label = now
        ? 'Leave now'
        : soon
            ? 'Leave in ${fmtCountdown(left)}'
            : 'Leave in ${left.inMinutes + 1} min';

    return Container(
      height: 34,
      padding: const EdgeInsets.symmetric(horizontal: 14),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: soon ? Z.coral : Z.tealSoft,
        borderRadius: BorderRadius.circular(Z.rPill),
      ),
      child: Text(
        label,
        style: Z
            .text(13,
                color: soon ? Z.onCoral : Z.teal, weight: FontWeight.w800)
            .copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
      ),
    );
  }
}

/// The avatar in the corner: who is signed in, and the way back out.
class _CrewMenu extends StatelessWidget {
  final FleetStore store;

  const _CrewMenu({required this.store});

  @override
  Widget build(BuildContext context) {
    final initial = (store.crewName ?? '?').trim()[0].toUpperCase();

    return PopupMenuButton<String>(
      tooltip: 'Account',
      position: PopupMenuPosition.under,
      color: Z.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Z.rField),
        side: const BorderSide(color: Z.cardBorder, width: 1.5),
      ),
      onSelected: (v) {
        if (v == 'role') store.clearAssignment();
        if (v == 'offline') store.setOffline(!store.offline);
        if (v == 'out') store.signOut();
      },
      itemBuilder: (context) => [
        PopupMenuItem(
          value: 'role',
          child: Text('Change role or bus',
              style: Z.text(15, weight: FontWeight.w700)),
        ),
        if (kDebugMode)
          PopupMenuItem(
            value: 'offline',
            child: Text(
                store.offline ? 'Simulate: back online' : 'Simulate: offline',
                style: Z.text(15, weight: FontWeight.w700)),
          ),
        PopupMenuItem(
          value: 'out',
          child: Text('Sign out',
              style: Z.text(15, color: Z.coralText, weight: FontWeight.w700)),
        ),
      ],
      child: Container(
        width: Z.tapMin,
        height: Z.tapMin,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: Z.tealSoft,
          shape: BoxShape.circle,
          border: Border.all(color: Z.turquoise, width: 2),
        ),
        child: Text(initial, style: Z.head(16, color: Z.teal)),
      ),
    );
  }
}
