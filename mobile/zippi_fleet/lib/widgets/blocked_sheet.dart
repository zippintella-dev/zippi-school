import 'package:flutter/material.dart';

import '../services/fleet_api.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import 'common.dart';

/// Runs a mutating action and turns the two kinds of failure into the two
/// different things they mean.
///
/// ⚠ A REFUSAL AND A DEAD ZONE ARE NOT THE SAME EVENT, and the crew must never
/// have to guess which one they are looking at:
///
///   · [SafetyViolation] — the rules said no, locally or on the server. It gets
///     the blocking sheet, because it has to be read and dismissed.
///   · [FleetTransportException] — nobody said anything; the request did not
///     arrive. It gets a snackbar that says so plainly and does NOT claim the
///     action succeeded. The route passes through dead zones and this is an
///     ordinary event, not an alarm.
///
/// Returns true only when the action actually completed.
Future<bool> runGuarded(
  BuildContext context,
  Future<void> Function() action, {
  Widget? blockedAction,
}) async {
  try {
    await action();
    return true;
  } on SafetyViolation catch (e) {
    if (context.mounted) showBlocked(context, e, action: blockedAction);
    return false;
  } on FleetTransportException catch (e) {
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.message)),
      );
    }
    return false;
  }
}

/// The one way a [SafetyViolation] reaches a person.
///
/// ⚠ A SHEET, NOT A SNACKBAR. A refusal that slides away after four seconds is
/// a refusal that gets missed by somebody counting children with one hand on a
/// rail. This has to be dismissed deliberately.
///
/// ⚠ It says WHY and WHAT NEXT. "Cannot complete: Aarav Mehta is still on
/// board" beats "Error", and [action] is where the way forward goes — a button
/// straight to Aarav's drop stop, not an instruction to go and find it.
Future<void> showBlocked(
  BuildContext context,
  SafetyViolation e, {
  Widget? action,
}) {
  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: Z.bg,
    showDragHandle: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(Z.rCard)),
    ),
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ZBanner(e.message, tone: Tone.danger, emphatic: true),
            if (action != null) ...[const SizedBox(height: 12), action],
            const SizedBox(height: 12),
            OutlinedButton(
              onPressed: () => Navigator.of(sheetContext).pop(),
              child: const Text('Back'),
            ),
          ],
        ),
      ),
    ),
  );
}
