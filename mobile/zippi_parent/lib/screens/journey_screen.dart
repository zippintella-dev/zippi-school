import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../models/journey_day.dart';
import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// PART D — the journey record, 90 days.
///
/// The canvas's "Day" screen, repeated per date: morning and afternoon as
/// separate tagged sections. This is the killer feature and what answers a
/// dispute, so it renders what was recorded and nothing inferred — a leg with
/// no boarding says so rather than showing blank.
class JourneyScreen extends StatefulWidget {
  final ApiClient api;
  final int childId;
  final String childName;

  const JourneyScreen({
    required this.api,
    required this.childId,
    required this.childName,
    super.key,
  });

  @override
  State<JourneyScreen> createState() => _JourneyScreenState();
}

class _JourneyScreenState extends State<JourneyScreen> {
  List<JourneyDay>? _days;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final days = await widget.api.journey(widget.childId);
      if (!mounted) return;

      setState(() {
        _days = days;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            ScreenHeader(
              'Journey',
              subtitle: '${widget.childName} · last 90 days',
              onBack: () => Navigator.of(context).pop(),
            ),
            Expanded(child: _body()),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_error != null && _days == null) {
      return ErrorState(_error!, onRetry: _load);
    }

    if (_days == null) {
      return const Center(child: CircularProgressIndicator(color: Z.turquoise));
    }

    if (_days!.isEmpty) {
      return EmptyState(
        'Nothing yet',
        "${widget.childName}'s trips will appear here once the bus "
        'starts running.',
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      color: Z.teal,
      child: ListView.builder(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 28),
        itemCount: _days!.length,
        itemBuilder: (_, i) => _day(_days![i]),
      ),
    );
  }

  Widget _day(JourneyDay d) {
    final date = DateTime.tryParse(d.serviceDate);

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 8, 0, 10),
            child: Row(
              children: [
                Text(
                  date == null
                      ? d.serviceDate
                      : DateFormat('EEE d MMM yyyy').format(date),
                  style: Z.head(18),
                ),
                if (d.absentDirections.isNotEmpty) ...[
                  const SizedBox(width: 10),
                  StatusChip('Absent · ${d.absentDirections.join(' & ')}'),
                ],
              ],
            ),
          ),
          for (final t in d.trips) ...[
            _trip(t),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }

  Widget _trip(JourneyTrip t) {
    final rows = <(String, String, bool)>[
      if (t.boardedAt != null)
        (
          fmtTime(t.boardedAt),
          'Boarded${t.stop != null ? ' at ${t.stop}' : ''}',
          true
        ),
      if (t.arrivedAtSchoolAt != null)
        (fmtTime(t.arrivedAtSchoolAt), 'Reached school', true),
      if (t.alightedAt != null) (fmtTime(t.alightedAt), t.outcome, true),
      // A leg with nothing recorded is stated explicitly — silence here reads
      // as "nothing happened" rather than "nothing was recorded".
      if (t.boardedAt == null && t.alightedAt == null)
        ('—', t.outcome, false),
    ];

    return ZCard(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SectionTag(
                t.isMorning ? 'Morning · to school' : 'Afternoon · to home',
                tone: t.isMorning ? ChipTone.morning : ChipTone.afternoon,
              ),
              const SizedBox(width: 8),
              Text(t.route ?? '', style: Z.text(13, color: Z.muted)),
            ],
          ),
          const SizedBox(height: 8),
          for (var i = 0; i < rows.length; i++)
            TimelineRow(
              time: rows[i].$1,
              text: rows[i].$2,
              bold: rows[i].$3,
              dimmed: !rows[i].$3,
              last: i == rows.length - 1,
            ),
        ],
      ),
    );
  }
}
