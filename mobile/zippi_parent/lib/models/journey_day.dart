/// PART D — the journey record. One entry per service date, 90 days back.
///
/// This is the killer feature: every event with actor, coordinates and SERVER
/// time. Client time is never trusted for ordering.
library;

class JourneyDay {
  final String serviceDate;
  final List<String> absentDirections;
  final List<JourneyTrip> trips;

  const JourneyDay({
    required this.serviceDate,
    required this.absentDirections,
    required this.trips,
  });

  factory JourneyDay.fromJson(Map<String, dynamic> j) => JourneyDay(
        serviceDate: j['service_date'] as String? ?? '',
        absentDirections:
            (j['absent_directions'] as List?)?.map((e) => '$e').toList() ?? const [],
        trips: (j['trips'] as List?)
                ?.map((e) => JourneyTrip.fromJson(e as Map<String, dynamic>))
                .toList() ??
            const [],
      );
}

class JourneyTrip {
  final String direction;
  final String? bellTier;
  final String? route;
  final String status;
  final String? stop;
  final DateTime? boardedAt;
  final DateTime? arrivedAtSchoolAt;
  final DateTime? alightedAt;

  const JourneyTrip({
    required this.direction,
    required this.bellTier,
    required this.route,
    required this.status,
    required this.stop,
    required this.boardedAt,
    required this.arrivedAtSchoolAt,
    required this.alightedAt,
  });

  factory JourneyTrip.fromJson(Map<String, dynamic> j) => JourneyTrip(
        direction: j['direction'] as String? ?? '',
        bellTier: j['bell_tier'] as String?,
        route: j['route'] as String?,
        status: j['status'] as String? ?? '',
        stop: j['stop'] as String?,
        boardedAt: DateTime.tryParse('${j['boarded_at']}')?.toLocal(),
        arrivedAtSchoolAt:
            DateTime.tryParse('${j['arrived_at_school_at']}')?.toLocal(),
        alightedAt: DateTime.tryParse('${j['alighted_at']}')?.toLocal(),
      );

  bool get isMorning => direction == 'Morning';

  /// The human sentence for however this leg ended.
  String get outcome => switch (status) {
        'alighted_self_release' => 'Got off${stop != null ? ' at $stop' : ''}',
        'alighted_to_guardian' => 'Handed over${stop != null ? ' at $stop' : ''}',
        'returned_to_school' => 'Returned to school — nobody at the stop',
        'not_at_stop' => 'Not at the stop when the bus arrived',
        'arrived_at_school' => 'Reached school',
        'absent' => 'Absent',
        _ => 'No boarding recorded',
      };
}
