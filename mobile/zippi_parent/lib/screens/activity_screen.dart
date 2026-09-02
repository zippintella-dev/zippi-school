import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../models/family_card.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Activity — today's events for the ONE selected child.
///
/// ⚠ Receives a single child, not the family. A sibling's events must not
/// appear on a screen the parent has scoped to one child; passing the whole
/// list and filtering here is how that leaks back in.
class ActivityScreen extends StatelessWidget {
  final FamilyCard child;
  final Future<void> Function() onRefresh;

  const ActivityScreen({
    required this.child,
    required this.onRefresh,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final events = child.timeline.reversed.toList();

    return RefreshIndicator(
      onRefresh: onRefresh,
      color: Z.teal,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(4, 20, 0, 4),
            child: ZippiWordmark(),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 8, 0, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Activity', style: Z.head(26)),
                const SizedBox(height: 2),
                Text(
                  '${DateFormat('EEE d MMM').format(DateTime.now())} · '
                  '${child.name}',
                  style: Z.text(14, color: Z.muted),
                ),
              ],
            ),
          ),

          if (child.absent) ...[
            NoteBanner(
              '${child.name} is marked absent today'
              '${child.absentDirections.isEmpty ? '' : ' (${child.absentDirections.join(' & ')})'}.',
            ),
            const SizedBox(height: 14),
          ],

          if (events.isEmpty)
            const Padding(
              padding: EdgeInsets.only(top: 40),
              child: EmptyState(
                'Nothing reported yet',
                'Events appear here as the bus attendant records them '
                'through the day.',
              ),
            ),

          for (final e in events) ...[
            _row(e),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }

  Widget _row(TimelineEvent e) {
    final morning = e.direction == 'Morning';

    return ZCard(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(
        children: [
          InitialAvatar(child.name, size: 44, coral: !morning),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(e.text,
                    style: Z.text(14, color: Z.ink, weight: FontWeight.w800)),
                const SizedBox(height: 2),
                Text(
                  [
                    fmtTime(e.at),
                    if (child.trip?.route.isNotEmpty ?? false) child.trip!.route,
                    morning ? 'to school' : 'on the way home',
                  ].join(' · '),
                  style: Z.text(13, color: Z.muted),
                ),
              ],
            ),
          ),
          if (child.showLiveMap) ...[
            const SizedBox(width: 8),
            const StatusChip('Live', tone: ChipTone.live),
          ],
        ],
      ),
    );
  }
}
