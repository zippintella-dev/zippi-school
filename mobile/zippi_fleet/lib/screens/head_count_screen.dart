import 'package:flutter/material.dart';

import '../models/trip.dart';
import '../services/fleet_scope.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/numeric_keypad.dart';
import 'arrival_screen.dart';

/// Screen 7 — Head-count reconciliation. Morning, after the last kerb.
///
/// ⚠ THIS IS THE ONLY CHECK THAT CATCHES THE WRONG-ROW MIS-TAP, and it is the
/// reason the screen exists. Everything else in the flow is self-consistent
/// with a mistake: the roster says the child boarded, the parent got a
/// boarding notification, the count on the header matches the marks. Only a
/// physical count of actual children disagrees.
///
/// So the attendant is asked to walk the aisle and count heads — not to
/// confirm a number the app already knows. The app's number is deliberately
/// not pre-filled.
class HeadCountScreen extends StatefulWidget {
  const HeadCountScreen({super.key});

  @override
  State<HeadCountScreen> createState() => _HeadCountScreenState();
}

class _HeadCountScreenState extends State<HeadCountScreen> {
  String _entry = '';

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;
    final marked = session.onBoard;
    final result = session.headCountMatched;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ScreenHeader('Head count',
                onBack: () => Navigator.of(context).pop()),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
                children: [
                  Text.rich(
                    TextSpan(
                      text: 'You marked ',
                      children: [
                        TextSpan(
                          text: '$marked '
                              '${marked == 1 ? 'child' : 'children'}',
                          style: Z.text(15,
                              color: Z.ink, weight: FontWeight.w800),
                        ),
                        const TextSpan(text: ' boarded. Walk the aisle and '),
                        TextSpan(
                          text: 'count the children on the bus',
                          style: Z.text(15,
                              color: Z.ink, weight: FontWeight.w800),
                        ),
                        const TextSpan(text: '.'),
                      ],
                    ),
                    style: Z.text(15, color: Z.ink).copyWith(height: 1.5),
                  ),
                  const SizedBox(height: 18),
                  Center(
                    child: Container(
                      width: 150,
                      height: 88,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: Z.surface,
                        borderRadius: BorderRadius.circular(Z.rRow),
                        border: Border.all(color: Z.turquoise, width: 2),
                      ),
                      child: Text(_entry.isEmpty ? '–' : _entry,
                          style: Z.number(44)),
                    ),
                  ),
                  const SizedBox(height: 20),
                  if (result == null) ...[
                    NumericKeypad(
                      onDigit: (d) {
                        // Two digits is plenty — a school bus is not carrying
                        // a hundred children, and a stray third keystroke
                        // producing "182" wastes a recount.
                        if (_entry.length < 2) setState(() => _entry += d);
                      },
                      onClear: () => setState(() => _entry = ''),
                      onBackspace: () => setState(() => _entry = _entry.isEmpty
                          ? _entry
                          : _entry.substring(0, _entry.length - 1)),
                    ),
                    const SizedBox(height: 14),
                    FilledButton(
                      onPressed: _entry.isEmpty
                          ? null
                          : () {
                              store.submitHeadCount(int.parse(_entry));
                              setState(() {});
                            },
                      child: const Text('Check count'),
                    ),
                  ] else if (result) ...[
                    ZBanner(
                      'Counts match — $marked marked, $marked counted',
                      body: 'The trip can go on to school.',
                      tone: Tone.good,
                      icon: Icons.check_rounded,
                    ),
                    const SizedBox(height: 14),
                    FilledButton(
                      onPressed: () => Navigator.of(context).pushReplacement(
                        MaterialPageRoute(
                            builder: (_) => const ArrivalScreen()),
                      ),
                      child: const Text('Continue to school →'),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: _recount,
                      child: const Text('Recount'),
                    ),
                  ] else ...[
                    ZBanner(
                      'Counts do not match',
                      body: 'You marked $marked but counted '
                          '${session.headCountEntered}. Check each face below '
                          'against the bus before going on.',
                      tone: Tone.danger,
                      emphatic: true,
                    ),
                    const SizedBox(height: 14),
                    // ⚠ Faces, not a list of names. The recheck only works if
                    // the attendant can look from the grid to the aisle and
                    // back; names in a column are re-read, not re-checked.
                    _BoardedGrid(
                      children: session.children
                          .where((c) => c.state == ChildState.boarded)
                          .toList(),
                    ),
                    const SizedBox(height: 14),
                    OutlinedButton(
                      onPressed: _recount,
                      child: const Text('Recount'),
                    ),
                    const SizedBox(height: 10),
                    Text(
                      'If a child is marked but not on the bus, undo them at '
                      'their stop or call ops. The trip cannot go on with a '
                      'count that does not add up.',
                      textAlign: TextAlign.center,
                      style: Z.text(13, color: Z.muted).copyWith(height: 1.5),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _recount() {
    FleetScope.of(context).clearHeadCount();
    setState(() => _entry = '');
  }
}

class _BoardedGrid extends StatelessWidget {
  final List<TripChild> children;

  const _BoardedGrid({required this.children});

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        crossAxisSpacing: 8,
        mainAxisSpacing: 8,
        childAspectRatio: 0.82,
      ),
      itemCount: children.length,
      itemBuilder: (context, i) {
        final child = children[i];

        return Container(
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
          decoration: BoxDecoration(
            color: Z.surface,
            borderRadius: BorderRadius.circular(Z.rField),
            border: Border.all(color: Z.cardBorder, width: 1.5),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              ChildAvatar(child, size: 44),
              const SizedBox(height: 6),
              Text(child.name,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Z.text(12, color: Z.ink, weight: FontWeight.w800)),
              Text(child.className,
                  style: Z.text(11, color: Z.muted)),
            ],
          ),
        );
      },
    );
  }
}
