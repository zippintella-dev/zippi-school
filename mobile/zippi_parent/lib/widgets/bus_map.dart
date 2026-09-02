import 'package:flutter/material.dart';

import '../models/family_card.dart';
import '../theme.dart';
import 'common.dart';

/// The tracking map, drawn in the canvas's illustrative style: a pale green
/// ground with white roads, a dashed turquoise route, a turquoise bus puck and
/// a coral "Your stop" pin.
///
/// ⚠ This is NOT the production map. PART M/R call for the Google Maps SDK with
/// real tiles and traffic; that needs an API key and a billing account, so this
/// stands in until those exist. The data is already real — `BusInfo` carries
/// actual lat/lng — so swapping in `google_maps_flutter` is a widget change,
/// not a data change.
///
/// ⚠ It renders only when the SERVER says so. The child screen gates it on
/// `card.showLiveMap` (enterprise L29), and the coordinates are absent from the
/// payload whenever that is false — so this widget cannot leak a position even
/// if it were mounted by mistake.
class BusMap extends StatelessWidget {
  final BusInfo bus;
  final StopInfo? stop;

  const BusMap({required this.bus, required this.stop, super.key});

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(Z.rCard)),
      child: Container(
        color: Z.mapLand,
        child: Stack(
          fit: StackFit.expand,
          children: [
            const CustomPaint(painter: _StreetPainter()),
            if (bus.hasPosition)
              LayoutBuilder(builder: (context, c) => _markers(c))
            else
              Center(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 32),
                  child: Text(
                    'Waiting for the bus to report its position…',
                    textAlign: TextAlign.center,
                    style: Z.text(14, color: Z.muted),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _markers(BoxConstraints c) {
    final lats = <double>[bus.latitude!];
    final lngs = <double>[bus.longitude!];

    if (stop != null) {
      lats.add(stop!.latitude);
      lngs.add(stop!.longitude);
    }

    final minLat = lats.reduce((a, b) => a < b ? a : b);
    final maxLat = lats.reduce((a, b) => a > b ? a : b);
    final minLng = lngs.reduce((a, b) => a < b ? a : b);
    final maxLng = lngs.reduce((a, b) => a > b ? a : b);

    final spanLat = maxLat - minLat;
    final spanLng = maxLng - minLng;

    // Padded so a marker never clips the edge of the box.
    Offset project(double lat, double lng) {
      final x = spanLng == 0 ? 0.5 : (lng - minLng) / spanLng;
      final y = spanLat == 0 ? 0.5 : (maxLat - lat) / spanLat;

      return Offset(
        (0.16 + x * 0.68) * c.maxWidth,
        (0.18 + y * 0.62) * c.maxHeight,
      );
    }

    final busAt = project(bus.latitude!, bus.longitude!);
    final stopAt = stop == null ? null : project(stop!.latitude, stop!.longitude);

    return Stack(
      children: [
        if (stopAt != null)
          CustomPaint(
            size: Size(c.maxWidth, c.maxHeight),
            painter: _RoutePainter(from: busAt, to: stopAt),
          ),
        if (stopAt != null)
          Positioned(
            left: stopAt.dx - 50,
            top: stopAt.dy - 34,
            child: SizedBox(
              width: 100,
              child: Column(
                children: [
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: Z.coral,
                      borderRadius: BorderRadius.circular(Z.rPill),
                    ),
                    child: Text('Your stop',
                        style: Z.text(12,
                            color: Z.onCoral, weight: FontWeight.w800)),
                  ),
                  const SizedBox(height: 4),
                  Container(
                    width: 12,
                    height: 12,
                    decoration: BoxDecoration(
                      color: Z.coral,
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white, width: 3),
                    ),
                  ),
                ],
              ),
            ),
          ),
        Positioned(
          left: busAt.dx - 24,
          top: busAt.dy - 24,
          child: Semantics(
            label: 'Bus',
            child: Opacity(
              // A stale position is drawn faded rather than as if it were live.
              opacity: bus.isStale ? 0.45 : 1,
              child: Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: Z.turquoise,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 3),
                  boxShadow: [
                    BoxShadow(
                      color: Z.teal.withValues(alpha: 0.4),
                      blurRadius: 14,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: const Icon(Icons.directions_bus_rounded,
                    size: 24, color: Colors.white),
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// Caption shown under the map. Says plainly when the fix is old.
  static String caption(BusInfo bus) {
    if (!bus.hasPosition) {
      return 'Schematic view. Live position depends on the bus device.';
    }

    if (bus.isStale) {
      // ⚠ Say so. A stale position drawn as if it were live is how a parent
      // walks to the stop ten minutes late.
      return bus.lastPingAt == null
          ? 'Bus position may be out of date.'
          : 'Last reported ${fmtTime(bus.lastPingAt)} — may be out of date.';
    }

    return 'Schematic view. Live GPS accuracy depends on the bus device.';
  }
}

/// The illustrative streets and blocks behind the markers.
class _StreetPainter extends CustomPainter {
  const _StreetPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final road = Paint()..color = Z.mapRoad;
    final block = Paint()..color = Z.mapBlock;

    void rotatedRoad(Offset origin, double w, double h, double turns) {
      canvas.save();
      canvas.translate(origin.dx, origin.dy);
      canvas.rotate(turns);
      canvas.drawRect(Rect.fromLTWH(0, 0, w, h), road);
      canvas.restore();
    }

    rotatedRoad(Offset(-20, size.height * 0.30), size.width * 1.6, 26, -0.14);
    rotatedRoad(Offset(size.width * 0.45, -40), 26, size.height * 1.6, 0.21);
    rotatedRoad(Offset(40, size.height * 0.72), size.width * 1.4, 22, 0.07);

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(size.width - 140, 40, 110, 70),
        const Radius.circular(14),
      ),
      block,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(26, size.height * 0.46, 80, 56),
        const Radius.circular(14),
      ),
      block,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Dashed turquoise line from the bus to the stop.
class _RoutePainter extends CustomPainter {
  final Offset from;
  final Offset to;

  const _RoutePainter({required this.from, required this.to});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = Z.turquoise
      ..strokeWidth = 5
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.stroke;

    // A gentle curve rather than a straight line — a bus does not fly.
    final mid = Offset(
      (from.dx + to.dx) / 2 + (to.dy - from.dy) * 0.18,
      (from.dy + to.dy) / 2 - (to.dx - from.dx) * 0.18,
    );

    final path = Path()
      ..moveTo(from.dx, from.dy)
      ..quadraticBezierTo(mid.dx, mid.dy, to.dx, to.dy);

    // Dash it manually — Flutter has no dash support on Path.
    for (final metric in path.computeMetrics()) {
      var d = 0.0;
      while (d < metric.length) {
        final next = (d + 2).clamp(0.0, metric.length);
        canvas.drawPath(metric.extractPath(d, next), paint);
        d += 12;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _RoutePainter old) =>
      old.from != from || old.to != to;
}
