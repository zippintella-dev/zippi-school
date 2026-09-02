import 'package:flutter/material.dart';

import '../models/family_card.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Screen 10 of the canvas — "Log out".
///
/// A confirm screen rather than a menu item, because signing out of this app
/// has a consequence a parent will not think of: the alerts and the daily
/// handover code stop. The warning names the child's next ride so the cost is
/// concrete rather than abstract.
class LogoutScreen extends StatelessWidget {
  final FamilyCard child;
  final VoidCallback onSignOut;

  const LogoutScreen({required this.child, required this.onSignOut, super.key});

  @override
  Widget build(BuildContext context) {
    // The child's next ride, if any — that is what the parent loses sight of.
    final ride = !child.absent && child.trip != null ? child.stop?.scheduledAt : null;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            ScreenHeader('Log out', onBack: () => Navigator.of(context).pop()),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                children: [
                  ZCard(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 18, vertical: 22),
                    child: Column(
                      children: [
                        Container(
                          width: 64,
                          height: 64,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: Z.coralSoft,
                            shape: BoxShape.circle,
                            border: Border.all(color: Z.coral, width: 2),
                          ),
                          child: Text(
                              child.name.trim().isEmpty
                                  ? '?'
                                  : child.name.trim()[0].toUpperCase(),
                              style: Z.text(22,
                                  color: Z.coralText,
                                  weight: FontWeight.w800)),
                        ),
                        const SizedBox(height: 10),
                        Text(child.name, style: Z.head(19)),
                        Text(
                          child.school ?? '',
                          textAlign: TextAlign.center,
                          style: Z.text(13, color: Z.muted),
                        ),
                        const SizedBox(height: 12),
                        Text('Log out of Zippi on this phone?',
                            textAlign: TextAlign.center, style: Z.head(19)),
                        const SizedBox(height: 8),
                        Text(
                          'You will stop getting bus alerts and the daily '
                          'handover code until you log in again.',
                          textAlign: TextAlign.center,
                          style: Z.text(14, color: Z.muted),
                        ),
                      ],
                    ),
                  ),
                  if (ride != null) ...[
                    const SizedBox(height: 16),
                    NoteBanner(
                      '${child.name} rides '
                      '${child.isMorning ? 'to school' : 'home'} at '
                      '${fmtTime(ride)} today. '
                      'Alerts stop the moment you log out.',
                    ),
                  ],
                  const SizedBox(height: 16),
                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: Z.coral,
                      foregroundColor: Z.onCoral,
                    ),
                    onPressed: () {
                      Navigator.of(context).pop();
                      onSignOut();
                    },
                    child: const Text('Log out'),
                  ),
                  const SizedBox(height: 10),
                  OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Cancel — stay logged in'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
