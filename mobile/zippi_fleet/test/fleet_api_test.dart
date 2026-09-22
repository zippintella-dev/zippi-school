import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zippi_fleet/models/duty.dart';
import 'package:zippi_fleet/models/trip.dart';
import 'package:zippi_fleet/services/fleet_api.dart';
import 'package:zippi_fleet/services/fleet_store.dart';

/// The app against the real API's payloads.
///
/// ⚠ THE FIXTURES BELOW ARE THE SERVER'S ACTUAL SHAPE — copied from
/// `FleetApiController::tripCard()` / `childRow()`. If the Laravel side changes
/// a key, this file is where the app finds out, rather than a crew member at a
/// kerb finding out that every child now renders as "Not boarded".
///
/// The other half of the contract lives in `tests/Feature/FleetApiTest.php`.
void main() {
  /* ---------------- fixtures ---------------- */

  Map<String, dynamic> tripPayload({
    String status = 'started',
    bool sosLocked = false,
    List<Map<String, dynamic>>? children,
  }) =>
      {
        'id': 12,
        'route_code': 'RT-01',
        'route_name': 'Kondapur – Botanical Garden',
        'direction': 'Afternoon',
        'bell_tier': 'Primary',
        'trip_leg': 'Primary',
        'school_name': 'Phoenix Greens International School',
        'status': status,
        'status_label': 'Now running',
        'child_count': 2,
        'stop_count': 2,
        'scheduled_start_at': '2026-08-24T15:40:00+05:30',
        'scheduled_start_label': '3:40 PM',
        'bell_time': '15:30',
        'started_at': '2026-08-24T15:41:02+05:30',
        'started_at_label': '3:41 PM',
        'started_by': 'Suresh Kumar',
        'bus': {'id': 3, 'reg_no': 'TS09UB1234'},
        'driver': {'name': 'Suresh Kumar', 'phone_masked': '•••••1201'},
        'attendant': {'name': 'Lakshmi Devi', 'phone_masked': '•••••2201'},
        'pretrip_checklist': [
          {'key': 'clean', 'label': 'Vehicle clean', 'ticked_at': '2026-08-24T15:38:00+05:30'},
          {'key': 'first_aid', 'label': 'First aid', 'ticked_at': '2026-08-24T15:38:20+05:30'},
        ],
        'headcount_reported': null,
        'headcount_verified': false,
        'arrived_at_school_at': null,
        'roster_locked_at': null,
        'sweep_verified_at': null,
        'completed_at': null,
        'sos_locked': sosLocked,
        'stops': [
          {
            'stop_id': 101,
            'sequence': 1,
            'name': 'Silver Oak School',
            'scheduled_at': '2026-08-24T15:40:00+05:30',
            'latitude': 17.4062,
            'longitude': 78.4394,
            'child_count': 2,
            'reached_at': '2026-08-24T15:41:00+05:30',
            'departed_at': null,
            'is_school': true,
          },
          {
            'stop_id': 102,
            'sequence': 2,
            'name': 'KBR Park Gate',
            'scheduled_at': '2026-08-24T16:12:00+05:30',
            'latitude': 17.4162,
            'longitude': 78.4295,
            'child_count': 2,
            'reached_at': null,
            'departed_at': null,
            'is_school': false,
          },
        ],
        'children': children ??
            [
              {
                'child_id': 7,
                'name': 'Aarav Mehta',
                'class': '3-B',
                'detail': 'Blue name tag',
                'photo_url': null,
                'stop_id': 102,
                'stop_name': 'KBR Park Gate',
                'status': 'boarded',
                'status_label': 'On board',
                'boarded_at': '2026-08-24T15:45:00+05:30',
                'alighted_at': null,
                'escalation_started_at': null,
                'note': null,
                'medical_notes': 'Peanut allergy — EpiPen in bag',
                'may_self_release': false,
                'authorized_receivers': [
                  {
                    'id': 55,
                    'name': 'Sunita Mehta',
                    'relationship': 'grandparent',
                    'photo_url': null,
                  },
                ],
              },
              {
                'child_id': 8,
                'name': 'Diya Sharma',
                'class': 'UKG',
                'detail': 'Youngest at this stop',
                'photo_url': null,
                'stop_id': 102,
                'stop_name': 'KBR Park Gate',
                'status': 'parent_collecting',
                'status_label': 'Parent collecting',
                'boarded_at': null,
                'alighted_at': null,
                'escalation_started_at': null,
                'note': 'Parent collecting — mother, approved 1:10 PM',
                'medical_notes': null,
                'may_self_release': true,
                'authorized_receivers': const [],
              },
            ],
      };

  http.Response ok(Map<String, dynamic> body) =>
      http.Response(jsonEncode(body), 200,
          headers: {'content-type': 'application/json'});

  http.Response fail(int code, String message, {List<int>? childIds}) =>
      http.Response(
          jsonEncode({
            'status': false,
            'message': message,
            if (childIds != null) 'child_ids': childIds,
          }),
          code,
          headers: {'content-type': 'application/json'});

  FleetStore storeWith(MockClient client) => FleetStore(
        api: FleetApi(client: client, token: 'test-token'),
      );

  DutyTrip theTrip() => DutyTrip.fromJson(tripPayload());

  /* ================================================================= */
  group('Parsing the server payload', () {
    test('a trip becomes a session with stops in authored order', () async {
      final store = storeWith(MockClient((_) async =>
          ok({'status': true, 'trip': tripPayload()})));

      await store.openTrip(theTrip());

      final s = store.session!;

      expect(s.trip.id, '12');
      expect(s.trip.direction, TripDirection.afternoon);
      expect(s.trip.busRegistration, 'TS09UB1234');
      expect(s.isRunning, isTrue);

      // ⚠ Authored order, exactly as sent. Never re-sorted client-side.
      expect(s.stops.map((x) => x.name),
          ['Silver Oak School', 'KBR Park Gate']);
      expect(s.stops.first.isSchool, isTrue);
      expect(s.stops.first.reached, isTrue);
      expect(s.nextStop!.name, 'KBR Park Gate');
    });

    test('the checklist is restored by KEY, not by label', () async {
      final store = storeWith(MockClient((_) async =>
          ok({'status': true, 'trip': tripPayload()})));

      await store.openTrip(theTrip());

      // Two of four ticked server-side; the labels come from the school's list.
      expect(store.session!.tickedCount, 2);
      expect(store.session!.allChecksTicked, isFalse);
      expect(store.session!.checklist[0].tickedAt, isNotNull);
      expect(store.session!.checklist[3].tickedAt, isNull);
    });

    test('child state, self-release and siblings survive the round trip',
        () async {
      final store = storeWith(MockClient((_) async =>
          ok({'status': true, 'trip': tripPayload()})));

      await store.openTrip(theTrip());

      final aarav = store.session!.childById('7')!;
      final diya = store.session!.childById('8')!;

      expect(aarav.state, ChildState.boarded);
      expect(aarav.distinguishingDetail, 'Blue name tag',
          reason: 'the anti-mis-tap line must not be dropped in parsing');
      expect(aarav.medicalNotes, isNotNull);

      // ⚠ ONE BOOLEAN FROM THE SERVER. Never re-derived from the class here.
      expect(aarav.maySelfRelease, isFalse);
      expect(diya.maySelfRelease, isTrue);

      expect(aarav.authorizedPeople.single.id, 55);

      // ⚠ `parent_collecting` is a display status the server computes; the app
      // renders it and never sends it back.
      expect(diya.state, ChildState.parentCollecting);
      expect(diya.note, contains('Parent collecting'));

      // Adjacent rows never share an avatar tint.
      expect(aarav.tone, isNot(diya.tone));
    });

    test('an unknown status counts as unaccounted, not as resolved', () async {
      final store = storeWith(MockClient((_) async => ok({
            'status': true,
            'trip': tripPayload(children: [
              {
                'child_id': 9,
                'name': 'Future State',
                'class': '4-A',
                'stop_id': 102,
                // A status this build has never heard of.
                'status': 'teleported_home',
                'may_self_release': false,
                'authorized_receivers': const [],
              },
            ]),
          })));

      await store.openTrip(theTrip());

      // ⚠ Fails towards "this child still needs attention". A build that
      // predates a new server state must not quietly treat it as resolved.
      expect(store.session!.childById('9')!.state, ChildState.expected);
      expect(store.session!.unaccounted, isNotEmpty);
    });
  });

  /* ================================================================= */
  group('The error contract', () {
    test("a 422 becomes a SafetyViolation carrying the server's sentence",
        () async {
      final store = storeWith(MockClient((request) async {
        if (request.method == 'GET') {
          return ok({'status': true, 'trip': tripPayload()});
        }

        return fail(
          422,
          'Cannot complete: Aarav Mehta is still on board. Every child must be '
          'handed over, absent, or returned to school before the trip can close.',
          childIds: [7],
        );
      }));

      await store.openTrip(theTrip());

      // Resolve the local check so the request actually goes out.
      store.session!.sweptAt = DateTime.now();
      for (final c in store.session!.children) {
        c.state = ChildState.handedOver;
      }

      SafetyViolation? thrown;

      try {
        await store.completeTrip();
      } on SafetyViolation catch (e) {
        thrown = e;
      }

      expect(thrown, isNotNull);
      expect(thrown!.message, contains('Aarav Mehta'),
          reason: 'the server names children; the app must not paraphrase');
      expect(thrown.childIds, ['7']);
    });

    test('a 423 SOS lock is a refusal, and a 500 is not', () async {
      var status = 423;

      final store = storeWith(MockClient((request) async {
        if (request.method == 'GET') {
          return ok({'status': true, 'trip': tripPayload()});
        }
        return fail(status, 'Something happened.');
      }));

      await store.openTrip(theTrip());
      store.session!.childById('7')!.state = ChildState.expected;

      await expectLater(
          store.markBoarded('7'), throwsA(isA<SafetyViolation>()));

      // ⚠ A CRASHED SERVER HAS NOT DECIDED ANYTHING. Presenting a 500 as a
      // safety refusal would teach the crew that refusals are noise.
      status = 500;
      store.session!.childById('7')!.state = ChildState.expected;

      await expectLater(
          store.markBoarded('7'), throwsA(isA<FleetTransportException>()));
    });

    test('a refused boarding rolls the optimistic green row back', () async {
      final store = storeWith(MockClient((request) async {
        if (request.method == 'GET') {
          return ok({'status': true, 'trip': tripPayload()});
        }
        return fail(423, 'Child records are locked while the SOS is open.');
      }));

      await store.openTrip(theTrip());
      final child = store.session!.childById('7')!..state = ChildState.expected;

      await expectLater(
          store.markBoarded('7'), throwsA(isA<SafetyViolation>()));

      // ⚠ A row left green after the server said no is a child the attendant
      // believes is aboard and who is not.
      expect(child.state, ChildState.expected);
      expect(child.stateChangedAt, isNull);
    });
  });

  /* ================================================================= */
  group('What the app sends', () {
    test('a handover POSTs the code and never holds one', () async {
      Map<String, dynamic>? sent;
      String? sentPath;

      final store = storeWith(MockClient((request) async {
        if (request.method == 'GET') {
          return ok({'status': true, 'trip': tripPayload()});
        }

        sentPath = request.url.path;
        sent = jsonDecode(request.body) as Map<String, dynamic>;

        return ok({'status': true, 'trip': tripPayload()});
      }));

      await store.openTrip(theTrip());

      await store.recordHandover(
        childId: '7',
        method: HandoverMethod.code,
        code: '4182',
      );

      expect(sentPath, endsWith('/trips/12/children/7/handover'));
      expect(sent!['method'], 'handover_code');
      expect(sent!['code'], '4182');

      // ⚠ And the GET payload it came from never contained a code to compare
      // against — the server holds a hash.
      final body = jsonEncode(tripPayload());
      expect(body, isNot(contains('handover_code')));
      expect(body, isNot(contains('4182')));
    });

    test('boarding sends the client time but the server orders the record',
        () async {
      Map<String, dynamic>? sent;

      final store = storeWith(MockClient((request) async {
        if (request.method == 'GET') {
          return ok({'status': true, 'trip': tripPayload()});
        }
        sent = jsonDecode(request.body) as Map<String, dynamic>;
        return ok({'status': true, 'trip': tripPayload()});
      }));

      await store.openTrip(theTrip());
      store.session!.childById('7')!.state = ChildState.expected;

      await store.markBoarded('7');

      // ⚠ Sent for the record, never trusted for ordering (PART L4). A handset
      // with a wrong clock must not be able to reorder a custody record.
      expect(sent!['client_reported_at'], isNotNull);
    });

    test('a driver token is refused before the request is even sent', () async {
      var calls = 0;

      final store = FleetStore(
        api: FleetApi(
          client: MockClient((request) async {
            if (request.method == 'GET') {
              return ok({'status': true, 'trip': tripPayload()});
            }
            calls++;
            return ok({'status': true, 'trip': tripPayload()});
          }),
          token: 'driver-token',
        ),
      );

      await store.chooseAssignment(const CrewAssignment(
        role: FleetRole.driver,
        routeCode: '',
        routeName: '',
        busRegistration: '',
        schoolName: '',
        firstBell: '',
      ));

      await store.openTrip(theTrip());

      await expectLater(
          store.markBoarded('7'), throwsA(isA<SafetyViolation>()));

      // ⚠ The server refuses this too (FleetApiTest asserts that). Refusing it
      // here as well is what gives the crew an instant answer instead of a
      // round trip — and means a driver device never even asks.
      expect(calls, 0);
    });

    test('the SOS lock from the server locks the app', () async {
      final store = storeWith(MockClient((_) async =>
          ok({'status': true, 'trip': tripPayload(sosLocked: true)})));

      await store.openTrip(theTrip());

      expect(store.session!.sosLocked, isTrue);
      expect(store.sos, isNotNull,
          reason: 'the app mirrors the lock so it can say WHY it is refusing');

      store.session!.childById('7')!.state = ChildState.expected;

      await expectLater(
          store.markBoarded('7'), throwsA(isA<SafetyViolation>()));
    });
  });

  /* ================================================================= */
  group('Role links', () {
    test('a live session with no links is EMPTY, never the demo fixtures',
        () async {
      final store = storeWith(MockClient((_) async => ok({'status': true})));

      // Exactly what the restore path does: a name and a token off the
      // keychain, and no links.
      store.signIn(name: 'Sunitha Reddy', phoneNumber: '+919848012203',
          token: 'restored-token');

      // ⚠ THE BUG THIS PINS. This used to hand back DemoData.assignments —
      // Route 12 and Route 7 at "Silver Oak School", neither of which exists in
      // any real database. A crew member relaunching the app was offered a
      // DRIVER card while holding an ATTENDANT's token, and picking it sets
      // `role`, which decides whether this device renders any child-marking
      // control at all.
      expect(store.roleLinks, isEmpty);
      expect(store.roleLinks.map((l) => l.schoolName), isNot(contains('Silver Oak School')));
    });

    test('a restored link keeps its role, school and token', () async {
      final store = storeWith(MockClient((_) async => ok({'status': true})));

      final saved = CrewAssignment.fromJson({
        'staff_id': 9,
        'role': 'attendant',
        'school': {'name': 'Phoenix Greens International School'},
        'token': 'attendant-token',
      });

      // Round-trips through the keychain's own encoding.
      final reread = CrewAssignment.fromJson(saved.toJson());

      store.signIn(name: 'Sunitha Reddy', phoneNumber: '+919848012203',
          links: [reread]);

      expect(store.roleLinks, hasLength(1));
      expect(store.roleLinks.single.role, FleetRole.attendant);
      expect(store.roleLinks.single.staffId, 9);
      expect(store.roleLinks.single.token, 'attendant-token');
      expect(store.roleLinks.single.schoolName,
          'Phoenix Greens International School');

      // ⚠ A real role link carries NO route or bus — those belong to today's
      // trips. A card showing "Route 7 · North loop" is a fabricated card.
      expect(store.roleLinks.single.routeCode, isEmpty);
      expect(store.roleLinks.single.busRegistration, isEmpty);
    });

    test('demo mode still gets the fixtures — they are its only data', () {
      final store = FleetStore();     // no api == demo mode

      store.signIn(name: 'Ravi Kumar', phoneNumber: '9999999999');

      expect(store.roleLinks, isNotEmpty);
    });
  });

  /* ================================================================= */
  group('Offline', () {
    test('a dead zone is reported as a dead zone, not as a refusal', () async {
      final store = storeWith(MockClient((request) async {
        if (request.method == 'GET') {
          return ok({'status': true, 'trip': tripPayload()});
        }
        throw http.ClientException('connection reset');
      }));

      await store.openTrip(theTrip());
      store.session!.childById('7')!.state = ChildState.expected;

      await expectLater(
          store.markBoarded('7'), throwsA(isA<FleetTransportException>()));

      // ⚠ The banner says how many writes are waiting. "Everything saved" when
      // nothing did is the one reassurance this app must never give.
      expect(store.offline, isTrue);
      expect(store.queuedEvents, 1);
    });

    test('a board that could not be read is NOT a board with no trips on it',
        () async {
      final store = storeWith(MockClient((_) async {
        throw http.ClientException('connection reset');
      }));

      // ⚠ It must not throw. This runs from a button press and from
      // pull-to-refresh, where a thrown exception reaches nobody — which is
      // how the crew ended up reading an empty board with no explanation.
      await store.refreshDuties();

      expect(store.duties, isEmpty);
      expect(store.offline, isTrue);

      // ⚠ THE WHOLE POINT. Empty and unread are different states, and only one
      // of them may be shown as "this vehicle has nothing scheduled". A crew
      // member told that about a bus that has a run on it does not board 22
      // children.
      expect(store.dutiesLoaded, isFalse);
      expect(store.dutiesError, isNotNull);

      // A failed READ has nothing to sync. Counting it told the crew "1 update
      // will sync when you are back" about an update that never existed.
      expect(store.queuedEvents, 0);
    });

    test('an answered board is loaded even when the school has no trips',
        () async {
      final store = storeWith(
          MockClient((_) async => ok({'status': true, 'trips': const []})));

      await store.refreshDuties();

      expect(store.duties, isEmpty);
      expect(store.dutiesLoaded, isTrue,
          reason: 'the server answered — "no trips" is now a fact, not a guess');
      expect(store.dutiesError, isNull);
      expect(store.offline, isFalse);
    });

    test('a failed refresh does not take the board away', () async {
      var fail = false;

      final store = storeWith(MockClient((_) async {
        if (fail) throw http.ClientException('connection reset');
        return ok({
          'status': true,
          'trips': [tripPayload()],
        });
      }));

      await store.refreshDuties();
      expect(store.duties, hasLength(1));

      fail = true;
      await store.refreshDuties();

      // ⚠ The day's work stays on screen; the offline strip says why it is
      // old. Taking the board away because one poll missed is worse than
      // showing one that is a few minutes behind.
      expect(store.duties, hasLength(1));
      expect(store.dutiesError, isNotNull);
      expect(store.offline, isTrue);
    });
  });
}
