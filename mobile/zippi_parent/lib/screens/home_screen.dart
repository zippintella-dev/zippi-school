import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../models/family_card.dart';
import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'absent_screen.dart';
import 'child_screen.dart';
import 'journey_screen.dart';
import 'pickup_screen.dart';
import 'tracking_screen.dart';

/// Home — everything about the ONE selected child.
///
/// Deliberately not a list. A parent opening this is thinking about a specific
/// child, and the screen answers "where is she right now" without them reading
/// past a sibling to find her.
class HomeScreen extends StatelessWidget {
  final ApiClient api;
  final FamilyCard child;
  final int siblingCount;
  final Future<void> Function() onRefresh;
  final VoidCallback onSwitchChild;

  const HomeScreen({
    required this.api,
    required this.child,
    required this.siblingCount,
    required this.onRefresh,
    required this.onSwitchChild,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      color: Z.teal,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        children: [
          _header(context),
          const SizedBox(height: 16),
          _statusCard(context),
          const SizedBox(height: 16),
          _actions(context),
        ],
      ),
    );
  }

  Widget _header(BuildContext context) {
    final now = DateTime.now();
    final greeting = switch (now.hour) {
      < 12 => 'Good morning',
      < 17 => 'Good afternoon',
      _ => 'Good evening',
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 20, 0, 4),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const ZippiWordmark(),
              // The avatar doubles as the switcher when there is more than one
              // child — the most reachable spot on the screen for the action a
              // two-child parent takes most often.
              if (siblingCount > 1)
                InkWell(
                  onTap: onSwitchChild,
                  borderRadius: BorderRadius.circular(Z.rPill),
                  child: Container(
                    height: 44,
                    padding: const EdgeInsets.fromLTRB(6, 0, 14, 0),
                    decoration: BoxDecoration(
                      color: Z.surface,
                      borderRadius: BorderRadius.circular(Z.rPill),
                      border: Border.all(color: Z.cardBorder, width: 1.5),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        InitialAvatar(child.name, size: 32),
                        const SizedBox(width: 8),
                        Text('Switch',
                            style: Z.text(13,
                                color: Z.teal, weight: FontWeight.w800)),
                      ],
                    ),
                  ),
                )
              else
                InitialAvatar(child.name, size: 44),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 8, 0, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(greeting, style: Z.head(26)),
              const SizedBox(height: 2),
              Text(
                '${DateFormat('EEE d MMM').format(now)} · '
                '${child.name}'
                '${child.grade.isEmpty ? '' : ' · Grade ${child.grade}'}',
                style: Z.text(14, color: Z.muted),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _statusCard(BuildContext context) {
    final trip = child.trip;

    return ZCard(
      onTap: () => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ChildScreen(api: api, childId: child.childId),
      )),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              InitialAvatar(child.name),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(child.name, style: Z.head(19)),
                    Text(
                      [
                        if (child.bellTier != null) child.bellTier!,
                        if (trip?.route.isNotEmpty ?? false) trip!.route,
                      ].join(' · '),
                      style: Z.text(13, color: Z.muted),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              StatusChip.forCard(child),
              if (trip != null)
                StatusChip(
                  trip.direction,
                  dot: false,
                  tone: child.isMorning ? ChipTone.morning : ChipTone.afternoon,
                ),
            ],
          ),
          if (child.stop != null) ...[
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: Z.bg,
                borderRadius: BorderRadius.circular(Z.rField),
              ),
              child: Column(
                children: [
                  _miniRow(child.isMorning ? 'Pickup stop' : 'Drop stop',
                      child.stop!.name),
                  const SizedBox(height: 8),
                  _miniRow(
                    'Scheduled',
                    fmtTime(child.stop!.scheduledAt),
                    valueColor: Z.coralText,
                    bold: true,
                  ),
                ],
              ),
            ),
          ] else if (trip == null) ...[
            const SizedBox(height: 14),
            Text('No trip scheduled today.',
                style: Z.text(14, color: Z.muted)),
          ],
        ],
      ),
    );
  }

  Widget _miniRow(String label, String value,
      {Color? valueColor, bool bold = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: Z.text(14, color: Z.muted)),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.right,
            style: Z.text(14,
                color: valueColor ?? Z.ink,
                weight: bold ? FontWeight.w800 : FontWeight.w700),
          ),
        ),
      ],
    );
  }

  Widget _actions(BuildContext context) {
    Future<void> push(Widget s) =>
        Navigator.of(context).push(MaterialPageRoute(builder: (_) => s));

    return Column(
      children: [
        if (child.showLiveMap) ...[
          FilledButton(
            onPressed: () =>
                push(TrackingScreen(api: api, childId: child.childId)),
            child: const Text('Track the bus'),
          ),
          const SizedBox(height: 10),
        ],
        if (child.showHandoverCode) ...[
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Z.coral,
              foregroundColor: Z.onCoral,
            ),
            onPressed: () =>
                push(PickupScreen(api: api, childId: child.childId)),
            child: const Text('Show handover code'),
          ),
          const SizedBox(height: 10),
        ],
        OutlinedButton(
          onPressed: () => push(ChildScreen(api: api, childId: child.childId)),
          child: Text("${child.name.split(' ').first}'s day"),
        ),
        const SizedBox(height: 10),
        OutlinedButton(
          onPressed: () => push(JourneyScreen(
            api: api,
            childId: child.childId,
            childName: child.name,
          )),
          child: const Text('Journey history'),
        ),
        const SizedBox(height: 10),
        OutlinedButton(
          style: OutlinedButton.styleFrom(
            foregroundColor: child.absent ? Z.teal : Z.coralText,
            side: BorderSide(
                color: child.absent ? Z.turquoise : Z.coral, width: 2),
          ),
          onPressed: () async {
            await push(AbsentScreen(
              api: api,
              cards: [child],
              initialChildId: child.childId,
            ));
            await onRefresh();
          },
          child: Text(child.absent ? 'Change absence' : 'Mark absent'),
        ),
      ],
    );
  }
}
