import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zippi_fleet/config.dart';
import 'package:zippi_fleet/main.dart';
import 'package:zippi_fleet/models/duty.dart';
import 'package:zippi_fleet/models/sos.dart';
import 'package:zippi_fleet/models/trip.dart';
import 'package:zippi_fleet/screens/arrival_screen.dart';
import 'package:zippi_fleet/screens/at_stop_screen.dart';
import 'package:zippi_fleet/screens/checklist_screen.dart';
import 'package:zippi_fleet/screens/complete_screen.dart';
import 'package:zippi_fleet/screens/driver_screen.dart';
import 'package:zippi_fleet/screens/drop_stop_screen.dart';
import 'package:zippi_fleet/screens/duty_screen.dart';
import 'package:zippi_fleet/screens/escalation_screen.dart';
import 'package:zippi_fleet/screens/head_count_screen.dart';
import 'package:zippi_fleet/screens/login_screen.dart';
import 'package:zippi_fleet/screens/pm_boarding_screen.dart';
import 'package:zippi_fleet/screens/role_screen.dart';
import 'package:zippi_fleet/screens/sos_screen.dart';
import 'package:zippi_fleet/screens/stop_list_screen.dart';
import 'package:zippi_fleet/screens/sweep_screen.dart';
import 'package:zippi_fleet/services/demo_data.dart';
import 'package:zippi_fleet/services/fleet_scope.dart';
import 'package:zippi_fleet/services/fleet_store.dart';
import 'package:zippi_fleet/theme.dart';

/// Every screen, pumped at phone size.
///
/// ⚠ THE POINT IS THE OVERFLOWS. A `RenderFlex overflowed` on a screen that
/// only appears when a child cannot be verified is a screen nobody sees until
/// the worst afternoon of somebody's year. Flutter turns those into test
/// failures, so pumping each state here is the cheapest way to know they render
/// — including the blocked, expired and refused states, which are the ones a
/// manual walkthrough never reaches.
///
/// 428 × 908 is the design's artboard.
void main() {
  const phone = Size(428, 908);

  Future<void> pumpScreen(WidgetTester tester, FleetStore store, Widget screen,
      {Size size = phone}) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      FleetScope(
        store: store,
        child: MaterialApp(theme: Z.theme(), home: screen),
      ),
    );

    await tester.pump(const Duration(milliseconds: 100));
  }

  FleetStore attendantStore({bool morning = true, bool running = true}) {
    final store = FleetStore()
      ..signIn(name: DemoData.crewName, phoneNumber: DemoData.crewPhone)
      ..chooseAssignment(DemoData.assignments
          .firstWhere((a) => a.role == FleetRole.attendant));

    final trip = store.duties
        .firstWhere((t) => t.id == (morning ? 'trip-am' : 'trip-pm'));

    store.openTrip(trip);

    if (running) {
      for (var i = 0; i < store.session!.checklist.length; i++) {
        store.toggleCheck(i);
      }
      store.beginTrip();
    }

    return store;
  }

  /* ---------------- shared ---------------- */

  testWidgets('1 · Login renders', (tester) async {
    await pumpScreen(tester, FleetStore(), const LoginScreen());

    expect(find.text('Mobile number'), findsOneWidget);
    expect(find.text('Send OTP'), findsOneWidget);
  });

  testWidgets('2 · Role & vehicle pre-selects today', (tester) async {
    final store = FleetStore()
      ..signIn(name: DemoData.crewName, phoneNumber: DemoData.crewPhone);

    await pumpScreen(tester, store, const RoleScreen());

    expect(find.text('Who are you today?'), findsOneWidget);
    expect(find.text("Today's assignment"), findsOneWidget);
    expect(find.text('Continue as attendant'), findsOneWidget);
  });

  testWidgets('3 · Duty dashboard is read-only', (tester) async {
    final store = FleetStore()
      ..signIn(name: DemoData.crewName, phoneNumber: DemoData.crewPhone)
      ..chooseAssignment(DemoData.assignments.first);

    await pumpScreen(tester, store, const DutyScreen());

    expect(find.text("Today's trips"), findsOneWidget);
    expect(find.text('Now running'), findsOneWidget);

    // ⚠ ONE trip is promoted, and a RUNNING one always wins.
    //
    // One bus runs 2–3 tiers per direction, so a full day is up to six cards.
    // As a flat list a crew member at a kerb had to read all six to work out
    // which one they were standing in front of. Exactly one section may say
    // "RIGHT NOW" — two promoted cards is the same scanning problem in a
    // bigger typeface.
    expect(find.text('RIGHT NOW'), findsOneWidget);
    expect(find.text('NEXT'), findsNothing,
        reason: 'A running trip outranks the next departure.');

    // ⚠ No control on this screen starts a trip. "Started 6:58 AM" is a fact
    // about the running trip, not a button — there is no button at all.
    expect(find.byType(FilledButton), findsNothing);
    expect(find.byType(OutlinedButton), findsNothing);

    await tester.scrollUntilVisible(
      find.text('Tap a trip to open it. Trips cannot be started from here.'),
      200,
    );
    expect(
      find.text('Tap a trip to open it. Trips cannot be started from here.'),
      findsOneWidget,
    );
  });

  /* ---------------- attendant, morning ---------------- */

  testWidgets('4 · Checklist blocks the swipe until all four are ticked',
      (tester) async {
    // The up-next trip: a checklist is only ever shown for a trip that has not
    // begun. A running trip opens running.
    final store = attendantStore(morning: false, running: false);

    await pumpScreen(tester, store, const ChecklistScreen());

    expect(find.text('Finish the checklist to unlock Begin trip'),
        findsOneWidget);

    for (var i = 0; i < store.session!.checklist.length; i++) {
      store.toggleCheck(i);
    }
    await tester.pump();

    expect(
      find.text('Swipe all the way across — release early and it springs back'),
      findsOneWidget,
    );
  });

  testWidgets('4b · Already running elsewhere', (tester) async {
    final store = attendantStore(running: false);
    store.openTrip(store.duties.first, startedElsewhere: true);

    await pumpScreen(tester, store, const ChecklistScreen());

    expect(find.text('This trip is already running on another device'),
        findsOneWidget);
  });

  testWidgets('5 · Stop list marks the next stop', (tester) async {
    final store = attendantStore();

    await pumpScreen(tester, store, const StopListScreen());

    expect(find.text('NEXT'), findsOneWidget);
    expect(find.text('Film Nagar'), findsOneWidget);
    expect(find.text('SOS'), findsOneWidget);
  });

  testWidgets('5b · Stop list, all stops done', (tester) async {
    final store = attendantStore();
    for (final stop in store.session!.stops) {
      store.reachStop(stop.id);
    }

    await pumpScreen(tester, store, const StopListScreen());

    expect(find.text('Head-count check →'), findsOneWidget);
  });

  testWidgets('6 · At stop — fresh arrival, then boarded', (tester) async {
    final store = attendantStore();
    store.reachStop('stop-kbr');

    await pumpScreen(tester, store, const AtStopScreen(stopId: 'stop-kbr'));

    expect(find.text('KBR Park Gate'), findsOneWidget);
    expect(find.text('Aarav Mehta'), findsOneWidget);

    // ⚠ Siblings are distinguishable without reading the surname.
    expect(find.text("Class 1A · Red name tag · Aarav's sister"), findsOneWidget);
    expect(find.text('Class 3B · Blue name tag'), findsOneWidget);

    // "Not at stop" is locked while the wait runs.
    expect(find.text('Not at stop'), findsNothing);
    expect(find.textContaining('"Not at stop" unlocks in'), findsOneWidget);

    await tester.tap(find.text('Aarav Mehta'));
    await tester.pump();

    expect(find.textContaining('Boarded'), findsOneWidget);
    expect(find.textContaining('Undo'), findsOneWidget);
  });

  testWidgets('6b · At stop — wait expired unlocks "Not at stop"',
      (tester) async {
    final store = attendantStore();
    store.reachStop('stop-kbr');
    store.session!.stopById('stop-kbr')!.reachedAt =
        DateTime.now().subtract(Config.stopWait + const Duration(seconds: 1));

    await pumpScreen(tester, store, const AtStopScreen(stopId: 'stop-kbr'));

    expect(find.text('Not at stop'), findsWidgets);
    expect(find.text('"Not at stop" is unlocked — morning trips only'),
        findsOneWidget);
  });

  testWidgets('7 · Head count — match and mismatch', (tester) async {
    final store = attendantStore();
    store.reachStop('stop-kbr');
    for (final child in store.session!.childrenAt('stop-kbr')) {
      store.markBoarded(child.id);
    }

    await pumpScreen(tester, store, const HeadCountScreen());

    expect(find.text('Head count'), findsOneWidget);

    // A deliberately wrong count.
    await tester.tap(find.text('1'));
    await tester.pump();
    await tester.tap(find.text('Check count'));
    await tester.pump();

    expect(find.text('Counts do not match'), findsOneWidget);
    expect(find.text('Recount'), findsOneWidget);
  });

  testWidgets('8 · Arrival at school', (tester) async {
    final store = attendantStore();
    store.reachStop('stop-kbr');
    for (final child in store.session!.childrenAt('stop-kbr')) {
      store.markBoarded(child.id);
    }
    store.submitHeadCount(store.session!.onBoard);

    await pumpScreen(tester, store, const ArrivalScreen());

    expect(find.text('Silver Oak School'), findsOneWidget);
    expect(find.text('WHAT PARENTS SEE'), findsOneWidget);
    expect(find.textContaining('Confirm disembark'), findsOneWidget);
  });

  /* ---------------- attendant, afternoon ---------------- */

  testWidgets('9 · Afternoon boarding groups by class', (tester) async {
    final store = attendantStore(morning: false);

    await pumpScreen(tester, store, const PmBoardingScreen());

    expect(find.text('Afternoon boarding'), findsOneWidget);

    // ⚠ Youngest first: UKG is at the top of the screen, not buried in
    // roster order somewhere past the fold.
    expect(find.text('UKG'), findsOneWidget);
    expect(find.text('PARENT COLLECTING'), findsOneWidget);
    expect(find.text('☐ NOT BOARDED'), findsWidgets);

    await tester.scrollUntilVisible(find.text('CLASS 3B'), 200);
    expect(find.text('CLASS 3B'), findsOneWidget);
  });

  testWidgets('10 · Drop stop offers exactly three ways out, and no skip',
      (tester) async {
    final store = attendantStore(morning: false);

    // Put the KBR children on the bus and leave the school.
    for (final child in store.session!.childrenAt('stop-kbr')) {
      if (child.state == ChildState.expected) store.togglePmBoarded(child.id);
    }
    store.reachStop('stop-kbr');

    await pumpScreen(tester, store, const DropStopScreen(stopId: 'stop-kbr'));

    expect(find.text('Verify handover code'), findsOneWidget);
    expect(find.text('Pick authorized person'), findsOneWidget);
    expect(find.text('No one is here'), findsOneWidget);

    // ⚠ There is no skip and no "leave child", on any screen, ever.
    expect(find.textContaining('Skip'), findsNothing);
    expect(
      find.text(
          'There is no skip and no "leave child". If nobody can be verified, '
          'the bus returns to school.'),
      findsOneWidget,
    );
  });

  testWidgets('10b · Self-release is absent for a child without consent',
      (tester) async {
    final store = attendantStore(morning: false);

    final aarav = store.session!.childById('c12')!;
    store.togglePmBoarded(aarav.id);
    store.reachStop('stop-kbr');

    await pumpScreen(tester, store, const DropStopScreen(stopId: 'stop-kbr'));

    await tester.tap(find.text('Aarav'));
    await tester.pump();

    expect(find.text('Self-release'), findsNothing);
    expect(find.text('Self-release is not shown — Aarav has no consent on file.'),
        findsOneWidget);
  });

  testWidgets('11 · Escalation — running, then expired', (tester) async {
    final store = attendantStore(morning: false);

    final aarav = store.session!.childById('c12')!;
    store.togglePmBoarded(aarav.id);
    store.beginEscalation(aarav.id);

    await pumpScreen(tester, store, const EscalationScreen(childId: 'c12'));

    expect(find.text('Nobody is here for Aarav'), findsOneWidget);
    expect(find.text('BUS WAITS ANOTHER'), findsOneWidget);
    expect(find.text('Return to school — unlocks if no one comes'),
        findsOneWidget);

    aarav.escalationStartedAt = DateTime.now()
        .subtract(Config.escalationWindow + const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));

    expect(find.text('Countdown expired'), findsOneWidget);
    expect(find.text('Return to school with Aarav'), findsOneWidget);
  });

  testWidgets('12 · Sweep — blocked, then ready', (tester) async {
    final store = attendantStore();

    await pumpScreen(tester, store, const SweepScreen());

    expect(find.text('Sweep is locked'), findsOneWidget);

    store.setAtSchoolGate(true);
    await tester.pump();

    expect(find.text('At school gate ✓'), findsOneWidget);
    expect(find.text('Take the photo first'), findsOneWidget);
  });

  testWidgets('13 · Complete — the refusal names the child', (tester) async {
    final store = attendantStore(morning: false);

    final aarav = store.session!.childById('c12')!;
    store.togglePmBoarded(aarav.id);
    store.setAtSchoolGate(true);
    store.confirmSweep(photoPath: '/tmp/sweep.jpg');

    await pumpScreen(tester, store, const CompleteScreen());

    await tester.tap(find.text('Complete trip'));
    await tester.pump();

    expect(find.textContaining('Cannot complete: Aarav Mehta is still on board'),
        findsOneWidget);

    // The way to the child is offered, not just the refusal.
    await tester.scrollUntilVisible(
      find.text("Open Aarav's stop"),
      200,
      // The page's own list, not the stats grid nested inside it.
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text("Open Aarav's stop"), findsOneWidget);
  });

  /* ---------------- driver ---------------- */

  testWidgets('14 · Driver screen has no child-marking control anywhere',
      (tester) async {
    final store = FleetStore()
      ..signIn(name: DemoData.crewName, phoneNumber: DemoData.crewPhone)
      ..chooseAssignment(DemoData.assignments
          .firstWhere((a) => a.role == FleetRole.driver));

    store.openTrip(store.duties.firstWhere((t) => t.id == 'trip-am'));

    await pumpScreen(tester, store, const DriverScreen());

    expect(find.textContaining('on board · next:'), findsOneWidget);
    expect(find.text('km/h'), findsOneWidget);
    expect(find.text('SOS'), findsOneWidget);
    expect(
      find.text('No child marking on this device. The attendant marks '
          'children — you drive.'),
      findsOneWidget,
    );

    // No child's name appears on a driver device at all.
    for (final child in store.session!.children) {
      expect(find.text(child.name), findsNothing);
    }
  });

  /* ---------------- SOS ---------------- */

  testWidgets('15 · SOS is a hold, and a tap does nothing', (tester) async {
    final store = attendantStore();

    await pumpScreen(tester, store, const SosScreen());

    expect(find.text('HOLD 2 SECONDS'), findsOneWidget);

    await tester.tap(find.text('SOS'));
    await tester.pump(const Duration(milliseconds: 300));

    expect(store.sos, isNull, reason: 'a tap must not fire an alarm');
    expect(find.text('What is happening?'), findsNothing);
  });

  testWidgets('15b · SOS fired state locks child records', (tester) async {
    final store = attendantStore();
    store.fireSos(SosType.medical);

    await pumpScreen(tester, store, const SosScreen());

    expect(find.text('SOS sent · Medical'), findsOneWidget);
    expect(find.text('Child records are locked'), findsOneWidget);
    expect(find.text('Waiting for ops · they release the lock'), findsOneWidget);
  });

  testWidgets('15c · Drill is banded', (tester) async {
    final store = attendantStore();
    store.fireSos(SosType.other, drill: true);

    await pumpScreen(tester, store, const SosScreen());

    expect(find.text('THIS IS A DRILL · NO HELP IS DISPATCHED'), findsOneWidget);
    expect(find.text('Drill sent · Other'), findsOneWidget);
  });

  /* ---------------- offline ---------------- */

  testWidgets('Offline banner reassures rather than blocks', (tester) async {
    final store = attendantStore()..setOffline(true);

    await pumpScreen(tester, store, const StopListScreen());

    expect(
      find.text('No signal here. Carry on — everything saves and syncs later.'),
      findsOneWidget,
    );

    // The list is still fully usable.
    expect(find.text('Film Nagar'), findsOneWidget);
  });

  /* ---------------- the app itself ---------------- */

  testWidgets('The app boots to login when the keystore does not answer',
      (tester) async {
    // ⚠ Every test above mounts a screen directly, which never exercises
    // `main()`'s startup path — and that path talks to the platform keystore,
    // which does not exist under `flutter test`, so the channel call never
    // answers. That is exactly the broken-handset case, and it must land on
    // the login screen rather than a splash that never resolves.
    tester.view.physicalSize = phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const ZippiFleetApp());

    // Still waiting, briefly — that part is fine.
    expect(find.byType(CircularProgressIndicator), findsOneWidget);

    // Past the restore budget, it must have given up and moved on.
    await tester.pump(const Duration(seconds: 4));
    await tester.pump();

    expect(find.text('Mobile number'), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });
}
