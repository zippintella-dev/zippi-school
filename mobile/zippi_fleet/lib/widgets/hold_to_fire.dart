import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';
import '../theme.dart';

/// SOS is a 2-second long-press with haptic confirmation, never a tap.
///
/// ⚠ A TAP MUST DO NOTHING, AND THAT IS THE FEATURE. The SOS control sits on
/// screens the crew uses all day, on a phone bouncing in a moving bus. A tap
/// target that dispatches a control room and alerts a school will be fired by
/// accident, and an alarm that cries wolf is an alarm nobody drives to.
///
/// The ring fills as the hold progresses so the gesture is discoverable
/// without instruction, and the phone buzzes when it fires because at that
/// moment the person holding it is looking at the road, not the screen.
class HoldToFire extends StatefulWidget {
  final VoidCallback onFired;

  /// Banded "THIS IS A DRILL" upstream. The gesture itself is identical — a
  /// drill that behaves differently trains the wrong reflex.
  final bool drill;

  final double size;

  const HoldToFire({
    required this.onFired,
    this.drill = false,
    this.size = 200,
    super.key,
  });

  @override
  State<HoldToFire> createState() => _HoldToFireState();
}

class _HoldToFireState extends State<HoldToFire>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: Config.sosHold,
    // A release does not snap back instantly — a hand jolted off the glass by
    // a pothole should not lose a full second of hold.
    reverseDuration: const Duration(milliseconds: 400),
  )..addStatusListener(_onStatus);

  bool _fired = false;

  void _onStatus(AnimationStatus s) {
    if (s == AnimationStatus.completed && !_fired) {
      _fired = true;
      HapticFeedback.heavyImpact();
      widget.onFired();
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  void _down(_) {
    if (_fired) return;
    _c.forward();
  }

  void _up([_]) {
    if (_fired) return;
    _c.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final inner = widget.size * 0.88;

    return Listener(
      onPointerDown: _down,
      onPointerUp: _up,
      onPointerCancel: _up,
      child: Semantics(
        button: true,
        label: widget.drill
            ? 'Drill S O S. Press and hold for two seconds.'
            : 'S O S. Press and hold for two seconds.',
        child: AnimatedBuilder(
          animation: _c,
          builder: (context, _) => SizedBox(
            width: widget.size,
            height: widget.size,
            child: CustomPaint(
              painter: _RingPainter(_c.value),
              child: Center(
                child: Container(
                  width: inner,
                  height: inner,
                  decoration: BoxDecoration(
                    color: Z.red,
                    shape: BoxShape.circle,
                    boxShadow: [
                      BoxShadow(
                        color: Z.red.withValues(alpha: 0.35),
                        blurRadius: 24,
                        offset: const Offset(0, 6),
                      ),
                    ],
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text('SOS',
                          style: Z.head(36, color: Colors.white)
                              .copyWith(letterSpacing: 2)),
                      const SizedBox(height: 2),
                      Text(
                        _c.value > 0 && _c.value < 1
                            ? 'KEEP HOLDING'
                            : 'HOLD 2 SECONDS',
                        style: Z.text(13,
                            color: Z.redBg, weight: FontWeight.w800),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The progress ring behind the button.
class _RingPainter extends CustomPainter {
  final double progress;

  const _RingPainter(this.progress);

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final stroke = size.width * 0.06;

    final track = Paint()
      ..color = Z.coralSoft
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;

    final fill = Paint()
      ..color = Z.red
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round;

    final inner = rect.deflate(stroke / 2);

    canvas.drawArc(inner, 0, math.pi * 2, false, track);

    if (progress > 0) {
      canvas.drawArc(
          inner, -math.pi / 2, math.pi * 2 * progress, false, fill);
    }
  }

  @override
  bool shouldRepaint(covariant _RingPainter old) => old.progress != progress;
}
