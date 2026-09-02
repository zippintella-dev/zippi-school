import 'package:flutter/material.dart';

import '../models/duty.dart';
import '../services/demo_data.dart';
import '../services/fleet_scope.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Screen 2 — Role & vehicle.
///
/// ⚠ ONE PHONE, MANY ROLES (PART P6). The same person can be an attendant on
/// one bus, a driver on another, and a parent at the same school. The identity
/// is one; the role links are many, and which one is active decides what this
/// app will let them do for the rest of the day.
///
/// Today's roster assignment is pre-selected, so the ordinary morning is one
/// tap. The picker exists for the day it is not ordinary.
class RoleScreen extends StatefulWidget {
  const RoleScreen({super.key});

  @override
  State<RoleScreen> createState() => _RoleScreenState();
}

class _RoleScreenState extends State<RoleScreen> {
  CrewAssignment? _selected;

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);

    // ⚠ THE LINKS COME FROM SIGN-IN, NOT FROM A GUESS. Each carries its own
    // bearer token, so picking a card here is literally picking which token
    // every later request is sent with — which is why the driver/attendant
    // split cannot be spoofed by a client.
    final options = store.roleLinks.isEmpty ? DemoData.assignments : store.roleLinks;

    final selected = _selected ??
        options.firstWhere((a) => a.isToday, orElse: () => options.first);

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 24, 20, 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Who are you today?', style: Z.head(26)),
                  const SizedBox(height: 4),
                  Text('Signed in as ${store.crewName} · ${store.phone}',
                      style: Z.text(14, color: Z.muted)),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
                children: [
                  for (final a in options) ...[
                    _AssignmentCard(
                      assignment: a,
                      selected: a == selected,
                      onTap: () => setState(() => _selected = a),
                    ),
                    const SizedBox(height: 14),
                  ],
                  // The Fleet app is never where a crew member's own children
                  // appear. Two roles on one screen is how somebody marks their
                  // own child boarded on a bus they are not on.
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 16, vertical: 14),
                    decoration: BoxDecoration(
                      color: Z.bg,
                      borderRadius: BorderRadius.circular(Z.rField),
                      border: Border.all(
                        color: Z.cardBorder,
                        width: 1.5,
                        strokeAlign: BorderSide.strokeAlignInside,
                      ),
                    ),
                    child: Text.rich(
                      TextSpan(
                        text: 'Also a parent? Your own children live in the ',
                        children: [
                          TextSpan(
                            text: 'Zippi Parent',
                            style: Z.text(13,
                                color: Z.teal, weight: FontWeight.w700),
                          ),
                          const TextSpan(text: ' app.'),
                        ],
                      ),
                      style: Z.text(13, color: Z.muted),
                    ),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
              child: FilledButton(
                onPressed: store.busy
                    ? null
                    : () => store.chooseAssignment(selected),
                child: Text(
                    'Continue as ${selected.role.label.toLowerCase()}'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AssignmentCard extends StatelessWidget {
  final CrewAssignment assignment;
  final bool selected;
  final VoidCallback onTap;

  const _AssignmentCard({
    required this.assignment,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final isAttendant = assignment.role == FleetRole.attendant;

    return ZCard(
      onTap: onTap,
      color: selected ? Z.tealSoft : Z.surface,
      borderColor: selected ? Z.turquoise : Z.cardBorder,
      borderWidth: selected ? 2 : 1.5,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration:
                    const BoxDecoration(color: Z.bg, shape: BoxShape.circle),
                child: Icon(
                  isAttendant
                      ? Icons.person_rounded
                      : Icons.airline_seat_recline_normal_rounded,
                  color: isAttendant ? Z.teal : Z.coralText,
                  size: 26,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(assignment.role.label, style: Z.head(19)),
                    const SizedBox(height: 2),
                    Text(
                      assignment.isToday
                          ? "Today's assignment"
                          : '${assignment.routeCode} · ${assignment.routeName}',
                      style: Z.text(13,
                          color: assignment.isToday ? Z.teal : Z.muted,
                          weight: FontWeight.w800),
                    ),
                  ],
                ),
              ),
              // Selection is a mark AND the card's border and fill — a tick
              // alone is a 28dp cue on a screen read at arm's length.
              if (selected)
                Container(
                  width: 28,
                  height: 28,
                  decoration: const BoxDecoration(
                      color: Z.turquoise, shape: BoxShape.circle),
                  child: const Icon(Icons.check_rounded,
                      size: 18, color: Z.onTurquoise),
                ),
            ],
          ),
          const SizedBox(height: 6),
          // ⚠ A role link carries no route or bus — those belong to TODAY's
          // trips, which the duty board fetches once a role is chosen. Showing
          // a remembered vehicle here would show a driver moved at 6 AM the bus
          // they had yesterday.
          if (assignment.routeCode.isNotEmpty)
            DetailRow('Route',
                '${assignment.routeCode} · ${assignment.schoolName}')
          else
            DetailRow('School', assignment.schoolName),
          if (assignment.busRegistration.isNotEmpty)
            DetailRow('Bus', assignment.busRegistration),
          if (assignment.firstBell.isNotEmpty)
            DetailRow('First bell', assignment.firstBell, last: true),
          if (!isAttendant) ...[
            const SizedBox(height: 6),
            Text('No child marking on a driver device.',
                style: Z.text(12, color: Z.faint, weight: FontWeight.w700)),
          ],
        ],
      ),
    );
  }
}
