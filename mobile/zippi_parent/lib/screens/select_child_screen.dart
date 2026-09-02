import 'package:flutter/material.dart';

import '../models/family_card.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// "Who are you checking on?" — shown after sign-in when a guardian has more
/// than one child, and again whenever they switch.
///
/// The app is scoped to ONE child at a time. A parent standing at a kerb is
/// thinking about one child, and a merged view of three makes them read to find
/// the one that matters. Everything downstream — home, activity, tracking, the
/// handover code — follows this choice.
///
/// ⚠ This is a FOCUS control, not a permission gate. Cross-family isolation is
/// enforced on the server: every child lookup resolves through the signed-in
/// guardian's own links and 404s anything else. Choosing here cannot widen what
/// a parent may see, only narrow it.
class SelectChildScreen extends StatelessWidget {
  final List<FamilyCard> cards;
  final int? currentChildId;
  final ValueChanged<FamilyCard> onSelect;

  /// Null on the post-login pass — there is nothing to go back to.
  final VoidCallback? onCancel;

  const SelectChildScreen({
    required this.cards,
    required this.onSelect,
    this.currentChildId,
    this.onCancel,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (onCancel != null)
              ScreenHeader('Switch student', onBack: onCancel)
            else
              const SizedBox(height: 12),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                children: [
                  if (onCancel == null) ...[
                    const Padding(
                      padding: EdgeInsets.only(left: 4, bottom: 4),
                      child: ZippiWordmark(),
                    ),
                    const SizedBox(height: 12),
                  ],
                  Padding(
                    padding: const EdgeInsets.only(left: 4, bottom: 4),
                    child: Text(
                      onCancel == null
                          ? 'Who are you checking on?'
                          : 'Choose a student',
                      style: Z.head(26),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.only(left: 4, bottom: 20),
                    child: Text(
                      cards.length == 1
                          ? 'You have one student on Zippi.'
                          : 'You can switch at any time from the You tab.',
                      style: Z.text(14, color: Z.muted),
                    ),
                  ),
                  for (var i = 0; i < cards.length; i++) ...[
                    _card(cards[i], i.isOdd),
                    const SizedBox(height: 14),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _card(FamilyCard c, bool coral) {
    final selected = c.childId == currentChildId;

    return ZCard(
      onTap: () => onSelect(c),
      borderColor: selected ? Z.turquoise : null,
      borderWidth: selected ? 2 : 1.5,
      child: Row(
        children: [
          InitialAvatar(c.name, size: 56, coral: coral),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(c.name, style: Z.head(20)),
                const SizedBox(height: 2),
                Text(
                  [
                    if (c.grade.isNotEmpty) 'Grade ${c.grade}',
                    if (c.bellTier != null) c.bellTier!,
                  ].join(' · '),
                  style: Z.text(13, color: Z.muted),
                ),
                const SizedBox(height: 8),
                StatusChip.forCard(c),
              ],
            ),
          ),
          Icon(
            selected ? Icons.check_circle_rounded : Icons.chevron_right_rounded,
            color: selected ? Z.turquoise : Z.faint,
          ),
        ],
      ),
    );
  }
}
