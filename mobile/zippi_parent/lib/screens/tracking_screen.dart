import 'dart:async';

import 'package:flutter/material.dart';

import '../config.dart';
import '../models/family_card.dart';
import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/bus_map.dart';
import '../widgets/common.dart';
import 'pickup_screen.dart';

/// Screen 9 of the canvas — "Live tracking".
///
/// Full-bleed map with a bottom sheet: status strip, stop progress, and the
/// route through to the handover code.
///
/// ⚠ ENTERPRISE L29 — THE SERVER OWNS THIS SCREEN. It renders only while
/// `showLiveMap` is true, and the bus coordinates are absent from the payload
/// whenever it is false. When the server closes the map — the child is handed
/// over, or reached school in the morning — this screen pops itself rather than
/// sitting on a position it should no longer be showing.
class TrackingScreen extends StatefulWidget {
  final ApiClient api;
  final int childId;

  const TrackingScreen({required this.api, required this.childId, super.key});

  @override
  State<TrackingScreen> createState() => _TrackingScreenState();
}

class _TrackingScreenState extends State<TrackingScreen>
    with WidgetsBindingObserver {
  FamilyCard? _card;
  String? _error;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
    _poll = Timer.periodic(Config.pollInterval, (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _load(silent: true);
  }

  Future<void> _load({bool silent = false}) async {
    try {
      final card = await widget.api.child(widget.childId);
      if (!mounted) return;

      // ⚠ The server closed the map for this family. Leave, don't linger.
      if (!card.showLiveMap && _card != null) {
        Navigator.of(context).pop();
        return;
      }

      setState(() {
        _card = card;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (!silent || _card == null) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _card;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            ScreenHeader(
              'Live tracking',
              subtitle: c == null
                  ? null
                  : '${c.name}${c.trip?.route.isNotEmpty ?? false ? ' · ${c.trip!.route}' : ''}',
              onBack: () => Navigator.of(context).pop(),
            ),
            Expanded(
              child: c == null
                  ? (_error != null
                      ? ErrorState(_error!, onRetry: _load)
                      : const Center(
                          child: CircularProgressIndicator(color: Z.turquoise)))
                  : _live(c),
            ),
          ],
        ),
      ),
    );
  }

  Widget _live(FamilyCard c) {
    final bus = c.trip?.bus;

    return Column(
      children: [
        Expanded(
          child: bus == null
              ? const Center(
                  child: Padding(
                    padding: EdgeInsets.all(32),
                    child: Text('No bus is assigned to this trip yet.',
                        textAlign: TextAlign.center),
                  ),
                )
              : BusMap(bus: bus, stop: c.stop),
        ),
        _sheet(c),
      ],
    );
  }

  Widget _sheet(FamilyCard c) {
    final bus = c.trip?.bus;

    return Container(
      width: double.infinity,
      transform: Matrix4.translationValues(0, -24, 0),
      decoration: const BoxDecoration(
        color: Z.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(Z.rCard)),
        border: Border(top: BorderSide(color: Z.cardBorder, width: 1.5)),
      ),
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 4),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 44,
              height: 5,
              decoration: BoxDecoration(
                color: Z.cardBorder,
                borderRadius: BorderRadius.circular(99),
              ),
            ),
            const SizedBox(height: 14),
            LiveBanner(
              c.stop?.scheduledAt == null
                  ? 'On the bus'
                  : 'On the bus · ${c.stop!.name} at ${fmtTime(c.stop!.scheduledAt)}',
            ),
            const SizedBox(height: 12),
            _progress(c),
            if (bus != null) ...[
              const SizedBox(height: 4),
              Text(BusMap.caption(bus),
                  textAlign: TextAlign.center,
                  style: Z.text(12, color: Z.faint)),
            ],
            if (c.showHandoverCode) ...[
              const SizedBox(height: 12),
              FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: Z.coral,
                  foregroundColor: Z.onCoral,
                ),
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) =>
                      PickupScreen(api: widget.api, childId: c.childId),
                )),
                child: const Text('Show handover code'),
              ),
            ],
            const SizedBox(height: 12),
          ],
        ),
      ),
    );
  }

  /// The stop list. Events already recorded are green; the child's own stop is
  /// coral so it stands out from the rest of the route.
  Widget _progress(FamilyCard c) {
    final rows = <Widget>[];

    for (final e in c.timeline) {
      rows.add(_dot(Z.green, '${e.text} — ${fmtTime(e.at)}', muted: true));
    }

    if (c.stop != null) {
      rows.add(_dot(
        Z.coral,
        '${c.stop!.name} — your stop'
        '${c.stop!.scheduledAt == null ? '' : ' · ${fmtTime(c.stop!.scheduledAt)}'}',
        bold: true,
      ));
    }

    return Column(children: rows);
  }

  Widget _dot(Color color, String text, {bool muted = false, bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7, horizontal: 2),
      child: Row(
        children: [
          Container(
            width: 10,
            height: 10,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              text,
              style: Z.text(14,
                  color: muted ? Z.muted : Z.ink,
                  weight: bold ? FontWeight.w800 : FontWeight.w400),
            ),
          ),
        ],
      ),
    );
  }
}
