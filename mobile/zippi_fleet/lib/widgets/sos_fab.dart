import 'package:flutter/material.dart';

import '../screens/sos_screen.dart';
import '../theme.dart';

/// The SOS button that sits on every trip screen, for both roles.
///
/// ⚠ ALWAYS VISIBLE, NEVER BEHIND A MENU. The moment it is needed is the
/// moment nobody is going to hunt for it. It is a coral pill rather than a red
/// one so that red stays reserved for the state *after* it fires — a screen
/// where everything is already red has nothing left to escalate to.
///
/// Tapping it opens the SOS screen. It does not fire anything: the alarm is the
/// 2-second hold on that screen, not this control.
class SosFab extends StatelessWidget {
  const SosFab({super.key});

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: 'Open emergency screen',
      child: Material(
        color: Z.coral,
        borderRadius: BorderRadius.circular(Z.rPill),
        elevation: 6,
        shadowColor: Z.coralText.withValues(alpha: 0.5),
        child: InkWell(
          borderRadius: BorderRadius.circular(Z.rPill),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const SosScreen()),
          ),
          child: Container(
            height: Z.tapSafety,
            padding: const EdgeInsets.symmetric(horizontal: 22),
            alignment: Alignment.center,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.warning_amber_rounded,
                    size: 22, color: Z.onCoral),
                const SizedBox(width: 8),
                Text('SOS', style: Z.head(16, color: Z.onCoral)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
