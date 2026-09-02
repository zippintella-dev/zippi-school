import 'package:flutter/material.dart';

import '../models/family_card.dart';
import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'logout_screen.dart';

/// You — the selected student's record, the switcher, and log out.
///
/// Read-only by design. A parent may not edit a child's grade, route or bus:
/// that is the school's record, and letting the family app write to it would
/// put the roster and the trip generator out of step.
///
/// ⚠ Shows ONLY the selected child. The sibling count is passed as a number
/// rather than a list precisely so this screen cannot render another child's
/// details by accident.
class YouScreen extends StatelessWidget {
  final ApiClient api;
  final FamilyCard child;
  final int siblingCount;
  final VoidCallback onSwitchChild;
  final VoidCallback onSignOut;

  const YouScreen({
    required this.api,
    required this.child,
    required this.siblingCount,
    required this.onSwitchChild,
    required this.onSignOut,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final trip = child.trip;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 20, 0, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Your student', style: Z.head(26)),
              const SizedBox(height: 2),
              Text(child.school ?? '', style: Z.text(14, color: Z.muted)),
            ],
          ),
        ),

        ZCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  InitialAvatar(child.name, size: 56),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(child.name, style: Z.head(20)),
                        Text(child.school ?? '—',
                            style: Z.text(13, color: Z.muted)),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              DetailRow('Grade', child.grade.isEmpty ? '—' : 'Grade ${child.grade}'),
              DetailRow('Bell tier', child.bellTier ?? '—'),
              DetailRow('Route', trip?.route ?? 'Not assigned today'),
              DetailRow('Stop', child.stop?.name ?? '—'),
              DetailRow('Bus', trip?.bus?.regNo ?? '—', last: true),
            ],
          ),
        ),

        if (siblingCount > 1) ...[
          const SizedBox(height: 16),
          OutlinedButton.icon(
            icon: const Icon(Icons.swap_horiz_rounded, size: 20),
            onPressed: onSwitchChild,
            label: Text(
              'Switch student · ${siblingCount - 1} other'
              '${siblingCount - 1 == 1 ? '' : 's'}',
            ),
          ),
        ],

        const SizedBox(height: 16),
        OutlinedButton(
          style: OutlinedButton.styleFrom(
            foregroundColor: Z.coralText,
            side: const BorderSide(color: Z.coral, width: 2),
          ),
          onPressed: () => Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => LogoutScreen(child: child, onSignOut: onSignOut),
          )),
          child: const Text('Log out'),
        ),
      ],
    );
  }
}
