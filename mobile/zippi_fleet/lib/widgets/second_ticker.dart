import 'dart:async';

import 'package:flutter/widgets.dart';

/// Rebuilds its subtree once a second.
///
/// Several things on these screens are countdowns the crew is waiting on: the
/// 120-second stop wait before "Not at stop" unlocks, the 90-second undo
/// window, the escalation ladder, the minutes to departure. Each one is a
/// number someone is watching, so it has to move.
///
/// ⚠ Nothing here decides anything. The timer only repaints; every rule is
/// re-evaluated from `DateTime.now()` against a server-issued timestamp inside
/// [FleetStore]. A dropped tick, a backgrounded app or a device asleep in a
/// pocket therefore cannot leave a lock open or a window stuck shut — the
/// worst it costs is a stale number until the next frame.
class SecondTicker extends StatefulWidget {
  final WidgetBuilder builder;

  const SecondTicker({required this.builder, super.key});

  @override
  State<SecondTicker> createState() => _SecondTickerState();
}

class _SecondTickerState extends State<SecondTicker> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.builder(context);
}
