import '../models/duty.dart';
import '../models/trip.dart';

/// The demo roster.
///
/// ⚠ THIS IS NOT PRODUCTION DATA AND IS NOT A FALLBACK. It exists because the
/// fleet API does not: `routes/api.php` carries `/api/parent` only, so there is
/// nowhere for this app to ask "what am I driving today". Everything below is
/// lifted from the design canvas — Route 12, Silver Oak School, the six
/// children at KBR Park Gate, the 4182 handover code — so that what runs on a
/// handset and what is on the canvas can be compared side by side.
///
/// The four stop child-counts in the canvas (4 · 5 · 6 · 7) sum to the 22 it
/// quotes, so the roster below is the canvas's, filled out to the full 22. The
/// named children are the canvas's; the rest are plausible fillers.
///
/// ⚠ No photographs are bundled. The design calls for a photo on every child
/// row and production must have one — but a repository is the wrong place for
/// 22 children's faces, so `photoUrl` is null throughout and the avatar falls
/// back to an initial tile. That fallback is a real production path too: a
/// school that has not uploaded photos yet still has to run buses.
class DemoData {
  static const crewName = 'Ravi Kumar';
  static const crewPhone = '+91 98765 40021';

  /// The code the canvas hands out for the drop-stop keypad.
  ///
  /// ⚠ In production the plaintext never reaches this device. The server holds
  /// a hash, the parent's app shows the plaintext, and the attendant's device
  /// posts what was typed for the server to compare. A 4-digit code sitting in
  /// an APK is a code anyone can read out of an APK.
  static const demoHandoverCode = '4182';

  static const assignments = <CrewAssignment>[
    CrewAssignment(
      role: FleetRole.attendant,
      routeCode: 'Route 12',
      routeName: 'Jubilee Hills loop',
      busRegistration: 'KA 05 MX 2211',
      schoolName: 'Silver Oak School',
      firstBell: '7:40 AM',
      isToday: true,
    ),
    CrewAssignment(
      role: FleetRole.driver,
      routeCode: 'Route 7',
      routeName: 'North loop',
      busRegistration: 'KA 05 KL 8830',
      schoolName: 'Silver Oak School',
      firstBell: '7:40 AM',
    ),
  ];

  /// Today's board for Bus KA 05 MX 2211.
  ///
  /// ⚠ Read-only by design. A trip cannot be started from the duty dashboard —
  /// tapping opens it, and the only way to begin is the pre-trip checklist and
  /// the swipe. An accidental start pushes "the bus has left" to 22 families.
  static List<DutyTrip> duties(DateTime now) => [
        DutyTrip(
          id: 'trip-am',
          routeCode: 'Route 12',
          routeName: 'Jubilee Hills loop',
          direction: TripDirection.morning,
          bellTier: 'Senior',
          schoolName: 'Silver Oak School',
          scheduledStart: '6:58 AM',
          bellTime: '7:40 AM',
          childCount: 22,
          stopCount: 5,
          status: TripStatus.running,
          busRegistration: 'KA 05 MX 2211',
          startedAt: '6:58 AM',
          startedAtTime: now.subtract(const Duration(minutes: 12)),
        ),
        DutyTrip(
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
          busRegistration: 'KA 05 MX 2211',
          // The canvas runs its "Leave in …" chip off a live deadline so the
          // coral flip at two minutes can actually be watched.
          departsAt: now.add(const Duration(minutes: 5, seconds: 41)),
        ),
        const DutyTrip(
          id: 'trip-sports',
          routeCode: 'Route 7',
          routeName: 'Sports pick-up',
          direction: TripDirection.morning,
          bellTier: 'Senior',
          schoolName: 'Silver Oak School',
          scheduledStart: '5:50 AM',
          bellTime: '6:30 AM',
          childCount: 8,
          stopCount: 3,
          status: TripStatus.done,
          startedAt: '5:50 AM',
          note: 'Done 6:15 AM',
        ),
        const DutyTrip(
          id: 'trip-midday',
          routeCode: 'Route 12',
          routeName: 'Midday shuttle',
          direction: TripDirection.afternoon,
          bellTier: 'Primary',
          schoolName: 'Silver Oak School',
          scheduledStart: '12:30 PM',
          bellTime: '12:20 PM',
          childCount: 11,
          stopCount: 4,
          status: TripStatus.moved,
          note: 'Was 12:30 PM · moved to Bus KA 05 JD 4410',
        ),
      ];

  /* ---------------- the route ---------------- */

  /// ⚠ AUTHORED ORDER. These coordinates are the polyline the canvas draws
  /// (Film Nagar → Jubilee Hills → KBR Park → Banjara Hills → Silver Oak). The
  /// list is the driving order and is never sorted by anything else.
  static List<TripStop> morningStops() => [
        TripStop(
          id: 'stop-film-nagar',
          index: 1,
          name: 'Film Nagar',
          scheduledTime: '6:58 AM',
          latitude: 17.4326,
          longitude: 78.4071,
        ),
        TripStop(
          id: 'stop-jubilee',
          index: 2,
          name: 'Jubilee Hills Check Post',
          scheduledTime: '7:04 AM',
          latitude: 17.4239,
          longitude: 78.4128,
        ),
        TripStop(
          id: 'stop-kbr',
          index: 3,
          name: 'KBR Park Gate',
          scheduledTime: '7:11 AM',
          latitude: 17.4162,
          longitude: 78.4295,
        ),
        TripStop(
          id: 'stop-banjara',
          index: 4,
          name: 'Banjara Hills Road 12',
          scheduledTime: '7:18 AM',
          latitude: 17.4108,
          longitude: 78.4294,
        ),
        TripStop(
          id: 'stop-school',
          index: 5,
          name: 'Silver Oak School',
          scheduledTime: '7:35 AM',
          latitude: 17.4062,
          longitude: 78.4394,
          isSchool: true,
        ),
      ];

  /// The afternoon runs the same kerbs in the same authored order, outward from
  /// the school. It is a separate list because the school is the origin, not
  /// the terminus, and because the times come from a different solve.
  static List<TripStop> afternoonStops() => [
        TripStop(
          id: 'stop-school',
          index: 1,
          name: 'Silver Oak School',
          scheduledTime: '3:40 PM',
          latitude: 17.4062,
          longitude: 78.4394,
          isSchool: true,
        ),
        TripStop(
          id: 'stop-banjara',
          index: 2,
          name: 'Banjara Hills Road 12',
          scheduledTime: '4:02 PM',
          latitude: 17.4108,
          longitude: 78.4294,
        ),
        TripStop(
          id: 'stop-kbr',
          index: 3,
          name: 'KBR Park Gate',
          scheduledTime: '4:12 PM',
          latitude: 17.4162,
          longitude: 78.4295,
        ),
        TripStop(
          id: 'stop-jubilee',
          index: 4,
          name: 'Jubilee Hills Check Post',
          scheduledTime: '4:21 PM',
          latitude: 17.4239,
          longitude: 78.4128,
        ),
        TripStop(
          id: 'stop-film-nagar',
          index: 5,
          name: 'Film Nagar',
          scheduledTime: '4:30 PM',
          latitude: 17.4326,
          longitude: 78.4071,
        ),
      ];

  /* ---------------- the children ---------------- */

  static const _aaravPeople = <AuthorizedPerson>[
    AuthorizedPerson(name: 'Priya Mehta', relationship: 'Mother'),
    AuthorizedPerson(name: 'Rohan Mehta', relationship: 'Father'),
    AuthorizedPerson(name: 'Sunita Mehta', relationship: 'Grandmother'),
    AuthorizedPerson(name: 'Lakshmi Devi', relationship: 'Nanny'),
  ];

  static const _zoyaPeople = <AuthorizedPerson>[
    AuthorizedPerson(name: 'Nadia Khan', relationship: 'Mother'),
    AuthorizedPerson(name: 'Imran Khan', relationship: 'Father'),
  ];

  /// The full 22, in stop order.
  ///
  /// ⚠ Every row carries a `distinguishingDetail`. Aarav and Anaya Mehta are
  /// the case this exists for: same surname, adjacent rows, similar faces at
  /// 56dp. Their tones differ and their details name each other.
  static List<TripChild> roster() => [
        // ── Film Nagar · 4 ────────────────────────────────────────────────
        TripChild(
          id: 'c01',
          name: 'Aditi Rao',
          className: 'Class 5B',
          stopId: 'stop-film-nagar',
          distinguishingDetail: 'Purple backpack',
          tone: AvatarTone.teal,
          maySelfRelease: true,
        ),
        TripChild(
          id: 'c02',
          name: 'Kabir Rao',
          className: 'Class 3B',
          stopId: 'stop-film-nagar',
          distinguishingDetail: "Aditi's brother · red cap",
          tone: AvatarTone.coral,
        ),
        TripChild(
          id: 'c03',
          name: 'Meera Nair',
          className: 'Class 2A',
          stopId: 'stop-film-nagar',
          distinguishingDetail: 'Two plaits, blue ribbons',
          tone: AvatarTone.amber,
        ),
        TripChild(
          id: 'c04',
          name: 'Rehan Ali',
          className: 'Class 7A',
          stopId: 'stop-film-nagar',
          distinguishingDetail: 'Tallest at this stop',
          tone: AvatarTone.teal,
          maySelfRelease: true,
        ),

        // ── Jubilee Hills Check Post · 5 ──────────────────────────────────
        TripChild(
          id: 'c05',
          name: 'Sara Fernandes',
          className: 'Class 4A',
          stopId: 'stop-jubilee',
          distinguishingDetail: 'Glasses, green bottle',
          tone: AvatarTone.coral,
        ),
        TripChild(
          id: 'c06',
          name: 'Advait Joshi',
          className: 'Class 6C',
          stopId: 'stop-jubilee',
          distinguishingDetail: 'Cricket bag',
          tone: AvatarTone.teal,
          maySelfRelease: true,
        ),
        TripChild(
          id: 'c07',
          name: 'Nithya Menon',
          className: 'Class 1B',
          stopId: 'stop-jubilee',
          distinguishingDetail: 'Yellow lunchbox',
          tone: AvatarTone.amber,
        ),
        TripChild(
          id: 'c08',
          name: 'Yash Patel',
          className: 'Class 3A',
          stopId: 'stop-jubilee',
          distinguishingDetail: 'Orange shoes',
          tone: AvatarTone.coral,
        ),
        TripChild(
          id: 'c09',
          name: 'Tara Sinha',
          className: 'Class 5A',
          stopId: 'stop-jubilee',
          distinguishingDetail: 'Short hair, silver watch',
          tone: AvatarTone.teal,
        ),

        // ── KBR Park Gate · 6 — the canvas's stop ─────────────────────────
        TripChild(
          id: 'c10',
          name: 'Vihaan Reddy',
          className: 'Class 2A',
          stopId: 'stop-kbr',
          distinguishingDetail: 'Green water bottle',
          tone: AvatarTone.teal,
        ),
        TripChild(
          id: 'c11',
          name: 'Zoya Khan',
          className: 'Class 4C',
          stopId: 'stop-kbr',
          distinguishingDetail: 'Yellow raincoat',
          tone: AvatarTone.amber,
          // Signed consent on file and above the school's minimum grade.
          maySelfRelease: true,
          authorizedPeople: _zoyaPeople,
        ),
        TripChild(
          id: 'c12',
          name: 'Aarav Mehta',
          className: 'Class 3B',
          stopId: 'stop-kbr',
          distinguishingDetail: 'Blue name tag',
          tone: AvatarTone.teal,
          // ⚠ No consent on file — Self-release is not rendered for him at all,
          // not rendered-and-disabled. A greyed-out control invites a long
          // press; an absent one does not exist to be worked around.
          maySelfRelease: false,
          authorizedPeople: _aaravPeople,
        ),
        TripChild(
          id: 'c13',
          name: 'Anaya Mehta',
          className: 'Class 1A',
          stopId: 'stop-kbr',
          distinguishingDetail: "Red name tag · Aarav's sister",
          tone: AvatarTone.coral,
          authorizedPeople: _aaravPeople,
        ),
        TripChild(
          id: 'c14',
          name: 'Ishaan Gupta',
          className: 'Class 3B',
          stopId: 'stop-kbr',
          distinguishingDetail: 'Wears glasses',
          tone: AvatarTone.coral,
        ),
        TripChild(
          id: 'c15',
          name: 'Diya Sharma',
          className: 'UKG',
          stopId: 'stop-kbr',
          distinguishingDetail: 'Youngest at this stop',
          tone: AvatarTone.amber,
        ),

        // ── Banjara Hills Road 12 · 7 ─────────────────────────────────────
        TripChild(
          id: 'c16',
          name: 'Arjun Iyer',
          className: 'UKG',
          stopId: 'stop-banjara',
          distinguishingDetail: 'Dinosaur backpack',
          tone: AvatarTone.teal,
        ),
        TripChild(
          id: 'c17',
          name: 'Riya Bose',
          className: 'Class 1A',
          stopId: 'stop-banjara',
          distinguishingDetail: 'Pink hairband',
          tone: AvatarTone.coral,
        ),
        TripChild(
          id: 'c18',
          name: 'Neel Kapoor',
          className: 'Class 8B',
          stopId: 'stop-banjara',
          distinguishingDetail: 'Headphones round neck',
          tone: AvatarTone.amber,
          maySelfRelease: true,
        ),
        TripChild(
          id: 'c19',
          name: 'Ira Deshpande',
          className: 'Class 2C',
          stopId: 'stop-banjara',
          distinguishingDetail: 'Red spectacles',
          tone: AvatarTone.teal,
        ),
        TripChild(
          id: 'c20',
          name: 'Samar Qureshi',
          className: 'Class 6A',
          stopId: 'stop-banjara',
          distinguishingDetail: 'Football under arm',
          tone: AvatarTone.coral,
          maySelfRelease: true,
        ),
        TripChild(
          id: 'c21',
          name: 'Leela Krishnan',
          className: 'Class 4B',
          stopId: 'stop-banjara',
          distinguishingDetail: 'Blue tiffin bag',
          tone: AvatarTone.amber,
        ),
        TripChild(
          id: 'c22',
          name: 'Dhruv Shetty',
          className: 'Class 3C',
          stopId: 'stop-banjara',
          distinguishingDetail: 'Plaster on left knee',
          tone: AvatarTone.teal,
        ),
      ];

  /// Stable keys for the four checks.
  ///
  /// ⚠ THE KEY IS THE IDENTITY, NOT THE LABEL. The server stores one timestamp
  /// per key, so re-wording "First aid box present" must not orphan every tick
  /// already in a trip record. Add to the end; never renumber.
  static const checklistKeys = ['clean', 'first_aid', 'extinguisher', 'exit'];

  /// The four pre-trip checks, in the order the canvas lists them.
  static List<ChecklistItem> checklist() => [
        ChecklistItem('Vehicle clean and seats intact', 'Walk the aisle once'),
        ChecklistItem('First aid box present', 'Under the front seat'),
        ChecklistItem('Fire extinguisher present', 'Charged, pin in place'),
        ChecklistItem('Emergency exit clear', 'Nothing stacked against it'),
      ];
}
