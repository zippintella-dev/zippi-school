import 'package:flutter/material.dart';

import '../theme.dart';

/// The on-screen number pad used for the head count and the handover code.
///
/// ⚠ IT IS NOT THE SYSTEM KEYBOARD, DELIBERATELY. Gboard's number row is 8dp
/// tall keys at the top of a full QWERTY, it steals half the screen, and it
/// offers autocorrect and voice input to someone typing a child's release code
/// at a kerb. These are 56dp keys, the whole pad is thumb-reachable one-handed,
/// and nothing else can be typed into it.
class NumericKeypad extends StatelessWidget {
  final ValueChanged<String> onDigit;
  final VoidCallback onClear;
  final VoidCallback? onBackspace;

  /// Set false to grey the pad out — a locked handover keypad, for instance.
  final bool enabled;

  const NumericKeypad({
    required this.onDigit,
    required this.onClear,
    this.onBackspace,
    this.enabled = true,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (final row in const [
          ['1', '2', '3'],
          ['4', '5', '6'],
          ['7', '8', '9'],
        ])
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              children: [
                for (final d in row) ...[
                  Expanded(child: _key(d)),
                  if (d != row.last) const SizedBox(width: 10),
                ],
              ],
            ),
          ),
        Row(
          children: [
            Expanded(
              child: _action(
                label: 'Clear',
                onTap: enabled ? onClear : null,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(child: _key('0')),
            const SizedBox(width: 10),
            Expanded(
              child: _action(
                icon: Icons.backspace_outlined,
                semantics: 'Delete last digit',
                onTap: enabled ? onBackspace : null,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _key(String d) => _Cell(
        onTap: enabled ? () => onDigit(d) : null,
        child: Text(d, style: Z.head(22, color: enabled ? Z.ink : Z.faint)),
      );

  Widget _action({
    String? label,
    IconData? icon,
    String? semantics,
    VoidCallback? onTap,
  }) =>
      _Cell(
        onTap: onTap,
        background: Z.bg,
        child: Semantics(
          label: semantics ?? label,
          child: icon != null
              ? Icon(icon, size: 22, color: onTap == null ? Z.faint : Z.muted)
              : Text(label!,
                  style: Z.text(14,
                      color: onTap == null ? Z.faint : Z.muted,
                      weight: FontWeight.w800)),
        ),
      );
}

class _Cell extends StatelessWidget {
  final Widget child;
  final VoidCallback? onTap;
  final Color background;

  const _Cell({
    required this.child,
    required this.onTap,
    this.background = Z.surface,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: background,
      borderRadius: BorderRadius.circular(Z.rField),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Z.rField),
        child: Container(
          // 56dp. Never smaller — this pad decides whether a child is released.
          height: Z.tapSafety,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(Z.rField),
            border: Border.all(color: Z.cardBorder, width: 1.5),
          ),
          child: child,
        ),
      ),
    );
  }
}

/// The 4 boxes a code is typed into. Filled boxes show a dot, not the digit —
/// the code is read aloud at a kerb with people standing around.
class CodeBoxes extends StatelessWidget {
  final int length;
  final int filled;
  final bool locked;

  const CodeBoxes(
      {required this.length,
      required this.filled,
      this.locked = false,
      super.key});

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '$filled of $length digits entered',
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: List.generate(length, (i) {
          final isFilled = i < filled;
          final isNext = i == filled && !locked;

          return Padding(
            padding: EdgeInsets.only(right: i == length - 1 ? 0 : 10),
            child: Container(
              width: 56,
              height: 60,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: Z.surface,
                borderRadius: BorderRadius.circular(Z.rOtp),
                border: Border.all(
                  color: locked
                      ? Z.redBorder
                      : (isNext ? Z.turquoise : Z.cardBorder),
                  width: isNext ? 2 : 1.5,
                ),
              ),
              child: isFilled
                  ? Container(
                      width: 12,
                      height: 12,
                      decoration:
                          const BoxDecoration(color: Z.ink, shape: BoxShape.circle),
                    )
                  : null,
            ),
          );
        }),
      ),
    );
  }
}
