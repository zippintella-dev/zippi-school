/// Who is holding the phone, and what they are holding it for.
///
/// ⚠ ONE APP, TWO ROLE MODES — and the split is a safety control, not a
/// convenience. A driver marking children is a driver looking at a phone while
/// children board around a moving vehicle. [FleetRole.driver] renders no
/// child-marking control anywhere; see `screens/driver_screen.dart`.
enum FleetRole {
  attendant,
  driver;

  String get label => switch (this) {
        FleetRole.attendant => 'Attendant',
        FleetRole.driver => 'Driver',
      };

  /// The one authorization question this enum exists to answer.
  bool get canMarkChildren => this == FleetRole.attendant;
}

/// PART L1 — home→school or school→home. Never both, never a loop.
enum TripDirection {
  morning,
  afternoon;

  String get label => switch (this) {
        TripDirection.morning => 'Morning pick-up',
        TripDirection.afternoon => 'Afternoon drop',
      };

  bool get isMorning => this == TripDirection.morning;
}

/// The state of a trip on the duty dashboard.
///
/// Opacity and pill text come straight from the design's status table. Every
/// one carries a word as well as a shade — a 0.45-opacity card in sunlight is
/// just a card.
enum TripStatus {
  running,
  upNext,
  done,
  cancelled,
  moved;

  String get pill => switch (this) {
        TripStatus.running => 'Now running',
        TripStatus.upNext => 'Up next',
        TripStatus.done => 'Done',
        TripStatus.cancelled => 'Cancelled',
        TripStatus.moved => 'Moved',
      };

  double get opacity => switch (this) {
        TripStatus.running => 1.0,
        TripStatus.upNext => 0.65,
        _ => 0.45,
      };

  /// Only a scheduled trip can be opened and begun. A done or moved trip is a
  /// record, not a thing to walk into.
  bool get openable => this == TripStatus.running || this == TripStatus.upNext;
}

/// One scheduled run of one route, one direction, one date, one bell tier.
///
/// ⚠ `bellTier` is the school-side name for what both specs call `trip_leg`.
/// If a fleet API is ever written, map it back at the boundary (see CLAUDE.md)
/// rather than renaming it here.
class DutyTrip {
  final String id;
  final String routeCode;
  final String routeName;
  final TripDirection direction;
  final String bellTier;
  final String schoolName;

  /// Wall-clock strings as the server renders them. The app never re-derives a
  /// schedule locally — a phone with a wrong clock must not move a bell time.
  final String scheduledStart;
  final String bellTime;

  final int childCount;
  final int stopCount;
  final TripStatus status;

  /// Populated for [TripStatus.running] and [TripStatus.done].
  final String? startedAt;

  /// The same moment as [startedAt], as an instant rather than a label.
  ///
  /// ⚠ Two fields on purpose. [startedAt] is what the SERVER decided to show —
  /// rendered in the school's timezone, which is not necessarily the handset's.
  /// This one is for arithmetic the app has to do locally (how long the trip
  /// has been running). Never format this one for display; a phone whose clock
  /// or timezone is wrong would move a time the whole crew is working from.
  final DateTime? startedAtTime;

  /// Why this trip is not happening on this bus — "moved to Bus KA 05 JD 4410".
  final String? note;

  /// When the bus is due to leave. Drives the "Leave in 18 min" / "Leave now"
  /// chip, which turns coral at two minutes.
  final DateTime? departsAt;

  /// ⚠ The vehicle is a property of the TRIP, not of the crew member. A driver
  /// moved to another bus at 6 AM is on a different bus for that trip only, and
  /// a registration remembered from the role would be yesterday's.
  final String busRegistration;

  const DutyTrip({
    required this.id,
    required this.routeCode,
    required this.routeName,
    required this.direction,
    required this.bellTier,
    required this.schoolName,
    required this.scheduledStart,
    required this.bellTime,
    required this.childCount,
    required this.stopCount,
    required this.status,
    this.startedAt,
    this.startedAtTime,
    this.note,
    this.departsAt,
    this.busRegistration = '',
  });

  /// From `GET /api/fleet/duties`.
  ///
  /// ⚠ The server sends BOTH `bell_tier` and `trip_leg` — the same value under
  /// the codebase's name and the spec's. Read `bell_tier`; `trip_leg` exists so
  /// the PART M3 contract still matches at the boundary (see CLAUDE.md). Do not
  /// reintroduce the old name inside this app.
  factory DutyTrip.fromJson(Map<String, dynamic> json) {
    final direction = (json['direction'] as String?) == 'Afternoon'
        ? TripDirection.afternoon
        : TripDirection.morning;

    return DutyTrip(
      id: '${json['id']}',
      routeCode: json['route_code'] as String? ?? '',
      routeName: json['route_name'] as String? ?? '',
      direction: direction,
      bellTier: json['bell_tier'] as String? ?? '',
      schoolName: json['school_name'] as String? ?? '',
      scheduledStart: json['scheduled_start_label'] as String? ?? '—',
      bellTime: json['bell_time'] as String? ?? '—',
      childCount: (json['child_count'] as num?)?.toInt() ?? 0,
      stopCount: (json['stop_count'] as num?)?.toInt() ?? 0,
      status: _status(json['status'] as String?),
      startedAt: json['started_at_label'] as String?,
      startedAtTime: _dt(json['started_at']),
      note: json['note'] as String?,
      // The chip counts down to the scheduled departure.
      departsAt: _dt(json['scheduled_start_at']),
      busRegistration:
          (json['bus'] as Map<String, dynamic>?)?['reg_no'] as String? ?? '',
    );
  }

  static TripStatus _status(String? raw) => switch (raw) {
        'started' => TripStatus.running,
        'scheduled' => TripStatus.upNext,
        'completed' => TripStatus.done,
        'cancelled' => TripStatus.cancelled,
        'transferred' => TripStatus.moved,
        // ⚠ An unknown status is NOT openable. A build that predates a new
        // server status must not let a crew member walk into a trip it does
        // not understand — it shows it as a record and gets out of the way.
        _ => TripStatus.moved,
      };

  static DateTime? _dt(Object? raw) =>
      raw is String ? DateTime.tryParse(raw)?.toLocal() : null;

  String get title => '$routeCode · ${direction.label}';

  String get subtitle => direction.isMorning
      ? 'Home → $schoolName · First bell $bellTime'
      : '$schoolName → Home · Last bell $bellTime';

  /// "1 child" / "22 children". A crew member reading "1 children" on a screen
  /// that is otherwise carefully worded starts wondering what else is sloppy.
  String get childLabel => '$childCount ${childCount == 1 ? 'child' : 'children'}';

  /// What a FINISHED trip says. It has to carry the direction and the tier,
  /// because those are the only things separating two runs of the same route.
  ///
  /// ⚠ This used to be '$routeCode · $routeName', which is identical for every
  /// completed trip on a bus. One bus runs both directions and 2–3 tiers per
  /// direction, so a full day collapsed to six byte-identical rows and an
  /// attendant checking "did I close the Middle afternoon run?" had nothing to
  /// read. The route NAME moved to the detail line, where it is context rather
  /// than identity.
  String get recordTitle => '$routeCode · ${direction.label} · $bellTier';
}

/// A role the signed-in person may take today.
///
/// One phone can be an attendant on one bus and a driver on another — and a
/// parent besides. PART P6 calls for one identity with N role links; this is
/// the app-side shape of one of those links.
class CrewAssignment {
  final FleetRole role;
  final String routeCode;
  final String routeName;
  final String busRegistration;
  final String schoolName;
  final String firstBell;

  /// True for the assignment the roster says is theirs today. Shown
  /// pre-selected so the common case is one tap.
  final bool isToday;

  /// `school_staff.id` — the row this link is.
  final int? staffId;

  /// ⚠ THE BEARER TOKEN FOR THIS ROLE, AND THIS ROLE ONLY (PART P6).
  ///
  /// The server mints one token per role link, so a token identifies a ROLE
  /// rather than a person. That is what makes the driver/attendant split
  /// unspoofable: there is no role field in any request for a client to claim,
  /// and picking a card here is literally picking which token gets sent.
  final String? token;

  const CrewAssignment({
    required this.role,
    required this.routeCode,
    required this.routeName,
    required this.busRegistration,
    required this.schoolName,
    required this.firstBell,
    this.isToday = false,
    this.staffId,
    this.token,
  });

  /// From a `roles` entry of `POST /api/fleet/otp/verify`.
  ///
  /// ⚠ The route and bus are NOT on a role link — they are properties of
  /// today's trips, which the duty board fetches once a role is chosen. A
  /// driver moved to another bus at 6 AM would otherwise see yesterday's
  /// vehicle on the picker.
  factory CrewAssignment.fromJson(Map<String, dynamic> json) => CrewAssignment(
        role: json['role'] == 'driver' ? FleetRole.driver : FleetRole.attendant,
        routeCode: '',
        routeName: '',
        busRegistration: '',
        schoolName: (json['school'] as Map<String, dynamic>?)?['name'] as String? ?? '',
        firstBell: '',
        staffId: (json['staff_id'] as num?)?.toInt(),
        token: json['token'] as String?,
      );

  /// Back to the server's own shape, so a restored session re-reads through
  /// [CrewAssignment.fromJson] — one parser, not two that can disagree.
  ///
  /// ⚠ This is written to the KEYCHAIN, token included. The links are the only
  /// record of which roles this phone signed in for, and they must survive a
  /// relaunch: without them the app has a token and no idea what it is for.
  Map<String, dynamic> toJson() => {
        'staff_id': staffId,
        'role': role == FleetRole.driver ? 'driver' : 'attendant',
        'school': {'name': schoolName},
        'token': token,
      };
}
