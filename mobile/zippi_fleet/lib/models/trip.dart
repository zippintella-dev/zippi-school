/// Where a child is, as far as this trip is concerned.
///
/// ⚠ THERE IS NO "SKIPPED". Invariant #1: a child is never released without a
/// verified receiver, and there is no "mark absent and drive on" at a drop
/// stop. Every terminal value below is either a verified handover, a
/// documented absence recorded *before* the child was ever on the bus, or the
/// bus taking them back to school. If you find yourself wanting to add a value
/// that means "we left them", the design is wrong, not this enum.
enum ChildState {
  /// Morning: on the roster for this stop, not yet boarded.
  /// Afternoon at school: on the roster, not yet come out.
  expected,

  /// On the bus.
  boarded,

  /// Morning only, and only after the wait countdown expires. The guardians are
  /// notified and the child is the school's problem, not the kerb's.
  notAtStop,

  /// Recorded absent — by the parent in the Zippi Parent app, or by the school
  /// office. Never something the attendant decides at a drop stop.
  absent,

  /// Afternoon: a parent is collecting from the school gate, so the child was
  /// never expected on the bus.
  parentCollecting,

  /// Morning terminal state: delivered to the institution.
  arrivedAtSchool,

  /// Afternoon terminal state: released to a verified receiver.
  handedOver,

  /// Afternoon terminal state when nobody could be verified. Invariant #1's
  /// only remaining exit.
  returnedToSchool;

  /// Is this child still the bus's responsibility?
  ///
  /// ⚠ This is the predicate Invariant #2 is built on. `SchoolTrip::
  /// unaccountedChildIds()` is the server-side 422 gate; this is the same
  /// question asked locally so the attendant is told *before* they tap
  /// Complete rather than after.
  bool get isUnaccounted => this == ChildState.expected || this == ChildState.boarded;

  /// Words, always — never colour alone.
  String get label => switch (this) {
        ChildState.expected => 'Not boarded',
        ChildState.boarded => 'On board',
        ChildState.notAtStop => 'Not at stop · guardians notified',
        ChildState.absent => 'Absent',
        ChildState.parentCollecting => 'Parent collecting',
        ChildState.arrivedAtSchool => 'Arrived at school',
        ChildState.handedOver => 'Handed over',
        ChildState.returnedToSchool => 'Returned to school',
      };
}

/// How a child was released at a drop stop.
///
/// ⚠ These three are the whole of Invariant #1. There is no fourth.
enum HandoverMethod {
  /// The 4-digit code from the guardian's Parent app (PART A7).
  code,

  /// A face the family has approved in advance — the grandmother who collects
  /// daily and for whom typing a code four times a week is friction that gets
  /// worked around.
  authorizedPerson,

  /// Only when the child has signed consent on file AND meets the school's
  /// minimum grade. `Child::canSelfRelease()` requires both.
  selfRelease;

  String get label => switch (this) {
        HandoverMethod.code => 'Handover code verified',
        HandoverMethod.authorizedPerson => 'Authorized person',
        HandoverMethod.selfRelease => 'Self-release',
      };
}

/// The audit record of a release. Actor, method, receiver, server time.
class Handover {
  final HandoverMethod method;

  /// Who took the child. For [HandoverMethod.selfRelease] this records the
  /// consent, because "nobody" is not an acceptable answer to "who received
  /// this child".
  final String receiver;
  final DateTime at;

  const Handover({
    required this.method,
    required this.receiver,
    required this.at,
  });
}

/// The three avatar tints.
///
/// ⚠ Assigned in the roster, not derived from a hash. Two siblings at one stop
/// must never land on the same tint, and a hash cannot promise that. It also
/// has to be stable across screens: a child who is teal on the stop list and
/// amber on the head-count grid is a child someone counts twice.
enum AvatarTone { teal, coral, amber }

/// An adult this family has pre-approved to collect the child.
class AuthorizedPerson {
  /// `authorized_receivers.id`. Sent back on handover so the custody record
  /// names a ROW, not a free-text string somebody could have typed.
  final int? id;

  final String name;
  final String relationship;

  /// Photo URL when the school has one. Null falls back to an initial tile —
  /// which is still better than a name alone at a kerb.
  final String? photoUrl;

  const AuthorizedPerson({
    required this.name,
    required this.relationship,
    this.id,
    this.photoUrl,
  });

  factory AuthorizedPerson.fromJson(Map<String, dynamic> json) =>
      AuthorizedPerson(
        id: (json['id'] as num?)?.toInt(),
        name: json['name'] as String? ?? '—',
        relationship: json['relationship'] as String? ?? '',
        photoUrl: json['photo_url'] as String?,
      );
}

/// A child on this trip.
class TripChild {
  final String id;
  final String name;
  final String className;

  /// ⚠ SIBLING DISAMBIGUATION. Two Mehtas, same surname, similar face at
  /// 56dp — "Blue name tag", "Red name tag · Aarav's sister". The design calls
  /// for a distinguishing detail on every row and this is where it lives. An
  /// empty string here is a row that can be mis-tapped.
  final String distinguishingDetail;

  final String? photoUrl;

  /// Which stop this child gets on or off at. Children are assigned to a
  /// published stop, never to a home coordinate.
  final String stopId;

  ChildState state;

  /// Server time of the last state change. Drives the 90-second undo window.
  DateTime? stateChangedAt;

  Handover? handover;

  /// Why this child is absent or is not travelling — set by the office or by
  /// the parent in the Zippi Parent app, and shown verbatim on the row.
  ///
  /// ⚠ "Absent" alone tells the attendant nothing they can act on. "Absent —
  /// fever, parent informed 8:40 AM" tells them the office already knows.
  String? note;

  /// `Child::canSelfRelease()` — signed consent AND the school's minimum grade.
  ///
  /// ⚠ Resolved by the SERVER and shipped as a single boolean. The app must
  /// never re-derive it from a grade number: a local rule that drifts from the
  /// school's is a child let off a bus alone.
  final bool maySelfRelease;

  final List<AuthorizedPerson> authorizedPeople;

  /// PART E2 — shown behind a tap, never on the roster row.
  ///
  /// ⚠ A child's asthma is not something to display to a bus full of
  /// classmates, but it is something the attendant must reach in seconds.
  final String? medicalNotes;

  final AvatarTone tone;

  /// Invariant #1's clock. Set the moment "No one is here" is tapped at a drop
  /// stop; the escalation ladder and its expiry are read from it.
  DateTime? escalationStartedAt;

  TripChild({
    required this.id,
    required this.name,
    required this.className,
    required this.stopId,
    this.distinguishingDetail = '',
    this.photoUrl,
    this.state = ChildState.expected,
    this.stateChangedAt,
    this.handover,
    this.note,
    this.maySelfRelease = false,
    this.authorizedPeople = const [],
    this.medicalNotes,
    this.tone = AvatarTone.teal,
    this.escalationStartedAt,
  });

  /// From the `children` array of `GET /api/fleet/trips/{trip}`.
  ///
  /// ⚠ `may_self_release` arrives as ONE BOOLEAN the server resolved from the
  /// child's consent AND the school's minimum grade. It is never re-derived
  /// here from `class`: a local rule that drifts from the school's is a child
  /// let off a bus alone.
  ///
  /// ⚠ The payload carries no handover code, by design. The attendant's device
  /// posts what was typed and the server compares it against a hash.
  factory TripChild.fromJson(Map<String, dynamic> json, {required int index}) {
    return TripChild(
      id: '${json['child_id']}',
      name: json['name'] as String? ?? '—',
      className: json['class'] as String? ?? '',
      stopId: '${json['stop_id']}',
      distinguishingDetail: json['detail'] as String? ?? '',
      photoUrl: json['photo_url'] as String?,
      state: _state(json['status'] as String?),
      stateChangedAt: _dt(json['boarded_at']) ?? _dt(json['alighted_at']),
      note: json['note'] as String?,
      medicalNotes: json['medical_notes'] as String?,
      maySelfRelease: json['may_self_release'] == true,
      escalationStartedAt: _dt(json['escalation_started_at']),
      authorizedPeople: ((json['authorized_receivers'] as List?) ?? const [])
          .map((r) => AuthorizedPerson.fromJson(r as Map<String, dynamic>))
          .toList(),
      // ⚠ Derived from POSITION IN THE STOP-ORDERED ROSTER, not from a hash of
      // the name. Adjacent rows therefore never share a tint, which is exactly
      // the Mehta-siblings case this exists for.
      tone: AvatarTone.values[index % AvatarTone.values.length],
    );
  }

  /// ⚠ The server's vocabulary is the authority. `parent_collecting` is a
  /// display status the server computes from the absence row; the app never
  /// sends it back.
  static ChildState _state(String? raw) => switch (raw) {
        'boarded' => ChildState.boarded,
        'not_at_stop' => ChildState.notAtStop,
        'absent' => ChildState.absent,
        'parent_collecting' => ChildState.parentCollecting,
        'arrived_at_school' => ChildState.arrivedAtSchool,
        'alighted_to_guardian' => ChildState.handedOver,
        'alighted_self_release' => ChildState.handedOver,
        'returned_to_school' => ChildState.returnedToSchool,
        // ⚠ An unrecognised status counts as UNACCOUNTED, not as resolved. A
        // build that predates a new server state must fail towards "this child
        // still needs attention".
        _ => ChildState.expected,
      };

  static DateTime? _dt(Object? raw) =>
      raw is String ? DateTime.tryParse(raw)?.toLocal() : null;

  String get initial => name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase();

  /// First name, for sentences like "Nobody is here for Aarav."
  String get firstName => name.trim().split(' ').first;
}

/// One stop on the authored sequence.
///
/// ⚠ THE SEQUENCE IS AUTHORED AND FROZEN (PART G3 · ⚠ DIVERGES FROM
/// ENTERPRISE). It is never re-ordered by nearest-neighbour, never re-sorted in
/// the UI, and `index` is not a display convenience — it is the order the bus
/// physically drives. Sorting this list by anything else puts a bus on a road
/// nobody signed off.
class TripStop {
  final String id;

  /// 1-based position in the authored sequence. Carried so a fleet API can send
  /// and receive it verbatim; the app renders the list order, never this.
  final int index;
  final String name;

  /// Scheduled time as the solver produced it (PART M7).
  final String scheduledTime;

  final double latitude;
  final double longitude;

  /// Set when the bus geo-fences into the stop.
  DateTime? reachedAt;
  DateTime? departedAt;

  /// True for the school itself, which is a stop like any other in the
  /// sequence but the terminus of the journey rather than a kerb.
  final bool isSchool;

  TripStop({
    required this.id,
    required this.index,
    required this.name,
    required this.scheduledTime,
    required this.latitude,
    required this.longitude,
    this.reachedAt,
    this.departedAt,
    this.isSchool = false,
  });

  /// From the `stops` array — already in AUTHORED order. The list is never
  /// re-sorted client-side; `sequence` is carried for the API, not for display.
  factory TripStop.fromJson(Map<String, dynamic> json, {required int index}) =>
      TripStop(
        id: '${json['stop_id']}',
        index: (json['sequence'] as num?)?.toInt() ?? index + 1,
        name: json['name'] as String? ?? '',
        scheduledTime: _clock(json['scheduled_at']),
        latitude: (json['latitude'] as num?)?.toDouble() ?? 0,
        longitude: (json['longitude'] as num?)?.toDouble() ?? 0,
        reachedAt: _dt(json['reached_at']),
        departedAt: _dt(json['departed_at']),
        isSchool: json['is_school'] == true,
      );

  static DateTime? _dt(Object? raw) =>
      raw is String ? DateTime.tryParse(raw)?.toLocal() : null;

  /// "7:11 AM" out of an ISO timestamp, in the device's local zone — which is
  /// the school's zone for every crew member who is physically on the bus.
  static String _clock(Object? raw) {
    final at = _dt(raw);
    if (at == null) return '—';

    final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
    final m = at.minute.toString().padLeft(2, '0');

    return '$h:$m ${at.hour < 12 ? 'AM' : 'PM'}';
  }

  bool get reached => reachedAt != null;
  bool get departed => departedAt != null;
}

/// A pre-trip check. Each tick is timestamped into the trip record.
///
/// ⚠ Cannot be pre-tapped in bulk and cannot be skipped: the swipe-to-begin
/// widget stays inert until every one is true. See
/// `screens/checklist_screen.dart`.
class ChecklistItem {
  final String label;
  final String hint;
  bool ticked;
  DateTime? tickedAt;

  ChecklistItem(this.label, this.hint, {this.ticked = false, this.tickedAt});
}
