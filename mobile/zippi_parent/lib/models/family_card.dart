/// One child's current state, as returned by `FamilyCardBuilder` on the server.
///
/// ⚠ The client NEVER recomputes `showLiveMap`. The server decides when a bus
/// position may leave it (enterprise L29) and omits the coordinates entirely
/// when the answer is no. Re-deriving that rule here would put a second,
/// drifting copy of a safety rule on the device.
library;

class FamilyCard {
  final int childId;
  final String name;
  final String grade;
  final String? bellTier;
  final String? school;
  final String? schoolPhone;
  final String serviceDate;
  final bool hasActiveTrip;
  final bool showLiveMap;
  final String? status;
  final String statusLabel;
  final bool absent;
  final List<String> absentDirections;
  final bool showHandoverCode;

  /// Present only on the single-child endpoint, and only when the server
  /// decided a code is warranted (PART A7).
  final String? handoverCode;

  /// PART A7 (extended) — the MORNING boarding code, read out as the child gets
  /// on the bus.
  ///
  /// ⚠ Never non-null at the same time as [handoverCode]. The server sets one or
  /// the other from the trip's direction, and the app must not invent a state
  /// where a parent is looking at both — the afternoon release code has no
  /// business being on a screen at a morning kerb.
  final bool showBoardingCode;
  final String? boardingCode;

  final TripInfo? trip;
  final StopInfo? stop;
  final List<TimelineEvent> timeline;

  const FamilyCard({
    required this.childId,
    required this.name,
    required this.grade,
    required this.bellTier,
    required this.school,
    required this.schoolPhone,
    required this.serviceDate,
    required this.hasActiveTrip,
    required this.showLiveMap,
    required this.status,
    required this.statusLabel,
    required this.absent,
    required this.absentDirections,
    required this.showHandoverCode,
    required this.handoverCode,
    required this.showBoardingCode,
    required this.boardingCode,
    required this.trip,
    required this.stop,
    required this.timeline,
  });

  factory FamilyCard.fromJson(Map<String, dynamic> j) => FamilyCard(
        childId: j['child_id'] as int,
        name: j['name'] as String? ?? '',
        grade: '${j['grade'] ?? ''}',
        bellTier: j['bell_tier'] as String?,
        school: j['school'] as String?,
        schoolPhone: j['school_phone'] as String?,
        serviceDate: j['service_date'] as String? ?? '',
        hasActiveTrip: j['has_active_trip'] == true,
        showLiveMap: j['show_live_map'] == true,
        status: j['status'] as String?,
        statusLabel: j['status_label'] as String? ?? '',
        absent: j['absent'] == true,
        absentDirections:
            (j['absent_directions'] as List?)?.map((e) => '$e').toList() ?? const [],
        showHandoverCode: j['show_handover_code'] == true,
        handoverCode: j['handover_code'] as String?,
        showBoardingCode: j['show_boarding_code'] == true,
        boardingCode: j['boarding_code'] as String?,
        trip: j['trip'] == null
            ? null
            : TripInfo.fromJson(j['trip'] as Map<String, dynamic>),
        stop: j['stop'] == null
            ? null
            : StopInfo.fromJson(j['stop'] as Map<String, dynamic>),
        timeline: (j['timeline'] as List?)
                ?.map((e) => TimelineEvent.fromJson(e as Map<String, dynamic>))
                .toList() ??
            const [],
      );

  bool get isMorning => trip?.direction == 'Morning';
}

class TripInfo {
  final int id;
  final String direction;
  final String? bellTier;
  final String status;
  final String route;
  final DateTime? scheduledStartAt;
  final DateTime? scheduledEndAt;
  final String? bellTime;
  final CrewMember? driver;
  final CrewMember? attendant;
  final BusInfo? bus;

  const TripInfo({
    required this.id,
    required this.direction,
    required this.bellTier,
    required this.status,
    required this.route,
    required this.scheduledStartAt,
    required this.scheduledEndAt,
    required this.bellTime,
    required this.driver,
    required this.attendant,
    required this.bus,
  });

  factory TripInfo.fromJson(Map<String, dynamic> j) => TripInfo(
        id: j['id'] as int,
        direction: j['direction'] as String? ?? '',
        bellTier: j['bell_tier'] as String?,
        status: j['status'] as String? ?? '',
        route: j['route'] as String? ?? '',
        scheduledStartAt: DateTime.tryParse('${j['scheduled_start_at']}')?.toLocal(),
        scheduledEndAt: DateTime.tryParse('${j['scheduled_end_at']}')?.toLocal(),
        bellTime: j['bell_time'] as String?,
        driver: j['driver'] == null
            ? null
            : CrewMember.fromJson(j['driver'] as Map<String, dynamic>),
        attendant: j['attendant'] == null
            ? null
            : CrewMember.fromJson(j['attendant'] as Map<String, dynamic>),
        bus: j['bus'] == null
            ? null
            : BusInfo.fromJson(j['bus'] as Map<String, dynamic>),
      );
}

/// ⚠ PART K9 — a parent gets a name and a MASKED number. There is deliberately
/// no raw-phone field on this class; if one ever appears in the payload, the
/// leak is on the server and this model should not quietly carry it through.
class CrewMember {
  final String name;
  final String phoneMasked;

  const CrewMember({required this.name, required this.phoneMasked});

  factory CrewMember.fromJson(Map<String, dynamic> j) => CrewMember(
        name: j['name'] as String? ?? '',
        phoneMasked: j['phone_masked'] as String? ?? '—',
      );
}

class BusInfo {
  final String regNo;

  /// Null whenever the server has closed the map for this family.
  final double? latitude;
  final double? longitude;
  final DateTime? lastPingAt;

  const BusInfo({
    required this.regNo,
    required this.latitude,
    required this.longitude,
    required this.lastPingAt,
  });

  factory BusInfo.fromJson(Map<String, dynamic> j) => BusInfo(
        regNo: j['reg_no'] as String? ?? '',
        latitude: (j['latitude'] as num?)?.toDouble(),
        longitude: (j['longitude'] as num?)?.toDouble(),
        lastPingAt: DateTime.tryParse('${j['last_ping_at']}')?.toLocal(),
      );

  bool get hasPosition => latitude != null && longitude != null;

  /// PART B — a bus that has not reported in a while is shown as stale rather
  /// than pretended to be live.
  bool get isStale =>
      lastPingAt == null ||
      DateTime.now().difference(lastPingAt!) > const Duration(minutes: 3);
}

class StopInfo {
  final String name;
  final double latitude;
  final double longitude;
  final DateTime? scheduledAt;

  const StopInfo({
    required this.name,
    required this.latitude,
    required this.longitude,
    required this.scheduledAt,
  });

  factory StopInfo.fromJson(Map<String, dynamic> j) => StopInfo(
        name: j['name'] as String? ?? '',
        latitude: (j['latitude'] as num?)?.toDouble() ?? 0,
        longitude: (j['longitude'] as num?)?.toDouble() ?? 0,
        scheduledAt: DateTime.tryParse('${j['scheduled_at']}')?.toLocal(),
      );
}

class TimelineEvent {
  final DateTime at;
  final String direction;
  final String text;

  const TimelineEvent({
    required this.at,
    required this.direction,
    required this.text,
  });

  factory TimelineEvent.fromJson(Map<String, dynamic> j) => TimelineEvent(
        at: DateTime.tryParse('${j['at']}')?.toLocal() ?? DateTime.now(),
        direction: j['direction'] as String? ?? '',
        text: j['text'] as String? ?? '',
      );
}
