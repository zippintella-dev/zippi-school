import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme.dart';

/// Begin Trip is a swipe, not a button.
///
/// ⚠ THIS IS A SAFETY CONTROL, NOT A FLOURISH. Starting a trip pushes "the bus
/// has left" to 22 families and opens a journey record that has to be unpicked
/// by hand if it was wrong. A 56dp button at the bottom of a screen, on a phone
/// held one-handed in a moving vehicle, gets pressed by a thumb steadying the
/// device. A deliberate 85%-of-the-width drag does not.
///
/// Released short of [_threshold] it springs back. There is no tap fallback,
/// and adding one would undo the whole control.
class SwipeToBegin extends StatefulWidget {
  /// False until every pre-trip check is ticked. A disabled handle still
  /// tracks nothing — it does not slide and then refuse at the end, which
  /// would read as a broken widget rather than a locked one.
  final bool enabled;

  final String label;
  final VoidCallback onConfirmed;

  const SwipeToBegin({
    required this.enabled,
    required this.onConfirmed,
    this.label = 'Swipe to begin trip',
    super.key,
  });

  @override
  State<SwipeToBegin> createState() => _SwipeToBeginState();
}

class _SwipeToBeginState extends State<SwipeToBegin> {
  static const double _threshold = 0.85;
  static const double _track = 66;
  static const double _handle = 56;

  double _progress = 0;
  bool _dragging = false;

  void _update(double dx, double width) {
    final span = width - _handle - 10;
    if (span <= 0) return;

    setState(() => _progress = (dx - _handle / 2).clamp(0, span) / span);
  }

  void _release() {
    if (!_dragging) return;

    if (_progress >= _threshold) {
      setState(() {
        _dragging = false;
        _progress = 1;
      });

      // Confirmation you can feel. The crew is not looking at the screen while
      // the bus pulls out.
      HapticFeedback.mediumImpact();
      widget.onConfirmed();
    } else {
      setState(() {
        _dragging = false;
        _progress = 0;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, c) {
        final span = c.maxWidth - _handle - 10;
        final left = 5 + _progress * span;

        return Semantics(
          button: true,
          enabled: widget.enabled,
          label: widget.label,
          onTap: widget.enabled ? widget.onConfirmed : null,
          child: Container(
            height: _track,
            decoration: BoxDecoration(
              color: Z.tealSoft,
              borderRadius: BorderRadius.circular(Z.rPill),
              border: Border.all(color: Z.tealSoftBorder, width: 1.5),
            ),
            clipBehavior: Clip.antiAlias,
            child: Stack(
              children: [
                // The fill grows behind the handle so the gesture reads as
                // progress rather than as dragging an object around.
                Positioned.fill(
                  child: Opacity(
                    opacity: _progress * 0.9,
                    child: const ColoredBox(color: Z.turquoisePressed),
                  ),
                ),
                Center(
                  child: Text(widget.label,
                      style: Z.head(16, color: Z.teal)),
                ),
                AnimatedPositioned(
                  duration: _dragging
                      ? Duration.zero
                      : const Duration(milliseconds: 220),
                  curve: Curves.easeOut,
                  left: left,
                  top: 4,
                  child: GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onHorizontalDragStart: widget.enabled
                        ? (_) => setState(() => _dragging = true)
                        : null,
                    onHorizontalDragUpdate: widget.enabled
                        ? (d) {
                            final box = context.findRenderObject() as RenderBox?;
                            if (box == null) return;
                            _update(
                                box.globalToLocal(d.globalPosition).dx,
                                c.maxWidth);
                          }
                        : null,
                    onHorizontalDragEnd: widget.enabled ? (_) => _release() : null,
                    onHorizontalDragCancel: widget.enabled ? _release : null,
                    child: Container(
                      width: _handle,
                      height: _handle,
                      decoration: BoxDecoration(
                        color: widget.enabled ? Z.onTurquoise : Z.faint,
                        shape: BoxShape.circle,
                      ),
                      child: const Icon(Icons.chevron_right_rounded,
                          color: Colors.white, size: 28),
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
