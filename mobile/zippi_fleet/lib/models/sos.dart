/// What is happening. Picked after the 2-second hold, never before — the type
/// picker is not the alarm, the hold is.
enum SosType {
  accident,
  breakdown,
  medical,
  security,
  fire,
  other;

  String get label => switch (this) {
        SosType.accident => 'Accident',
        SosType.breakdown => 'Breakdown',
        SosType.medical => 'Medical',
        SosType.security => 'Security',
        SosType.fire => 'Fire',
        SosType.other => 'Other',
      };
}

/// A fired alert.
///
/// ⚠ WHILE ONE OF THESE IS OPEN, NO CHILD STATE CHANGE IS ACCEPTED. Not
/// boarding, not handover, not "not at stop". The lock exists because the
/// minutes after an SOS are exactly when a frightened person taps at a phone,
/// and a panicked mis-tap sequence in a child-custody record is worse than no
/// record at all. `FleetStore` enforces it; the UI only explains it.
///
/// Only ops can release the lock. There is no local override, by design.
class SosAlert {
  final SosType type;
  final DateTime firedAt;

  /// ⚠ A drill is banded, logged, and dispatches nobody. It still locks child
  /// records, because a drill that behaves differently from the real thing
  /// trains the wrong reflex.
  final bool isDrill;

  const SosAlert({
    required this.type,
    required this.firedAt,
    this.isDrill = false,
  });
}
