import 'package:flutter/material.dart';

import '../models/trip.dart';
import '../theme.dart';

/// The driver's route map.
///
/// ⚠ NOT THE PRODUCTION MAP, AND THE DRIVER IS NOT MEANT TO NAVIGATE BY IT.
/// PART M/R call for the Google Maps SDK with real tiles and traffic, which
/// needs an API key and a billing account. Until then this is a schematic plot
/// of the authored stop sequence — enough to answer "where am I on the route",
/// which is what the strip underneath actually says in words.
///
/// Turn-by-turn is handed off to Google Maps by the button beside it. That is
/// deliberate and survives the real map landing: an in-house navigation UI
/// competing for a driver's attention with the one they already trust is a
/// worse outcome than a handoff.
///
/// The data is already real — [TripStop] carries actual lat/lng — so swapping
/// in `google_maps_flutter` is a widget change, not a data change.
class RouteMap extends StatelessWidget {
  final List<TripStop> stops;

  /// Where the bus is. Null while no position has been reported.
  final double? busLatitude;
  final double? busLongitude;

  const RouteMap({
    required this.stops,
    this.busLatitude,
    this.busLongitude,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Z.mapLand,
      child: Stack(
        fit: StackFit.expand,
        children: [
          const CustomPaint(painter: _StreetPainter()),
          LayoutBuilder(builder: (context, c) => _plot(c)),
        ],
      ),
    );
  }

  Widget _plot(BoxConstraints c) {
    if (stops.isEmpty) return const SizedBox.shrink();

    final lats = stops.map((s) => s.latitude).toList();
    final lngs = stops.map((s) => s.longitude).toList();

    if (busLatitude != null && busLongitude != null) {
      lats.add(busLatitude!);
      lngs.add(busLongitude!);
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
        (0.14 + x * 0.72) * c.maxWidth,
        (0.14 + y * 0.72) * c.maxHeight,
      );
    }

    final points = [
      for (final s in stops) project(s.latitude, s.longitude),
    ];

    final busAt = (busLatitude != null && busLongitude != null)
        ? project(busLatitude!, busLongitude!)
        : null;

    return Stack(
      children: [
        CustomPaint(
          size: Size(c.maxWidth, c.maxHeight),
          painter: _RoutePainter(points),
        ),
        for (var i = 0; i < stops.length; i++)
          Positioned(
            left: points[i].dx - 9,
            top: points[i].dy - 9,
            child: Semantics(
              label: stops[i].name,
              child: Container(
                width: 18,
                height: 18,
                decoration: BoxDecoration(
                  color: stops[i].reached ? Z.teal : Z.bg,
                  shape: BoxShape.circle,
                  border: Border.all(color: Z.teal, width: 3),
                ),
              ),
            ),
          ),
        if (busAt != null)
          Positioned(
            left: busAt.dx - 22,
            top: busAt.dy - 22,
            child: Semantics(
              label: 'This bus',
              child: Container(
                width: 44,
                height: 44,
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
                    size: 22, color: Z.onTurquoise),
              ),
            ),
          ),
      ],
    );
  }
}

/// Illustrative streets behind the route.
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

    rotatedRoad(Offset(-20, size.height * 0.26), size.width * 1.7, 24, -0.12);
    rotatedRoad(Offset(size.width * 0.52, -40), 24, size.height * 1.7, 0.18);
    rotatedRoad(Offset(30, size.height * 0.70), size.width * 1.5, 20, 0.06);

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(size.width - 130, 34, 100, 62),
        const Radius.circular(14),
      ),
      block,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(22, size.height * 0.44, 74, 50),
        const Radius.circular(14),
      ),
      block,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// The teal polyline through the stops, in authored order.
class _RoutePainter extends CustomPainter {
  final List<Offset> points;

  const _RoutePainter(this.points);

  @override
  void paint(Canvas canvas, Size size) {
    if (points.length < 2) return;

    final paint = Paint()
      ..color = Z.teal
      ..strokeWidth = 5
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;

    final path = Path()..moveTo(points.first.dx, points.first.dy);

    // Smoothed through the midpoints so the line reads as a road rather than a
    // set of survey bearings.
    for (var i = 1; i < points.length; i++) {
      final prev = points[i - 1];
      final cur = points[i];
      final mid = Offset((prev.dx + cur.dx) / 2, (prev.dy + cur.dy) / 2);
      path.quadraticBezierTo(prev.dx, prev.dy, mid.dx, mid.dy);
    }

    path.lineTo(points.last.dx, points.last.dy);

    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant _RoutePainter old) => old.points != points;
}
