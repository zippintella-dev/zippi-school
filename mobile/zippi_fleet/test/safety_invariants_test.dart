import 'package:flutter_test/flutter_test.dart';
import 'package:zippi_fleet/config.dart';
import 'package:zippi_fleet/models/duty.dart';
import 'package:zippi_fleet/models/sos.dart';
import 'package:zippi_fleet/models/trip.dart';
import 'package:zippi_fleet/services/demo_data.dart';
import 'package:zippi_fleet/services/fleet_store.dart';

/// The four safety invariants, asserted against [FleetStore].
///
/// ⚠ THE COUNTERPART OF `tests/Feature/SafetyInvariantsTest.php`. The server is
/// the authority and has its own version of these; this file asserts that the
/// app does not offer a crew member a route the server would refuse — because
/// an app that lets someone tap "release" and then rejects it has already shown
/// them a door.
///
/// If a change makes one of these fail, the change is wrong.
void main() {
  late FleetStore store;

  DutyTrip morningTrip() =>
      DemoData.duties(DateTime.now()).firstWhere((t) => t.id == 'trip-am');

  DutyTrip afternoonTrip() =>
      DemoData.duties(DateTime.now()).firstWhere((t) => t.id == 'trip-pm');

  /// A running morning trip with an attendant holding the phone.
  ///
  /// ⚠ Async because every mutation is now a Future — in demo mode it resolves
  /// immediately, live it is a call to `/api/fleet`. The tests run in demo
  /// mode, which is the point: these assert the LOCAL rules, and
  /// `tests/Feature/FleetApiTest.php` asserts the server's.
  Future<void> startMorning() async {
    await store.chooseAssignment(DemoData.assignments
        .firstWhere((a) => a.role == FleetRole.attendant));
    await store.openTrip(morningTrip());

    for (var i = 0; i < store.session!.checklist.length; i++) {
      await store.toggleCheck(i);
    }

    await store.beginTrip();
  }

  Future<void> startAfternoon() async {
    await store.chooseAssignment(DemoData.assignments
        .firstWhere((a) => a.role == FleetRole.attendant));
    await store.openTrip(afternoonTrip());

    for (var i = 0; i < store.session!.checklist.length; i++) {
      await store.toggleCheck(i);
    }

    await store.beginTrip();
  }

  setUp(() => store = FleetStore());

  /* =================================================================== */
  group('Invariant #1 — a child is never released without a verified receiver',
      () {
    test('self-release is refused for a child with no consent on file', () async {
      await startAfternoon();

      final aarav = store.session!.childById('c12')!;
      expect(aarav.maySelfRelease, isFalse,
          reason: 'the fixture depends on Aarav having no consent');

      await store.togglePmBoarded(aarav.id);
      expect(aarav.state, ChildState.boarded);

      expect(
        () => store.recordHandover(
          childId: aarav.id,
          method: HandoverMethod.selfRelease,
          receiverLabel: 'self',
        ),
        throwsA(isA<SafetyViolation>()),
      );

      expect(aarav.state, ChildState.boarded,
          reason: 'a refused handover must not move the child');
    });

    test('self-release is allowed only with consent', () async {
      await startAfternoon();

      final zoya = store.session!.childById('c11')!;
      expect(zoya.maySelfRelease, isTrue);

      await store.togglePmBoarded(zoya.id);
      await store.recordHandover(
        childId: zoya.id,
        method: HandoverMethod.selfRelease,
        receiverLabel: 'Signed consent on file',
      );

      expect(zoya.state, ChildState.handedOver);
      expect(zoya.handover!.method, HandoverMethod.selfRelease);
    });

    test('there is no "not at stop" on an afternoon trip', () async {
      await startAfternoon();

      final aarav = store.session!.childById('c12')!;
      await store.togglePmBoarded(aarav.id);

      // The afternoon equivalent of "not at stop" would be putting a child out
      // at a kerb where nobody came. It must not exist.
      expect(
        () => store.markNotAtStop(aarav.id),
        throwsA(isA<SafetyViolation>()),
      );
    });

    test('return to school is locked until the escalation window expires', () async {
      await startAfternoon();

      final aarav = store.session!.childById('c12')!;
      await store.togglePmBoarded(aarav.id);
      await store.beginEscalation(aarav.id);

      expect(
        () => store.returnToSchool(aarav.id),
        throwsA(isA<SafetyViolation>()),
        reason: 'the bus waits the full window first',
      );

      // Wind the clock back past the window.
      aarav.escalationStartedAt = DateTime.now()
          .subtract(Config.escalationWindow + const Duration(seconds: 1));

      await store.returnToSchool(aarav.id);
      expect(aarav.state, ChildState.returnedToSchool);
    });

    test('a drop stop cannot be departed with a child still on the bus',
        () async {
      await startAfternoon();

      final aarav = store.session!.childById('c12')!;
      await store.togglePmBoarded(aarav.id);
      await store.reachStop('stop-kbr');

      // ⚠ Departing here would carry Aarav past his own stop, and the handover
      // that should have happened there never would.
      SafetyViolation? thrown;
      try {
        await store.departStop('stop-kbr');
      } on SafetyViolation catch (e) {
        thrown = e;
      }

      expect(thrown, isNotNull);
      expect(thrown!.message, contains('Aarav Mehta'));
      expect(thrown.message, contains('has not been handed over'));
      expect(store.session!.stopById('stop-kbr')!.departed, isFalse);

      await store.recordHandover(
        childId: aarav.id,
        method: HandoverMethod.code,
        receiverLabel: 'Guardian at the stop',
      );

      await store.departStop('stop-kbr');
      expect(store.session!.stopById('stop-kbr')!.departed, isTrue);
    });

    test('a stop cannot be departed with an unresolved child at it', () async {
      await startMorning();

      const stopId = 'stop-kbr';
      await store.reachStop(stopId);

      expect(
        () => store.departStop(stopId),
        throwsA(isA<SafetyViolation>()),
      );

      for (final child in store.session!.childrenAt(stopId)) {
        await store.markBoarded(child.id);
      }

      await store.departStop(stopId);
      expect(store.session!.stopById(stopId)!.departed, isTrue);
    });
  });

  /* =================================================================== */
  group('Invariant #2 — a trip cannot complete with a child unaccounted for',
      () {
    test('completion is refused and the child is named', () async {
      await startAfternoon();

      final aarav = store.session!.childById('c12')!;
      await store.togglePmBoarded(aarav.id);

      // Satisfy Invariant #3 so this test is only about #2.
      store.setAtSchoolGate(true);
      await store.confirmSweep(photoPath: '/tmp/sweep.jpg');

      SafetyViolation? thrown;

      try {
        await store.completeTrip();
      } on SafetyViolation catch (e) {
        thrown = e;
      }

      expect(thrown, isNotNull);
      expect(thrown!.message, contains('Aarav Mehta'),
          reason: 'a count is dismissed; a name is a person somebody finds');
      expect(thrown.childIds, contains('c12'));
      expect(store.session!.completedAt, isNull);
    });

    test('completion succeeds once every child is resolved', () async {
      await startMorning();

      for (final stop in store.session!.stops) {
        await store.reachStop(stop.id);

        for (final child in store.session!.childrenAt(stop.id)) {
          await store.markBoarded(child.id);
        }
      }

      await store.submitHeadCount(store.session!.onBoard);
      await store.confirmDisembark();

      expect(store.session!.unaccounted, isEmpty);

      store.setAtSchoolGate(true);
      await store.confirmSweep(photoPath: '/tmp/sweep.jpg');
      await store.completeTrip();

      expect(store.session!.completedAt, isNotNull);
    });
  });

  /* =================================================================== */
  group('Invariant #3 — the bus is swept before the trip closes', () {
    test('the sweep cannot be confirmed away from the school', () async {
      await startMorning();

      expect(store.session!.atSchoolGate, isFalse);
      expect(
        () => store.confirmSweep(photoPath: '/tmp/sweep.jpg'),
        throwsA(isA<SafetyViolation>()),
      );
    });

    test('the sweep needs a photo', () async {
      await startMorning();
      store.setAtSchoolGate(true);

      expect(
        () => store.confirmSweep(photoPath: ''),
        throwsA(isA<SafetyViolation>()),
      );
    });

    test('completion is refused while the sweep is pending', () async {
      await startMorning();

      for (final stop in store.session!.stops) {
        await store.reachStop(stop.id);
        for (final child in store.session!.childrenAt(stop.id)) {
          await store.markBoarded(child.id);
        }
      }

      await store.submitHeadCount(store.session!.onBoard);
      await store.confirmDisembark();

      expect(store.session!.unaccounted, isEmpty);
      expect(store.session!.sweepPending, isTrue);

      expect(() => store.completeTrip(), throwsA(isA<SafetyViolation>()));
    });
  });

  /* =================================================================== */
  group('Invariant #4 — an emergency locks child records', () {
    test('no child state change is accepted while an SOS is open', () async {
      await startMorning();
      await store.reachStop('stop-kbr');

      await store.fireSos(SosType.breakdown);

      final child = store.session!.childrenAt('stop-kbr').first;

      expect(() => store.markBoarded(child.id),
          throwsA(isA<SafetyViolation>()));
      expect(child.state, ChildState.expected);

      // Only ops release it — there is no local override in the UI.
      store.releaseSos();
      await store.markBoarded(child.id);
      expect(child.state, ChildState.boarded);
    });

    test('a drill locks records exactly like a real alert', () async {
      await startMorning();
      await store.reachStop('stop-kbr');
      await store.fireSos(SosType.other, drill: true);

      final child = store.session!.childrenAt('stop-kbr').first;

      expect(() => store.markBoarded(child.id),
          throwsA(isA<SafetyViolation>()),
          reason: 'a drill that behaves differently trains the wrong reflex');
    });
  });

  /* =================================================================== */
  group('The role split', () {
    test('a driver device cannot mark a child', () async {
      await store.chooseAssignment(
          DemoData.assignments.firstWhere((a) => a.role == FleetRole.driver));
      await store.openTrip(morningTrip());
      await store.reachStop('stop-kbr');

      final child = store.session!.childrenAt('stop-kbr').first;

      expect(() => store.markBoarded(child.id),
          throwsA(isA<SafetyViolation>()));
      expect(child.state, ChildState.expected);
    });
  });

  /* =================================================================== */
  group('Beginning a trip', () {
    // ⚠ The up-next trip, not the running one. A trip that is already running
    // opens running and its checks are history — see `FleetStore.openTrip`.
    test('the checklist is blocking', () async {
      await store.chooseAssignment(DemoData.assignments
          .firstWhere((a) => a.role == FleetRole.attendant));
      await store.openTrip(afternoonTrip());

      expect(() => store.beginTrip(), throwsA(isA<SafetyViolation>()));

      await store.toggleCheck(0);
      expect(() => store.beginTrip(), throwsA(isA<SafetyViolation>()));

      for (var i = 1; i < store.session!.checklist.length; i++) {
        await store.toggleCheck(i);
      }

      await store.beginTrip();
      expect(store.session!.isRunning, isTrue);
    });

    test('each tick is timestamped, and unticking clears the timestamp', () async {
      await store.chooseAssignment(DemoData.assignments
          .firstWhere((a) => a.role == FleetRole.attendant));
      await store.openTrip(afternoonTrip());

      await store.toggleCheck(0);
      expect(store.session!.checklist[0].tickedAt, isNotNull);

      await store.toggleCheck(0);
      expect(store.session!.checklist[0].tickedAt, isNull,
          reason: 'a timestamp for a check that is not true is a lie');
    });

    test('only one device may start a trip', () async {
      await store.chooseAssignment(DemoData.assignments
          .firstWhere((a) => a.role == FleetRole.attendant));
      await store.openTrip(morningTrip(), startedElsewhere: true);

      for (var i = 0; i < store.session!.checklist.length; i++) {
        await store.toggleCheck(i);
      }

      expect(() => store.beginTrip(), throwsA(isA<SafetyViolation>()));
    });
  });

  /* =================================================================== */
  group('Opening a trip that is already running', () {
    test('it opens running, with its checks recorded as history', () async {
      await store.chooseAssignment(DemoData.assignments
          .firstWhere((a) => a.role == FleetRole.attendant));

      await store.openTrip(morningTrip());

      // ⚠ Sending a crew member back through the checklist for a bus that left
      // twelve minutes ago is how "the bus has left" reaches 22 families twice.
      expect(store.session!.isRunning, isTrue);
      expect(store.session!.allChecksTicked, isTrue);
      expect(store.session!.startedAt, isNotNull);
    });
  });

  /* =================================================================== */
  group('The morning kerb', () {
    test('"not at stop" is locked until the wait expires', () async {
      await startMorning();
      await store.reachStop('stop-kbr');

      final child = store.session!.childrenAt('stop-kbr').first;

      expect(() => store.markNotAtStop(child.id),
          throwsA(isA<SafetyViolation>()));

      final stop = store.session!.stopById('stop-kbr')!;
      stop.reachedAt =
          DateTime.now().subtract(Config.stopWait + const Duration(seconds: 1));

      await store.markNotAtStop(child.id);
      expect(child.state, ChildState.notAtStop);
      expect(child.state.isUnaccounted, isFalse,
          reason: 'a child who never boarded is accounted for, not missing');
    });

    test('the undo window closes', () async {
      await startMorning();
      await store.reachStop('stop-kbr');

      final child = store.session!.childrenAt('stop-kbr').first;
      await store.markBoarded(child.id);

      expect(store.canUndoBoard(child), isTrue);

      child.stateChangedAt =
          DateTime.now().subtract(Config.boardUndo + const Duration(seconds: 1));

      expect(store.canUndoBoard(child), isFalse);

      await store.undoBoard(child.id);
      expect(child.state, ChildState.boarded,
          reason: 'past the window, un-boarding is an audited ops override');
    });

    test('the head count catches a marked child who is not on the bus',
        () async {
      await startMorning();
      await store.reachStop('stop-kbr');

      for (final child in store.session!.childrenAt('stop-kbr')) {
        await store.markBoarded(child.id);
      }

      final marked = store.session!.onBoard;

      expect(await store.submitHeadCount(marked - 1), isFalse);
      expect(store.session!.headCountMatched, isFalse);

      // ⚠ And the trip may not go on to school on a count that does not add up.
      await expectLater(
          store.confirmDisembark(), throwsA(isA<SafetyViolation>()));

      expect(await store.submitHeadCount(marked), isTrue);
      await store.confirmDisembark();
      expect(store.session!.disembarkedAt, isNotNull);
    });
  });

  /* =================================================================== */
  group('The afternoon school gate', () {
    test('lock-in is refused while children are unresolved and time remains',
        () async {
      await startAfternoon();

      expect(store.canLockIn, isFalse);
      expect(() => store.lockInAndDepart(), throwsA(isA<SafetyViolation>()));
    });

    test('lock-in marks the rest absent once every child is resolved',
        () async {
      await startAfternoon();

      final session = store.session!;

      for (final child in session.children) {
        if (child.state == ChildState.expected) {
          await store.togglePmBoarded(child.id);
        }
      }

      expect(store.canLockIn, isTrue);
      expect(await store.lockInAndDepart(), 0);
      expect(session.lockedInAt, isNotNull);
    });

    test('at departure time the un-boarded become absent', () async {
      await store.chooseAssignment(DemoData.assignments
          .firstWhere((a) => a.role == FleetRole.attendant));

      // A trip whose departure time has already passed.
      final due = DutyTrip(
        id: 'trip-pm',
        routeCode: 'Route 12',
        routeName: 'Jubilee Hills loop',
        direction: TripDirection.afternoon,
        bellTier: 'Senior',
        schoolName: 'Silver Oak School',
        scheduledStart: '3:40 PM',
        bellTime: '3:30 PM',
        childCount: 22,
        stopCount: 5,
        status: TripStatus.upNext,
        departsAt: DateTime.now().subtract(const Duration(minutes: 1)),
      );

      await store.openTrip(due);
      for (var i = 0; i < store.session!.checklist.length; i++) {
        await store.toggleCheck(i);
      }
      await store.beginTrip();

      expect(store.canLockIn, isTrue);

      final expected = store.session!.children
          .where((c) => c.state == ChildState.expected)
          .length;

      expect(await store.lockInAndDepart(), expected);
      expect(
        store.session!.children.where((c) => c.state == ChildState.expected),
        isEmpty,
      );
    });
  });
}
