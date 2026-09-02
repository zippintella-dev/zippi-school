import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';
import '../models/trip.dart';
import '../services/fleet_api.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import 'numeric_keypad.dart';

/// PART A7 (extended) — the morning boarding keypad.
///
/// The attendant asks the guardian for the 4-digit boarding code from their
/// Parent app before the child gets on. Deliberately modelled on the afternoon
/// handover pad in drop_stop_screen so a crew member who has used one already
/// knows this one.
///
/// ⚠⚠ THE ONE PLACE THIS DIFFERS FROM THE AFTERNOON IS THE ONE THAT MATTERS.
///
/// The afternoon pad has no way out that leaves a child at a kerb: if no code
/// works, the escalation ladder runs and the bus returns to school with the
/// child safely aboard. Refusing is the safe direction there.
///
/// In the morning it is the reverse. The child is ON THE PAVEMENT and the bus is
/// about to go. A pad that could only refuse would strand a seven-year-old
/// because a parent's phone was flat — and it would do that most often to the
/// families least able to absorb it. The risk the code addresses (wrong child,
/// wrong bus) is already covered by the PART F2 photo roster underneath it.
///
/// So this sheet always offers *Board on the photo roster instead*. That is not
/// a bypass and not a failure state: the server records which route was taken,
/// and raises an ops event when a code was tried, failed, and the child was
/// boarded anyway. The verification is real, and the child still gets on the bus.
///
/// Returns true if the child was boarded, false or null if the attendant backed
/// out without boarding anyone.
Future<bool?> showBoardingCodeSheet(
  BuildContext context,
  FleetStore store,
  TripChild child,
) {
  return showModalBottomSheet<bool>(
    context: context,
    backgroundColor: Z.bg,
    showDragHandle: true,
    isScrollControlled: true,
    isDismissible: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(Z.rCard)),
    ),
    builder: (sheet) => _BoardingCodeSheet(store: store, child: child),
  );
}

class _BoardingCodeSheet extends StatefulWidget {
  final FleetStore store;
  final TripChild child;

  const _BoardingCodeSheet({required this.store, required this.child});

  @override
  State<_BoardingCodeSheet> createState() => _BoardingCodeSheetState();
}

class _BoardingCodeSheetState extends State<_BoardingCodeSheet> {
  String _code = '';
  int _attempts = 0;
  bool _locked = false;
  bool _submitting = false;
  String? _error;

  int get _left => Config.boardingAttempts - _attempts;

  /// Sends the boarding. Returns the server's sentence on refusal, or null on
  /// success.
  ///
  /// ⚠ The message is rendered VERBATIM (PART P7). The server knows how many
  /// attempts are really left and this device does not, so its wording wins over
  /// anything composed here.
  Future<String?> _submit(BoardingMethod method, {String? code}) async {
    try {
      await widget.store.markBoarded(
        widget.child.id,
        method: method,
        code: code,
      );
      return null;
    } on SafetyViolation catch (e) {
      return e.message;
    } on FleetTransportException catch (e) {
      // ⚠ A transport failure is NOT a refusal. A crashed or unreachable server
      // has not decided this child may not board, and showing "code rejected"
      // here would have the attendant re-collecting a code that was fine.
      return e.message;
    }
  }

  Future<void> _boardOnRoster() async {
    if (_submitting) return;
    setState(() => _submitting = true);

    final error = await _submit(BoardingMethod.rosterPhoto);

    if (!mounted) return;

    if (error == null) {
      Navigator.of(context).pop(true);
      return;
    }

    setState(() {
      _submitting = false;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final child = widget.child;

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          4,
          20,
          20 + MediaQuery.of(context).viewInsets.bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Boarding ${child.name}',
                textAlign: TextAlign.center,
                style: Z.text(17, weight: FontWeight.w900),
              ),
              const SizedBox(height: 2),
              // The roster line stays visible while the code is typed. It is the
              // check that is actually catching a wrong child, and hiding it
              // behind a keypad would trade the reliable check for the new one.
              Text(
                '${child.className}'
                '${child.distinguishingDetail.isEmpty ? '' : ' · ${child.distinguishingDetail}'}',
                textAlign: TextAlign.center,
                style: Z.text(13, color: Z.muted),
              ),
              const SizedBox(height: 14),
              CodeBoxes(length: 4, filled: _code.length, locked: _locked),
              const SizedBox(height: 12),
              Text(
                _locked
                    ? 'Locked — ${Config.boardingAttempts} wrong attempts. '
                        'Board ${child.firstName} on the photo roster instead.'
                    : _error ??
                        (_attempts > 0
                            ? 'Wrong code · $_left ${_left == 1 ? 'attempt' : 'attempts'} left'
                            : 'Ask the guardian for the 4-digit boarding code '
                                'from their Parent app'),
                textAlign: TextAlign.center,
                style: Z.text(
                  13,
                  color: _locked
                      ? Z.red
                      : _attempts > 0
                          ? Z.coralText
                          : Z.muted,
                  weight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 14),
              NumericKeypad(
                enabled: !_locked && !_submitting,
                onClear: () => setState(() => _code = ''),
                onBackspace: () => setState(() => _code =
                    _code.isEmpty ? _code : _code.substring(0, _code.length - 1)),
                onDigit: (d) async {
                  if (_locked || _submitting) return;

                  // Captured BEFORE the await. Inside this closure `context` is
                  // build's parameter, not State.context, so the `mounted`
                  // check below does not vouch for it.
                  final navigator = Navigator.of(context);

                  final next = _code + d;

                  if (next.length < 4) {
                    setState(() {
                      _code = next;
                      _error = null;
                    });
                    return;
                  }

                  setState(() {
                    _code = next;
                    _submitting = true;
                  });

                  final error =
                      await _submit(BoardingMethod.code, code: next);

                  if (!mounted) return;

                  if (error == null) {
                    HapticFeedback.selectionClick();
                    navigator.pop(true);
                    return;
                  }

                  setState(() {
                    _submitting = false;
                    _code = '';
                    _attempts++;
                    _error = error;
                    // Locks the pad for THIS CHILD only. The roster route stays
                    // open, which is the entire point.
                    if (_attempts >= Config.boardingAttempts) _locked = true;
                  });
                },
              ),
              const SizedBox(height: 16),
              // ⚠ ALWAYS PRESENT, never hidden behind the lockout. A guardian
              // with a flat phone has no code to give on the first attempt
              // either, and making the attendant type four wrong digits five
              // times to reach this button would be a worse design than not
              // having the code at all.
              OutlinedButton(
                onPressed: _submitting ? null : _boardOnRoster,
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(Z.tapSafety),
                ),
                child: Text('No code — board ${child.firstName} on the photo roster'),
              ),
              const SizedBox(height: 8),
              Text(
                'Recorded either way. The school sees which children boarded '
                'without a code.',
                textAlign: TextAlign.center,
                style: Z.text(12, color: Z.faint).copyWith(height: 1.4),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
