import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';

import '../config.dart';
import '../models/duty.dart';
import '../models/sos.dart';
import '../models/trip.dart';
import 'auth_store.dart';
import 'demo_data.dart';
import 'fleet_api.dart';
import 'location_service.dart';

/// Thrown when an action would break one of the four safety invariants.
///
/// ⚠ ONE EXCEPTION TYPE FOR BOTH MODES, AND THAT IS THE POINT. Offline it is
/// raised by the local rules below; online it is raised by [FleetApi] carrying
/// the SERVER's sentence verbatim. Screens catch one thing and render one
/// thing, so a refusal looks identical whichever decided it — and nobody is
/// tempted to write a second, friendlier message for the local case.
///
/// ⚠ The message must say WHY and WHAT NEXT, and must name children rather
/// than count them. "Cannot complete: Aarav Mehta is still on board" is a
/// person somebody goes and finds; "1 child unaccounted" is a number somebody
/// dismisses.
class SafetyViolation implements Exception {
  final String message;

  /// Children the refusal is about, so the UI can offer a route straight to
  /// them. Ids rather than objects, because the server sends ids.
  final List<String> childIds;

  const SafetyViolation(this.message, {this.childIds = const []});

  @override
  String toString() => message;
}

/// One trip, loaded and in progress.
class TripSession {
  final DutyTrip trip;
  final List<TripStop> stops;
  final List<TripChild> children;
  final List<ChecklistItem> checklist;

  DateTime? startedAt;

  /// Which stop the attendant currently has open. Set on geo-fence entry.
  String? currentStopId;

  /// PART F2 head-count reconciliation, morning only.
  int? headCountEntered;
  bool? headCountMatched;

  DateTime? disembarkedAt;

  /// Afternoon: the moment the roster was frozen and the bus left the school.
  DateTime? lockedInAt;

  /// Invariant #3's gate. True only when the bus is physically at the school.
  bool atSchoolGate = false;

  /// How far off the gate the bus is, for the blocked state's copy.
  double metresFromSchool = 1200;

  DateTime? sweptAt;
  String? sweepPhotoPath;

  DateTime? completedAt;

  /// ⚠ Invariant #4 — an open SOS on this trip freezes every child state
  /// change until ops close it. The server refuses regardless; this is so the
  /// app can SAY so rather than just failing.
  bool sosLocked = false;

  /// The already-running-elsewhere state: only one device may start a trip.
  final bool startedElsewhere;
  final String? startedElsewhereBy;

  TripSession({
    required this.trip,
    required this.stops,
    required this.children,
    required this.checklist,
    this.startedAt,
    this.currentStopId,
    this.startedElsewhere = false,
    this.startedElsewhereBy,
  });

  bool get isRunning => startedAt != null;

  bool get allChecksTicked => checklist.every((c) => c.ticked);

  int get tickedCount => checklist.where((c) => c.ticked).length;

  TripStop? stopById(String id) {
    for (final s in stops) {
      if (s.id == id) return s;
    }
    return null;
  }

  TripChild? childById(String id) {
    for (final c in children) {
      if (c.id == id) return c;
    }
    return null;
  }

  /// ⚠ Authored order, filtered — never re-sorted.
  List<TripChild> childrenAt(String stopId) =>
      children.where((c) => c.stopId == stopId).toList();

  /// The first stop the bus has not reached. Carries the NEXT pill.
  TripStop? get nextStop {
    for (final s in stops) {
      if (!s.reached) return s;
    }
    return null;
  }

  bool get allStopsDone => stops.every((s) => s.reached);

  int get onBoard =>
      children.where((c) => c.state == ChildState.boarded).length;

  int get boardedEver => children
      .where((c) => const [
            ChildState.boarded,
            ChildState.arrivedAtSchool,
            ChildState.handedOver,
            ChildState.returnedToSchool,
          ].contains(c.state))
      .length;

  int get handedOverCount =>
      children.where((c) => c.state == ChildState.handedOver).length;

  int get absentCount => children
      .where((c) => const [
            ChildState.absent,
            ChildState.notAtStop,
            ChildState.parentCollecting,
          ].contains(c.state))
      .length;

  int get returnedCount =>
      children.where((c) => c.state == ChildState.returnedToSchool).length;

  /// Invariant #2, asked locally.
  List<TripChild> get unaccounted =>
      children.where((c) => c.state.isUnaccounted).toList();

  /// Invariant #3.
  bool get sweepPending => sweptAt == null;

  /// Rebuilds a session from `GET /api/fleet/trips/{trip}`.
  ///
  /// ⚠ EVERY MUTATION REPLACES THE WHOLE SESSION FROM THIS. The app never
  /// patches its own copy and hopes: a handset that was offline, or is running
  /// an old build, converges on the server's answer at the next successful
  /// call. That is what makes "the server is the authority" true in the running
  /// app rather than only in the comments.
  factory TripSession.fromJson(Map<String, dynamic> json) {
    final stopsJson = (json['stops'] as List? ?? const []);
    final childrenJson = (json['children'] as List? ?? const []);

    final stops = <TripStop>[
      for (var i = 0; i < stopsJson.length; i++)
        TripStop.fromJson(stopsJson[i] as Map<String, dynamic>, index: i),
    ];

    final children = <TripChild>[
      for (var i = 0; i < childrenJson.length; i++)
        TripChild.fromJson(childrenJson[i] as Map<String, dynamic>, index: i),
    ];

    // The server sends the ticks it has; the labels come from the school's
    // standard list so an older trip still renders four readable rows.
    final ticked = <String, DateTime?>{
      for (final item in (json['pretrip_checklist'] as List? ?? const []))
        (item as Map<String, dynamic>)['key'] as String:
            DateTime.tryParse('${item['ticked_at']}')?.toLocal(),
    };

    final checklist = DemoData.checklist();
    for (var i = 0; i < checklist.length; i++) {
      final key = DemoData.checklistKeys[i];
      if (ticked.containsKey(key)) {
        checklist[i].ticked = true;
        checklist[i].tickedAt = ticked[key];
      }
    }

    return TripSession(
      trip: DutyTrip.fromJson(json),
      stops: stops,
      children: children,
      checklist: checklist,
      startedAt: _dt(json['started_at']),
      startedElsewhereBy: json['started_by'] as String?,
    )
      ..headCountEntered = (json['headcount_reported'] as num?)?.toInt()
      ..headCountMatched = json['headcount_verified'] == true
          ? true
          : (json['headcount_reported'] == null ? null : false)
      ..disembarkedAt = _dt(json['arrived_at_school_at'])
      ..lockedInAt = _dt(json['roster_locked_at'])
      ..sweptAt = _dt(json['sweep_verified_at'])
      ..completedAt = _dt(json['completed_at'])
      ..sosLocked = json['sos_locked'] == true;
  }

  static DateTime? _dt(Object? raw) =>
      raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
}

/// The whole app's state, and — offline — the place every safety rule is kept.
///
/// ⚠ TWO MODES, ONE SET OF RULES.
///
///   · **Live** ([api] non-null): every mutation is a call to `/api/fleet`, and
///     the session is REPLACED from the server's reply. The server decides; the
///     local checks below only stop an obviously-doomed request from being
///     sent, so the crew are told before the round trip rather than after it.
///   · **Demo** ([api] null): the rules below ARE the decision, because there
///     is nothing else. For a walkthrough with no backend.
///
/// ⚠ The local rules are never a substitute for the server's. A phone can be
/// old, offline, rooted, or running a build from March; the server re-decides
/// everything, and its refusal is what the crew sees.
class FleetStore extends ChangeNotifier {
  /// Where the session is persisted between launches. Optional so tests can
  /// exercise the safety rules without touching the Keychain.
  final AuthStore? auth;

  /// Null in demo mode. See the class comment.
  final FleetApi? api;

  /// Where the bus is. See [LocationService] — three safety behaviours read it.
  final LocationService location;

  FleetStore({this.auth, this.api, LocationService? location})
      : location = location ?? LocationService();

  /// Pushes the vehicle's position while a trip is running.
  StreamSubscription<Object>? _positionSub;

  bool get isLive => api != null;

  /// The vehicle to show in a header.
  ///
  /// ⚠ Prefers the OPEN TRIP's bus, then today's board, and only then the role
  /// link. A crew member moved to another vehicle mid-day must see the bus they
  /// are actually on — the one whose children they are about to mark.
  String get busLabel =>
      session?.trip.busRegistration.isNotEmpty == true
          ? session!.trip.busRegistration
          : (duties
                  .map((t) => t.busRegistration)
                  .firstWhere((r) => r.isNotEmpty, orElse: () => '')
                  .isNotEmpty
              ? duties.firstWhere((t) => t.busRegistration.isNotEmpty).busRegistration
              : (assignment?.busRegistration ?? ''));

  /* ---------------- session ---------------- */

  String? crewName;
  String? phone;
  CrewAssignment? assignment;

  /// PART P6 — every role this phone number owns, each with its own token.
  ///
  /// ⚠ Populated by sign-in, not by a guess. One phone can be an attendant at
  /// one school and a driver at another; the picker exists because the app
  /// must not choose for them.
  List<CrewAssignment> roleLinks = const [];

  FleetRole get role => assignment?.role ?? FleetRole.attendant;

  List<DutyTrip> duties = const [];

  /// When the board was last read from the server successfully.
  ///
  /// ⚠ NULL MEANS "NEVER LOADED", AND THAT IS NOT THE SAME AS "NO TRIPS". An
  /// empty [duties] on its own cannot tell the two apart, and the difference is
  /// the whole message: "this vehicle has nothing scheduled, call the office"
  /// versus "I could not ask". The first, shown when the second is true, sends
  /// a crew member home from a bus that has a run on it.
  DateTime? dutiesCheckedAt;

  /// Why the last attempt to read the board failed, in the sentence the crew
  /// should see. Null once a read succeeds.
  String? dutiesError;

  bool get dutiesLoaded => dutiesCheckedAt != null;

  TripSession? session;

  /// The open emergency, if any.
  SosAlert? sos;

  /// True while a call is in flight, so screens can disable a control rather
  /// than let it be tapped twice.
  bool busy = false;

  /// Connectivity, as the app believes it.
  ///
  /// The app keeps working offline and this drives an informational banner,
  /// never a block. An attendant told "no connection" mid-boarding stops
  /// boarding, and that is worse than a late sync.
  bool offline = false;

  /// Writes that failed on the wire and have not reached the server.
  ///
  /// ⚠ COUNTED, NOT SILENTLY DISCARDED. The banner says how many are waiting,
  /// because "everything saved" when nothing did is the one reassurance this
  /// app must never give.
  int queuedEvents = 0;

  /* ---------------- sign in ---------------- */

  void signIn({
    required String name,
    required String phoneNumber,
    String? token,
    List<CrewAssignment> links = const [],
  }) {
    crewName = name;
    phone = phoneNumber;

    // ⚠ NEVER SUBSTITUTE THE WALKTHROUGH FIXTURES IN A LIVE SESSION. This read
    // `links.isEmpty ? DemoData.assignments : links`, unconditionally — and the
    // restore path passes no links, so every relaunch of a real install offered
    // the crew Route 12 and Route 7 at Silver Oak School: a school, two routes
    // and two buses that exist only in `demo_data.dart`.
    //
    // It is worse than a cosmetic wrong label. Picking a fabricated card sets
    // `assignment.role`, and that decides whether this device renders any
    // child-marking control at all — while the bearer token in hand belongs to
    // whichever staff row the crew member actually signed in as. An attendant
    // who picked the invented "Driver" card got an app that refused to let them
    // mark a child, on a bus where they are the only person who can.
    //
    // In demo mode there is no server to ask, and the fixtures ARE the data.
    roleLinks = links.isNotEmpty ? links : (isLive ? const [] : DemoData.assignments);

    if (token != null) api?.token = token;

    // Fire-and-forget: a failed keychain write costs one extra sign-in
    // tomorrow, and blocking the crew on it at 6:40 AM costs a bus.
    auth?.save(phone: phoneNumber, name: name, token: token);
    auth?.saveLinks(roleLinks);

    notifyListeners();
  }

  Future<void> chooseAssignment(CrewAssignment a) async {
    assignment = a;
    session = null;

    // ⚠ THE TOKEN *IS* THE ROLE (PART P6). Selecting an assignment swaps the
    // bearer token to the one minted for that staff row, which is why
    // `canMarkChildren` cannot be spoofed by a client: there is no role field
    // in any request for it to claim.
    if (a.token != null) api?.token = a.token;
    auth?.saveRole(a);

    notifyListeners();

    await refreshDuties();
  }

  /// Today's board. Safe to call repeatedly — it is the pull-to-refresh path.
  ///
  /// ⚠ IT DOES NOT THROW, AND IT DOES NOT CLEAR A BOARD IT ALREADY HAS. A
  /// failure is recorded in [dutiesError] for the screen to render, because
  /// this is called from a button press and from pull-to-refresh, where a
  /// thrown transport exception reaches nobody — it was swallowed by the
  /// framework, and the crew were left reading an empty board with no
  /// explanation on it.
  Future<void> refreshDuties() async {
    if (!isLive) {
      duties = DemoData.duties(DateTime.now());
      dutiesCheckedAt = DateTime.now();
      dutiesError = null;
      notifyListeners();
      return;
    }

    try {
      await _guarded(() async {
        final json = await api!.duties();
        duties = ((json['trips'] as List?) ?? const [])
            .map((t) => DutyTrip.fromJson(t as Map<String, dynamic>))
            .toList();
        dutiesCheckedAt = DateTime.now();
        dutiesError = null;
      }, read: true);
    } on FleetTransportException catch (e) {
      // ⚠ NOT the transport's own sentence when the wire is down. That one is
      // written for a write — "carry on, this will sync when you are back" —
      // and a board that never loaded has nothing to sync and nothing to carry
      // on with. Everything else (a 502, an HTML error page) keeps its message,
      // because it names something the crew or ops can act on.
      dutiesError = e.isOffline
          ? 'No connection, so today\'s trips have not loaded. This is not '
              '"no trips" — it is "not known yet". Try again when you have '
              'signal.'
          : e.message;
    } on SafetyViolation catch (e) {
      // A 4xx on a *read* is not a safety refusal about a child — it is an
      // expired token or a bad request. Show its sentence rather than a
      // blocking sheet about a rule nobody broke.
      dutiesError = e.message;
    }

    notifyListeners();
  }

  /// Back to the role picker — a crew member moved to another bus mid-day, or
  /// who picked the wrong card.
  ///
  /// ⚠ Drops the loaded trip with it. Carrying a Route 12 attendant session
  /// into a Route 7 driver session is how a child gets marked on the wrong bus.
  void clearAssignment() {
    assignment = null;
    session = null;
    duties = const [];
    // The board is not "loaded and empty" for the next role — it is unread.
    dutiesCheckedAt = null;
    dutiesError = null;
    notifyListeners();
  }

  void signOut() {
    if (isLive) {
      // Best effort. A revoke that fails must not trap somebody in the app.
      api!.logout().catchError((Object _) {});
      api!.token = null;
    }

    auth?.clear();

    crewName = null;
    phone = null;
    assignment = null;
    roleLinks = const [];
    duties = const [];
    dutiesCheckedAt = null;
    dutiesError = null;
    session = null;
    sos = null;

    notifyListeners();
  }

  /* ---------------- opening a trip ---------------- */

  /// Loads a trip. Does NOT start it — that is the checklist and the swipe.
  Future<void> openTrip(DutyTrip trip, {bool startedElsewhere = false}) async {
    if (!isLive) {
      _openDemoTrip(trip, startedElsewhere: startedElsewhere);
      return;
    }

    await _guarded(() async => _applyTrip(await api!.trip(trip.id)),
        read: true);

    // Joining a trip somebody else started — the second device pushes too.
    if (session?.isRunning ?? false) unawaited(startPositionPushing());
  }

  /// Re-reads the trip from the server. The stop list polls this, so a boarding
  /// marked on the attendant's phone shows up on the driver's.
  Future<void> refreshTrip() async {
    final id = session?.trip.id;
    if (!isLive || id == null) return;

    await _guarded(() async => _applyTrip(await api!.trip(id)),
        quiet: true, read: true);
  }

  void _applyTrip(Map<String, dynamic> json) {
    final payload = (json['trip'] as Map<String, dynamic>?) ?? json;
    final previous = session;

    session = TripSession.fromJson(payload);

    // Geo-fence belief is device state, not server state; carry it across a
    // refresh so the sweep screen does not re-lock itself at the gate.
    if (previous != null) {
      session!
        ..atSchoolGate = previous.atSchoolGate
        ..metresFromSchool = previous.metresFromSchool
        ..currentStopId = previous.currentStopId
        ..sweepPhotoPath = previous.sweepPhotoPath;
    }

    // Invariant #4 — mirror the server's lock so the SOS screen and the child
    // rows agree without a second round trip.
    if (session!.sosLocked && sos == null) {
      sos = SosAlert(type: SosType.other, firedAt: DateTime.now());
    } else if (!session!.sosLocked) {
      sos = null;
    }
  }

  void _openDemoTrip(DutyTrip trip, {required bool startedElsewhere}) {
    final morning = trip.direction.isMorning;

    session = TripSession(
      trip: trip,
      stops: morning ? DemoData.morningStops() : DemoData.afternoonStops(),
      children: DemoData.roster(),
      checklist: DemoData.checklist(),
      startedElsewhere: startedElsewhere,
      startedElsewhereBy: startedElsewhere ? 'Suresh (driver)' : null,
    );

    // ⚠ A trip that is ALREADY RUNNING opens running. Sending a crew member
    // back through the pre-trip checklist for a bus that left twelve minutes
    // ago is how a trip gets started a second time — and "the bus has left"
    // pushed to 22 families twice.
    if (trip.status == TripStatus.running && !startedElsewhere) {
      final s = session!;
      s.startedAt = trip.startedAtTime ?? DateTime.now();

      for (final item in s.checklist) {
        item.ticked = true;
        item.tickedAt = s.startedAt;
      }
    }

    if (!morning) _seedAfternoonBoard();

    notifyListeners();
  }

  /// The afternoon starts at the school with a roster the office has already
  /// annotated: one child collected by a parent, one absent since morning.
  void _seedAfternoonBoard() {
    final s = session!;

    s.childById('c15')
      ?..state = ChildState.parentCollecting
      ..stateChangedAt = DateTime.now()
      ..note = 'Parent collecting — mother, approved 1:10 PM';

    s.childById('c02')
      ?..state = ChildState.absent
      ..stateChangedAt = DateTime.now()
      ..note = 'Absent — fever, parent informed 8:40 AM';

    s.stops.first.reachedAt = DateTime.now();
    s.currentStopId = s.stops.first.id;
  }

  void closeTrip() {
    stopPositionPushing();
    session = null;
    notifyListeners();
  }

  /* ---------------- guards ---------------- */

  /// ⚠ THE ROLE GATE. A driver device renders no child-marking control at all,
  /// and this is the second half of that. The server enforces it again — this
  /// only saves a doomed round trip and gives the crew an instant answer.
  void _guardRole() {
    if (!role.canMarkChildren) {
      throw const SafetyViolation(
        'Driver devices cannot mark children. Hand this to the attendant.',
      );
    }
  }

  /// ⚠ THE SOS LOCK. See [SosAlert].
  void _guardSos() {
    if (sos != null) {
      throw const SafetyViolation(
        'Child records are locked while the SOS is open. '
        'Ops release the lock once they have resolved it.',
      );
    }
  }

  /// Runs a call, tracks connectivity, and lets a [SafetyViolation] through.
  ///
  /// ⚠ A TRANSPORT FAILURE IS NOT A REFUSAL. A dead zone means "I could not
  /// ask"; it must never be presented as "the rules say no", and it must never
  /// be swallowed into a success. It flips the offline banner and counts the
  /// write, and the caller's optimistic UI does not advance.
  ///
  /// ⚠ [read] MARKS A CALL THAT CARRIES NOTHING TO SYNC. The queued count is a
  /// count of *writes* the server has not got. A failed board or trip fetch
  /// used to increment it too, so a crew member who opened the app in a dead
  /// zone was told "1 update will sync when you are back" about an update that
  /// did not exist — and a counter that invents work is a counter nobody
  /// believes on the morning it is counting a real boarding.
  Future<T?> _guarded<T>(
    Future<T> Function() body, {
    bool quiet = false,
    bool read = false,
  }) async {
    if (!quiet) {
      busy = true;
      notifyListeners();
    }

    try {
      final result = await body();

      if (offline) {
        offline = false;
        queuedEvents = 0;
      }

      return result;
    } on FleetTransportException catch (e) {
      if (e.isOffline) {
        offline = true;
        if (!read) queuedEvents++;
      }

      if (!quiet) rethrow;
      return null;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  /* ---------------- 4 · pre-trip checklist ---------------- */

  Future<void> toggleCheck(int index) async {
    final s = session;
    if (s == null || s.isRunning) return;

    final item = s.checklist[index];
    item.ticked = !item.ticked;
    // Each tick is timestamped into the trip record. Untick clears it — a
    // timestamp for a check that is not currently true is a lie in an audit.
    item.tickedAt = item.ticked ? DateTime.now() : null;

    notifyListeners();

    if (!isLive) return;

    // ⚠ Only ticked items are sent, and the SERVER stamps the time. A phone
    // with a wrong clock must not be able to backdate a safety check.
    final ticked = <Map<String, String>>[
      for (var i = 0; i < s.checklist.length; i++)
        if (s.checklist[i].ticked)
          {'key': DemoData.checklistKeys[i], 'label': s.checklist[i].label},
    ];

    if (ticked.isEmpty) return;

    await _guarded(() => api!.checklist(s.trip.id, ticked), quiet: true);
  }

  /// ⚠ Reached only by the swipe widget, past 85% travel.
  Future<void> beginTrip() async {
    final s = session;
    if (s == null) return;

    if (s.startedElsewhere) {
      throw SafetyViolation(
        'This trip is already running on ${s.startedElsewhereBy}. '
        'Open it to join, or call ops if that looks wrong.',
      );
    }

    if (!s.allChecksTicked) {
      throw const SafetyViolation(
        'Finish the pre-trip checklist before the bus moves.',
      );
    }

    if (!isLive) {
      s.startedAt = DateTime.now();
      notifyListeners();
      return;
    }

    await _guarded(() async => _applyTrip(await api!.start(s.trip.id)));

    // ⚠ The bus starts reporting the moment it starts moving. 22 families were
    // just told "the bus has left"; a map that does not move for the next ten
    // minutes reads as a bus that has not.
    unawaited(startPositionPushing());
  }

  /* ---------------- 5 · stops ---------------- */

  Future<void> reachStop(String stopId) async {
    final s = session;
    if (s == null) return;

    if (!isLive) {
      final stop = s.stopById(stopId);
      if (stop == null) return;

      stop.reachedAt ??= DateTime.now();
      s.currentStopId = stopId;

      if (stop.isSchool) {
        s.atSchoolGate = true;
        s.metresFromSchool = 0;
      }

      notifyListeners();
      return;
    }

    s.currentStopId = stopId;

    if (s.stopById(stopId)?.isSchool ?? false) {
      s.atSchoolGate = true;
      s.metresFromSchool = 0;
    }

    await _guarded(
      () async => _applyTrip(await api!.reachStop(s.trip.id, stopId)),
      quiet: true,
    );
  }

  /// ⚠ INVARIANT #1 AT A KERB. A stop cannot be departed while a child there is
  /// unresolved, and the two directions fail differently — morning on a child
  /// who never boarded, afternoon on one who is still aboard.
  Future<void> departStop(String stopId) async {
    final s = session;
    if (s == null) return;

    final morning = s.trip.direction.isMorning;

    final open = s.childrenAt(stopId).where((c) {
      return morning
          ? c.state == ChildState.expected
          : c.state == ChildState.boarded;
    }).toList();

    if (open.isNotEmpty) {
      final what = morning ? 'boarded' : 'been handed over';

      throw SafetyViolation(
        'Cannot depart: ${_nameList(open)} '
        '${open.length == 1 ? 'has' : 'have'} not $what.',
        childIds: open.map((c) => c.id).toList(),
      );
    }

    if (!isLive) {
      s.stopById(stopId)?.departedAt = DateTime.now();
      notifyListeners();
      return;
    }

    await _guarded(
      () async => _applyTrip(await api!.departStop(s.trip.id, stopId)),
    );
  }

  /* ---------------- 6 · marking children ---------------- */

  /// PART A7 (extended) — [method] says how the guardian was verified at the
  /// kerb, and [code] carries the 4 digits when it was the boarding code.
  ///
  /// ⚠ THE CODE IS SENT, NOT COMPARED HERE. The server holds the hash and counts
  /// the attempts. A plaintext comparison in an app is a formality that anyone
  /// with the APK skips, and the whole point of the check is that it holds
  /// against a phone running a build from March.
  ///
  /// ⚠ Defaults to [BoardingMethod.rosterPhoto] rather than requiring a code.
  /// A morning boarding must never be blocked by this layer — see the long note
  /// on FleetTripService::verifyBoarding for why refusing at a kerb in the
  /// morning is not the safe direction to be wrong.
  Future<void> markBoarded(
    String childId, {
    BoardingMethod method = BoardingMethod.rosterPhoto,
    String? code,
  }) async {
    _guardRole();
    _guardSos();

    final s = session;
    final child = s?.childById(childId);
    if (s == null || child == null || child.state != ChildState.expected) return;

    if (!isLive) {
      child.state = ChildState.boarded;
      child.boardingMethod = method;
      child.stateChangedAt = DateTime.now();
      notifyListeners();
      return;
    }

    // ⚠ OPTIMISTIC, AND ROLLED BACK ON REFUSAL. The row turns green under the
    // thumb and the server's answer replaces it — 22 children in 90 seconds
    // cannot wait for a round trip each. But a row left green after the server
    // said no is a child the attendant believes is aboard and who is not, so
    // the catch below is not optional.
    child.state = ChildState.boarded;
    child.boardingMethod = method;
    child.stateChangedAt = DateTime.now();
    notifyListeners();

    try {
      _applyTrip(await api!.board(
        s.trip.id,
        childId,
        method: method.wire,
        code: code,
        clientReportedAt: DateTime.now(),
        offline: offline,
      ));
    } on SafetyViolation {
      child.state = ChildState.expected;
      child.boardingMethod = null;
      child.stateChangedAt = null;
      notifyListeners();
      rethrow;
    } on FleetTransportException catch (e) {
      child.state = ChildState.expected;
      child.boardingMethod = null;
      child.stateChangedAt = null;

      if (e.isOffline) {
        offline = true;
        queuedEvents++;
      }

      notifyListeners();
      rethrow;
    }
  }

  /// The 90-second mis-tap window.
  ///
  /// ⚠ A window, not a toggle. Once it closes, un-boarding is an ops override
  /// with an audit entry — after 90 seconds the bus has moved and "this child
  /// is not on board" is a different claim entirely.
  bool canUndoBoard(TripChild child) =>
      child.state == ChildState.boarded &&
      child.stateChangedAt != null &&
      undoRemaining(child) > Duration.zero;

  Duration undoRemaining(TripChild child) {
    final at = child.stateChangedAt;
    if (at == null) return Duration.zero;

    final left = at.add(Config.boardUndo).difference(DateTime.now());
    return left.isNegative ? Duration.zero : left;
  }

  Future<void> undoBoard(String childId) async {
    _guardRole();
    _guardSos();

    final s = session;
    final child = s?.childById(childId);
    if (s == null || child == null || !canUndoBoard(child)) return;

    if (!isLive) {
      child.state = ChildState.expected;
      child.boardingMethod = null;
      child.stateChangedAt = null;
      notifyListeners();
      return;
    }

    await _guarded(
      () async => _applyTrip(await api!.undoBoard(s.trip.id, childId)),
    );
  }

  /// How long until "Not at stop" unlocks at the current stop.
  Duration waitRemaining(TripStop stop) {
    final at = stop.reachedAt;
    if (at == null) return Config.stopWait;

    final left = at.add(Config.stopWait).difference(DateTime.now());
    return left.isNegative ? Duration.zero : left;
  }

  /// ⚠ MORNING ONLY, AND ONLY AFTER THE WAIT.
  ///
  /// In the morning a child who does not appear is still at home with their
  /// guardian, and the bus leaving is safe. In the afternoon the child is *on
  /// the bus*, and "not at stop" would mean putting them out at a kerb where
  /// nobody came. That is what [beginEscalation] and *Return to school* exist
  /// for instead.
  Future<void> markNotAtStop(String childId) async {
    _guardRole();
    _guardSos();

    final s = session;
    final child = s?.childById(childId);
    if (s == null || child == null) return;

    if (!s.trip.direction.isMorning) {
      throw SafetyViolation(
        'There is no "not at stop" on an afternoon trip. '
        '${child.firstName} is on the bus — verify a receiver, or return to school.',
        childIds: [child.id],
      );
    }

    final stop = s.stopById(child.stopId);
    if (stop == null || waitRemaining(stop) > Duration.zero) {
      throw SafetyViolation(
        'The bus waits the full ${Config.stopWait.inSeconds} seconds first.',
        childIds: [child.id],
      );
    }

    if (child.state != ChildState.expected) return;

    if (!isLive) {
      child.state = ChildState.notAtStop;
      child.stateChangedAt = DateTime.now();
      notifyListeners();
      return;
    }

    await _guarded(
      () async => _applyTrip(await api!.notAtStop(s.trip.id, childId)),
    );
  }

  /* ---------------- 7 · head count ---------------- */

  /// PART F2 — the wrong-row catcher.
  ///
  /// A mis-tap marks a child boarded who is not on the bus. Nothing else in the
  /// flow disagrees: the roster is self-consistent, the parent has had a
  /// boarding notification, the header count matches the marks. Only a physical
  /// count of actual children does.
  Future<bool> submitHeadCount(int counted) async {
    final s = session;
    if (s == null) return false;

    if (!isLive) {
      s.headCountEntered = counted;
      s.headCountMatched = counted == s.onBoard;
      notifyListeners();
      return s.headCountMatched!;
    }

    final json = await _guarded(() => api!.headCount(s.trip.id, counted));
    final matched = json?['matched'] == true;

    s.headCountEntered = counted;
    s.headCountMatched = matched;
    notifyListeners();

    return matched;
  }

  void clearHeadCount() {
    final s = session;
    if (s == null) return;

    s.headCountEntered = null;
    s.headCountMatched = null;

    notifyListeners();
  }

  /* ---------------- 8 · arrival at school ---------------- */

  /// ⚠ MORNING CUSTODY GOES CHILD → INSTITUTION, so there is no receiver code
  /// here. The bus delivers children to a school, which does not have to prove
  /// its identity to receive a pupil. The receiver check belongs to the
  /// afternoon (PART A7).
  Future<void> confirmDisembark() async {
    final s = session;
    if (s == null) return;

    _guardRole();
    _guardSos();

    if (s.headCountMatched != true) {
      throw const SafetyViolation(
        'Do the head count first. It is the only check that catches a child '
        'marked boarded who is not on the bus.',
      );
    }

    if (!isLive) {
      for (final c in s.children) {
        if (c.state == ChildState.boarded) {
          c.state = ChildState.arrivedAtSchool;
          c.stateChangedAt = DateTime.now();
        }
      }

      s.disembarkedAt = DateTime.now();
      s.atSchoolGate = true;
      s.metresFromSchool = 0;

      notifyListeners();
      return;
    }

    await _guarded(() async => _applyTrip(await api!.disembark(s.trip.id)));

    session
      ?..atSchoolGate = true
      ..metresFromSchool = 0;

    notifyListeners();
  }

  /* ---------------- 9 · afternoon boarding at school ---------------- */

  Future<void> togglePmBoarded(String childId) async {
    _guardRole();
    _guardSos();

    final s = session;
    final child = s?.childById(childId);
    if (s == null || child == null) return;

    if (s.lockedInAt != null) {
      throw const SafetyViolation(
        'The roster is locked and the bus has left. Ops can reopen it.',
      );
    }

    // Absent and parent-collecting rows are the office's, not the kerb's.
    final boarding = child.state == ChildState.expected;

    if (!boarding && child.state != ChildState.boarded) return;

    if (!isLive) {
      child.state = boarding ? ChildState.boarded : ChildState.expected;
      child.stateChangedAt = DateTime.now();
      notifyListeners();
      return;
    }

    await _guarded(() async => _applyTrip(
          boarding
              ? await api!.board(s.trip.id, childId)
              : await api!.undoBoard(s.trip.id, childId),
        ));
  }

  Duration departureRemaining() {
    final at = session?.trip.departsAt;
    if (at == null) return Duration.zero;

    final left = at.difference(DateTime.now());
    return left.isNegative ? Duration.zero : left;
  }

  bool get canLockIn {
    final s = session;
    if (s == null || s.lockedInAt != null) return false;

    final unresolved =
        s.children.where((c) => c.state == ChildState.expected).length;

    return unresolved == 0 || departureRemaining() == Duration.zero;
  }

  /// ⚠ Un-boarded children become absent AND THE SCHOOL OFFICE IS TOLD.
  ///
  /// The notification is the point of the action, not a side effect: an
  /// expected child who did not come out to the bus is somewhere on school
  /// premises, and that is the school's problem to resolve before the bus
  /// leaves — not a row that quietly greys out.
  Future<int> lockInAndDepart() async {
    _guardRole();
    _guardSos();

    final s = session;
    if (s == null) return 0;

    if (!canLockIn) {
      throw const SafetyViolation(
        'Not everyone is resolved yet, and it is not departure time. '
        'Wait, or mark the rest.',
      );
    }

    if (!isLive) {
      var marked = 0;

      for (final c in s.children) {
        if (c.state == ChildState.expected) {
          c.state = ChildState.absent;
          c.stateChangedAt = DateTime.now();
          marked++;
        }
      }

      s.lockedInAt = DateTime.now();
      notifyListeners();

      return marked;
    }

    final json = await _guarded(() => api!.lockIn(s.trip.id));
    await refreshTrip();

    return (json?['marked_absent'] as num?)?.toInt() ?? 0;
  }

  /* ---------------- 10 · drop stop ---------------- */

  /// Children still on the bus who get off at this stop.
  List<TripChild> dropListAt(String stopId) => session == null
      ? const []
      : session!
          .childrenAt(stopId)
          .where((c) => c.state == ChildState.boarded)
          .toList();

  /// ⚠ INVARIANT #1, THE WHOLE OF IT.
  ///
  /// The only three ways a child leaves this bus at a kerb. There is no fourth
  /// method, no `skip`, and no `leaveChild`. If you are adding a parameter here
  /// meaning "we could not verify but let them off anyway", stop — the answer
  /// to that case is [beginEscalation] and then [returnToSchool].
  ///
  /// ⚠ The code is POSTED, never compared here. The server holds a hash; a
  /// plaintext comparison in an app is a formality anyone with the APK skips.
  Future<void> recordHandover({
    required String childId,
    required HandoverMethod method,
    String? code,
    int? authorizedReceiverId,
    String? receiverLabel,
  }) async {
    _guardRole();
    _guardSos();

    final s = session;
    final child = s?.childById(childId);
    if (s == null || child == null) return;

    if (child.state != ChildState.boarded) {
      throw SafetyViolation(
        '${child.firstName} is not on the bus.',
        childIds: [child.id],
      );
    }

    if (method == HandoverMethod.selfRelease && !child.maySelfRelease) {
      // Belt and braces: the option is not rendered for such a child, so
      // reaching here means a bug or a tampered call. Refuse loudly.
      throw SafetyViolation(
        '${child.name} has no signed self-release consent on file.',
        childIds: [child.id],
      );
    }

    if (!isLive) {
      child.state = ChildState.handedOver;
      child.stateChangedAt = DateTime.now();
      child.escalationStartedAt = null;
      child.handover = Handover(
        method: method,
        receiver: receiverLabel ?? method.label,
        at: DateTime.now(),
      );

      notifyListeners();
      return;
    }

    await _guarded(() async => _applyTrip(await api!.handover(
          s.trip.id,
          childId,
          method: switch (method) {
            HandoverMethod.code => 'handover_code',
            HandoverMethod.authorizedPerson => 'authorized_person',
            HandoverMethod.selfRelease => 'self_release',
          },
          code: code,
          authorizedReceiverId: authorizedReceiverId,
          offline: offline,
        )));
  }

  /// ⚠ DEMO MODE ONLY. Live, the server compares what was typed against a hash
  /// it holds and counts the attempts; this device never sees a live code.
  bool verifyHandoverCodeLocally(String entered) =>
      entered == DemoData.demoHandoverCode;

  /* ---------------- 11 · escalation ---------------- */

  /// Nobody is here. The child stays on the bus and the clock starts.
  Future<void> beginEscalation(String childId) async {
    _guardRole();

    final s = session;
    final child = s?.childById(childId);
    if (s == null || child == null) return;

    if (child.escalationStartedAt != null) return;

    if (!isLive) {
      child.escalationStartedAt = DateTime.now();
      notifyListeners();
      return;
    }

    await _guarded(() async {
      final json = await api!.escalate(s.trip.id, childId);

      // ⚠ The SERVER's start time, not the phone's. The window that decides
      // when a bus may leave with somebody's child must not be movable by a
      // handset clock.
      child.escalationStartedAt =
          DateTime.tryParse('${json['escalation_started_at']}')?.toLocal() ??
              DateTime.now();
    });
  }

  Duration escalationElapsed(TripChild child) {
    final at = child.escalationStartedAt;
    return at == null ? Duration.zero : DateTime.now().difference(at);
  }

  Duration escalationRemaining(TripChild child) {
    final left = Config.escalationWindow - escalationElapsed(child);
    return left.isNegative ? Duration.zero : left;
  }

  bool escalationExpired(TripChild child) =>
      child.escalationStartedAt != null &&
      escalationRemaining(child) == Duration.zero;

  /// ⚠ INVARIANT #1's ONLY REMAINING EXIT, and not available early.
  ///
  /// Until the window has run the bus waits, because a guardian two streets
  /// away in Hyderabad traffic is the ordinary case and driving off with their
  /// child is not a neutral act.
  Future<void> returnToSchool(String childId) async {
    _guardRole();
    _guardSos();

    final s = session;
    final child = s?.childById(childId);
    if (s == null || child == null) return;

    if (!escalationExpired(child)) {
      throw SafetyViolation(
        'The bus waits the full ${Config.escalationWindow.inMinutes} minutes first.',
        childIds: [child.id],
      );
    }

    if (!isLive) {
      child.state = ChildState.returnedToSchool;
      child.stateChangedAt = DateTime.now();
      notifyListeners();
      return;
    }

    await _guarded(
      () async => _applyTrip(await api!.returnToSchool(s.trip.id, childId)),
    );
  }

  /* ---------------- 12 · sweep ---------------- */

  /// Demo affordance for the geo-fence. Production reads the location stream.
  void setAtSchoolGate(bool value) {
    final s = session;
    if (s == null) return;

    s.atSchoolGate = value;
    s.metresFromSchool = value ? 0 : 1200;

    notifyListeners();
  }

  /// ⚠ INVARIANT #3. Timestamped, geo-stamped, photo-backed, and impossible to
  /// pre-tap: live, the SERVER refuses until the bus is inside the school's
  /// gate geofence, and that refusal is the one the crew sees.
  ///
  /// The failure this prevents is a child asleep in a back seat and a bus
  /// parked for the day. A sweep ticked at the second-to-last stop out of habit
  /// catches nothing.
  Future<void> confirmSweep({
    required String photoPath,
    double? lat,
    double? lng,
  }) async {
    final s = session;
    if (s == null) return;

    _guardRole();

    if (photoPath.isEmpty) {
      throw const SafetyViolation(
        'The sweep needs a photo of the empty aisle, taken from the back.',
      );
    }

    if (!isLive) {
      if (!s.atSchoolGate) {
        throw SafetyViolation(
          'The sweep unlocks at the school gate. The bus is '
          '${(s.metresFromSchool / 1000).toStringAsFixed(1)} km away. '
          'It cannot be ticked early — that is the point.',
        );
      }

      s.sweptAt = DateTime.now();
      s.sweepPhotoPath = photoPath;
      notifyListeners();
      return;
    }

    await _guarded(() => api!.sweep(
          s.trip.id,
          photo: File(photoPath),
          lat: lat,
          lng: lng,
        ));

    session?.sweepPhotoPath = photoPath;
    await refreshTrip();
  }

  /* ---------------- 13 · complete ---------------- */

  /// ⚠ INVARIANTS #2 AND #3 TOGETHER. Names the children rather than counting
  /// them, so the screen can offer a route straight to each one.
  Future<void> completeTrip() async {
    final s = session;
    if (s == null) return;

    _guardRole();

    if (s.sweepPending) {
      throw const SafetyViolation(
        'Cannot complete: the bus has not been swept. '
        'Walk to the back and confirm every seat is empty.',
      );
    }

    final open = s.unaccounted;

    if (open.isNotEmpty) {
      final onBus = open.where((c) => c.state == ChildState.boarded).toList();
      final missing = open.where((c) => c.state == ChildState.expected).toList();

      final parts = <String>[
        if (onBus.isNotEmpty)
          '${_nameList(onBus)} ${onBus.length == 1 ? 'is' : 'are'} still on board',
        if (missing.isNotEmpty)
          '${_nameList(missing)} ${missing.length == 1 ? 'is' : 'are'} never accounted for',
      ];

      throw SafetyViolation(
        'Cannot complete: ${parts.join(', and ')}. Every child must be handed '
        'over, absent, or returned to school before the trip can close.',
        childIds: open.map((c) => c.id).toList(),
      );
    }

    if (!isLive) {
      s.completedAt = DateTime.now();
      notifyListeners();
      return;
    }

    await _guarded(() async => _applyTrip(await api!.complete(s.trip.id)));

    // The trip is closed; the bus is no longer anybody's live map.
    stopPositionPushing();
  }

  /* ---------------- 15 · SOS ---------------- */

  Future<void> fireSos(SosType type, {bool drill = false}) async {
    final s = session;

    // Locked locally the instant it fires, before the round trip. The seconds
    // after an SOS are exactly when a frightened person taps at a phone.
    sos = SosAlert(type: type, firedAt: DateTime.now(), isDrill: drill);
    notifyListeners();

    if (!isLive || s == null) return;

    await _guarded(() => api!.sos(s.trip.id, type: type.name, drill: drill));
  }

  /// ⚠ Ops release the lock, not the crew. Bound to no control in the app: it
  /// exists for the refresh path (the server clears `sos_locked`) and tests.
  void releaseSos() {
    sos = null;
    session?.sosLocked = false;
    notifyListeners();
  }

  /* ---------------- position ---------------- */

  /// ⚠ THIS IS WHAT MOVES THE PARENT APP'S LIVE MAP.
  ///
  /// Fire-and-forget: a dropped ping is a bus that appears to have stopped on
  /// 22 parents' screens, so it is never blocked on and never raises an error
  /// at the crew — who can do nothing about it anyway.
  Future<void> ping({
    required double lat,
    required double lng,
    int? speedKmph,
  }) async {
    final s = session;
    if (!isLive || s == null || !s.isRunning) return;

    try {
      await api!.ping(s.trip.id, lat: lat, lng: lng, speedKmph: speedKmph);
    } on Object {
      // Deliberately silent. See above.
    }
  }

  /// Starts pushing the vehicle's position for as long as the trip runs.
  ///
  /// ⚠ Both devices push. Either the driver's or the attendant's handset can be
  /// the one in signal, in a pocket, or out of battery, and a family watching a
  /// bus that has stopped moving does not care which. The server keeps the last
  /// write, so two pushers is redundancy rather than a conflict.
  Future<void> startPositionPushing() async {
    if (!isLive || _positionSub != null) return;

    if (!await location.ensurePermission()) {
      // No permission is not a blocker for the crew — the child-safety work all
      // still functions. It costs the parents their map, and the sweep will be
      // refused at the gate, so it is worth asking for again later.
      return;
    }

    _positionSub = location.watch().listen((p) {
      ping(
        lat: p.latitude,
        lng: p.longitude,
        speedKmph: (p.speed * 3.6).round().clamp(0, 200),
      );
    });
  }

  void stopPositionPushing() {
    _positionSub?.cancel();
    _positionSub = null;
  }

  @override
  void dispose() {
    stopPositionPushing();
    super.dispose();
  }

  /* ---------------- offline ---------------- */

  void setOffline(bool value) {
    offline = value;
    if (!value) queuedEvents = 0;
    notifyListeners();
  }

  /* ---------------- helpers ---------------- */

  /// "Aarav Mehta", "Aarav Mehta and Zoya Khan", "A, B and C".
  ///
  /// ⚠ Names, never a count. "2 children unaccounted for" is a number somebody
  /// dismisses; a name is a person somebody goes and finds.
  static String _nameList(List<TripChild> children) {
    final names = children.map((c) => c.name).toList();

    if (names.length == 1) return names.first;
    if (names.length == 2) return '${names[0]} and ${names[1]}';

    return '${names.sublist(0, names.length - 1).join(', ')} and ${names.last}';
  }
}
