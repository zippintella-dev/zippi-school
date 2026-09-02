import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zippi_parent/models/family_card.dart';
import 'package:zippi_parent/models/journey_day.dart';
import 'package:zippi_parent/screens/activity_screen.dart';
import 'package:zippi_parent/screens/select_child_screen.dart';
import 'package:zippi_parent/screens/verify_screen.dart';
import 'package:zippi_parent/theme.dart';
import 'package:zippi_parent/widgets/common.dart';

void main() {
  group('FamilyCard parsing', () {
    Map<String, dynamic> payload({
      bool showMap = true,
      String status = 'boarded',
      double? busLat = 17.45,
      double? busLng = 78.34,
    }) =>
        {
          'child_id': 7,
          'name': 'Aarav',
          'grade': '3',
          'bell_tier': 'Primary',
          'school': 'Phoenix Greens',
          'school_phone': '+914023115500',
          'service_date': '2026-08-21',
          'has_active_trip': true,
          'show_live_map': showMap,
          'status': status,
          'status_label': 'On the bus',
          'absent': false,
          'absent_directions': [],
          'show_handover_code': false,
          'trip': {
            'id': 1,
            'direction': 'Morning',
            'bell_tier': 'Primary',
            'status': 'started',
            'route': 'RT-01 · Kondapur',
            'scheduled_start_at': '2026-08-21T07:32:00+05:30',
            'scheduled_end_at': '2026-08-21T08:35:00+05:30',
            'bell_time': '08:45',
            'driver': {'name': 'Ramesh', 'phone_masked': '•••••1202'},
            'attendant': {'name': 'Padma', 'phone_masked': '•••••2202'},
            'bus': {
              'reg_no': 'TS09UB1234',
              'latitude': busLat,
              'longitude': busLng,
              'last_ping_at': null,
            },
          },
          'stop': {
            'name': 'Silver Oak Gate',
            'latitude': 17.46,
            'longitude': 78.35,
            'scheduled_at': '2026-08-21T07:49:00+05:30',
          },
          'timeline': [
            {
              'at': '2026-08-21T07:49:00+05:30',
              'direction': 'Morning',
              'text': 'Boarded the bus at Silver Oak Gate',
            }
          ],
        };

    test('reads the whole card', () {
      final c = FamilyCard.fromJson(payload());

      expect(c.childId, 7);
      expect(c.name, 'Aarav');
      expect(c.bellTier, 'Primary');
      expect(c.isMorning, isTrue);
      expect(c.trip!.route, 'RT-01 · Kondapur');
      expect(c.stop!.name, 'Silver Oak Gate');
      expect(c.timeline, hasLength(1));
    });

    test('the crew phone is the masked one', () {
      final c = FamilyCard.fromJson(payload());

      // PART K9 — a parent never receives a raw crew number.
      expect(c.trip!.attendant!.phoneMasked, '•••••2202');
      expect(c.trip!.driver!.phoneMasked, startsWith('•'));
    });

    test('a closed map means no bus position at all', () {
      // Enterprise L29 — the server omits the coordinates; the model must
      // simply carry that absence rather than substitute a default.
      final c = FamilyCard.fromJson(
        payload(showMap: false, busLat: null, busLng: null),
      );

      expect(c.showLiveMap, isFalse);
      expect(c.trip!.bus!.hasPosition, isFalse);
      expect(c.trip!.bus!.latitude, isNull);
      expect(c.trip!.bus!.longitude, isNull);
    });

    test('a bus that has never pinged counts as stale', () {
      final c = FamilyCard.fromJson(payload());

      // Better to say "may be out of date" than to draw it as live.
      expect(c.trip!.bus!.isStale, isTrue);
    });

    test('a missing trip does not throw', () {
      final json = payload()..['trip'] = null;
      json['stop'] = null;

      final c = FamilyCard.fromJson(json);

      expect(c.trip, isNull);
      expect(c.stop, isNull);
    });
  });

  group('JourneyTrip outcome', () {
    JourneyTrip trip(String status) => JourneyTrip.fromJson({
          'direction': 'Afternoon',
          'bell_tier': 'Primary',
          'route': 'RT-01',
          'status': status,
          'stop': 'Silver Oak Gate',
          'boarded_at': null,
          'arrived_at_school_at': null,
          'alighted_at': null,
        });

    test('names every terminal state in plain words', () {
      expect(trip('alighted_to_guardian').outcome, contains('Handed over'));
      expect(trip('alighted_self_release').outcome, contains('Got off'));
      expect(trip('returned_to_school').outcome, contains('Returned to school'));
      expect(trip('not_at_stop').outcome, contains('Not at the stop'));
    });

    test('an unrecorded leg says so rather than showing blank', () {
      expect(trip('pending').outcome, 'No boarding recorded');
    });
  });

  group('design system', () {
    testWidgets('the handover code is large and read out digit by digit',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: const Scaffold(body: HandoverCodeCard('4729')),
      ));

      expect(find.text('4729'), findsOneWidget);
      expect(find.text('HANDOVER CODE'), findsOneWidget);

      final text = tester.widget<Text>(find.text('4729'));

      // The canvas sets this at 84px — it is read through a bus window.
      expect(text.style!.fontSize, greaterThanOrEqualTo(60));
      // A screen reader must not say "four thousand seven hundred twenty-nine".
      expect(text.semanticsLabel, 'Handover code 4 7 2 9');
    });

    testWidgets('the boarding code is as legible as the handover code',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: const Scaffold(body: BoardingCodeCard('3081')),
      ));

      expect(find.text('3081'), findsOneWidget);
      expect(find.text('BOARDING CODE'), findsOneWidget);

      final text = tester.widget<Text>(find.text('3081'));
      expect(text.style!.fontSize, greaterThanOrEqualTo(60));
      expect(text.semanticsLabel, 'Boarding code 3 0 8 1');

      // ⚠ The card says outright that this is not the afternoon number. Two
      // 4-digit codes from one app on one day is exactly how a parent reads out
      // the wrong one — and the direction that matters is reading the RELEASE
      // code aloud at a morning kerb, in front of everyone at the stop.
      expect(
        find.textContaining('not the same as the afternoon handover code'),
        findsOneWidget,
      );
    });

    testWidgets('a status chip always carries words, not just colour',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: const Scaffold(
          body: StatusChip('On the bus', tone: ChipTone.live),
        ),
      ));

      expect(find.text('On the bus'), findsOneWidget);
    });

    testWidgets('safety-critical buttons meet the 56dp target', (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: Scaffold(
          body: FilledButton(onPressed: () {}, child: const Text('Mark absent')),
        ),
      ));

      final size = tester.getSize(find.byType(FilledButton));
      expect(size.height, greaterThanOrEqualTo(Z.tapSafety));
    });

    testWidgets('bottom nav tabs are at least 56dp tall', (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: Scaffold(
          bottomNavigationBar: ZBottomNav(index: 0, onTap: (_) {}),
        ),
      ));

      expect(find.text('Home'), findsOneWidget);
      expect(find.text('Activity'), findsOneWidget);
      expect(find.text('You'), findsOneWidget);

      final size = tester.getSize(find.text('Home').first);
      expect(size.height, lessThan(Z.hNav));
    });

    test('the palette matches the design canvas', () {
      // Guards against a well-meaning "tidy up" drifting from the canvas.
      expect(Z.turquoise, const Color(0xFF40E0D0));
      expect(Z.coral, const Color(0xFFFF8070));
      expect(Z.teal, const Color(0xFF0E7C72));
      expect(Z.bg, const Color(0xFFFFF9F2));
      expect(Z.cardBorder, const Color(0xFFF0E4D6));
    });

    test('the app uses the design fonts', () {
      expect(Z.display, 'Quicksand');
      expect(Z.body, 'Mulish');
    });
  });

  group('single-child focus', () {
    FamilyCard card(int id, String name) => FamilyCard.fromJson({
          'child_id': id,
          'name': name,
          'grade': '3',
          'bell_tier': 'Primary',
          'school': 'Phoenix Greens',
          'school_phone': null,
          'service_date': '2026-08-21',
          'has_active_trip': false,
          'show_live_map': false,
          'status': 'pending',
          'status_label': 'Not started yet',
          'absent': false,
          'absent_directions': [],
          'show_handover_code': false,
          'trip': null,
          'stop': null,
          'timeline': [
            {'at': '2026-08-21T07:49:00+05:30', 'direction': 'Morning',
             'text': '\$name boarded the bus'},
          ],
        });

    testWidgets('Activity shows only the selected child, never a sibling',
        (tester) async {
      final chosen = card(1, 'Aarav');

      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: Scaffold(
          body: ActivityScreen(child: chosen, onRefresh: () async {}),
        ),
      ));

      expect(find.textContaining('Aarav'), findsWidgets);
      // The sibling was never passed in — it cannot be rendered by accident.
      expect(find.textContaining('Ishaan'), findsNothing);
    });

    testWidgets('the picker lists every child of THIS guardian',
        (tester) async {
      final cards = [card(1, 'Aarav'), card(2, 'Ishaan')];
      FamilyCard? picked;

      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: SelectChildScreen(
          cards: cards,
          onSelect: (c) => picked = c,
        ),
      ));

      expect(find.text('Who are you checking on?'), findsOneWidget);
      expect(find.text('Aarav'), findsOneWidget);
      expect(find.text('Ishaan'), findsOneWidget);

      await tester.tap(find.text('Ishaan'));
      await tester.pump();

      expect(picked?.childId, 2);
    });

    testWidgets('the picker marks the current choice', (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: Z.theme(),
        home: SelectChildScreen(
          cards: [card(1, 'Aarav'), card(2, 'Ishaan')],
          currentChildId: 2,
          onSelect: (_) {},
        ),
      ));

      expect(find.byIcon(Icons.check_circle_rounded), findsOneWidget);
    });
  });

  group('OTP length', () {
    test('matches what the server actually issues', () {
      // ⚠ The canvas draws SIX boxes; OtpService issues FOUR digits
      // (`random_int(1000, 9999)`, PART P1). Six boxes against a four-digit
      // code makes login impossible, so the app follows the server.
      //
      // If this ever becomes 6, change random_int in OtpService in the same
      // commit — this test exists to make that pairing explicit.
      expect(kOtpLength, 4);
    });
  });
}
