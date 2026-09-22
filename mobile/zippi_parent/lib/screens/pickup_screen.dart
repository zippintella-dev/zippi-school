import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../models/family_card.dart';
import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Screen 7 of the canvas — "Afternoon pickup".
///
/// PART A7. The handover code is the whole point of this screen and is the
/// largest element on it: it gets read aloud through a bus window, at a kerb,
/// in traffic noise.
///
/// ⚠ The code exists only while the SERVER says it should — afternoon, and the
/// child not yet collected. When that flips, this screen pops rather than
/// leaving a dead code on display that an attendant might still be shown.
class PickupScreen extends StatefulWidget {
  final ApiClient api;
  final int childId;

  const PickupScreen({required this.api, required this.childId, super.key});

  @override
  State<PickupScreen> createState() => _PickupScreenState();
}

class _PickupScreenState extends State<PickupScreen> with WidgetsBindingObserver {
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

      // The code has done its job (or the day moved on). Leave rather than
      // leave a dead code on display that an attendant might still be shown.
      //
      // ⚠ BOTH CODES, NOT JUST THE AFTERNOON ONE. This tested
      // `!card.showHandoverCode` alone, which is false all morning — so this
      // screen popped itself on its first poll, about ten seconds after a
      // parent opened it to read the boarding code out at the kerb. The screen
      // serves whichever code the server says is live; it closes when neither
      // is.
      if (!card.showHandoverCode && !card.showBoardingCode && _card != null) {
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
        child: Column(
          children: [
            ScreenHeader(
              // ⚠ The title names the code in play. "Afternoon pickup" over a
              // morning boarding code is the sentence that sends a parent
              // looking for the wrong number at a 07:15 kerb — the same trap
              // the placeholder text below already avoids.
              (c?.showBoardingCode ?? false) ? 'Morning boarding' : 'Afternoon pickup',
              subtitle: c == null
                  ? null
                  : [
                      c.name,
                      if (c.trip?.route.isNotEmpty ?? false) c.trip!.route,
                      if (c.stop != null) c.stop!.name,
                    ].join(' · '),
              onBack: () => Navigator.of(context).pop(),
            ),
            Expanded(
              child: c == null
                  ? (_error != null
                      ? ErrorState(_error!, onRetry: _load)
                      : const Center(
                          child: CircularProgressIndicator(color: Z.turquoise)))
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                      children: _content(c),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _content(FamilyCard c) {
    return [
      if (c.showLiveMap) ...[
        LiveBanner(
          c.stop?.scheduledAt == null
              ? 'Bus is on the way'
              : 'Bus arriving · ${fmtTime(c.stop!.scheduledAt)}',
        ),
        const SizedBox(height: 16),
      ],

      // ⚠ Exactly one of these, decided by the SERVER from the trip's direction.
      // The placeholder text has to name the right one too: telling a parent at
      // a 07:15 kerb that "the code appears once the afternoon trip is under
      // way" is the sentence that makes them go looking for the wrong number.
      if (c.boardingCode != null)
        BoardingCodeCard(c.boardingCode!)
      else if (c.handoverCode != null)
        HandoverCodeCard(c.handoverCode!)
      else
        NoteBanner(
          c.showBoardingCode
              ? 'The boarding code appears once the morning trip is under way.'
              : 'The code appears once the afternoon trip is under way.',
          label: '',
          tone: ChipTone.live,
        ),

      const SizedBox(height: 16),

      ZCard(
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 6),
        child: Column(
          children: [
            DetailRow('Bus', c.trip?.bus?.regNo ?? '—'),
            // ⚠ PART K9 — name plus MASKED number. The API never sends a raw
            // crew phone, so there is nothing here to leak.
            DetailRow('Attendant',
                c.trip?.attendant == null
                    ? '—'
                    : '${c.trip!.attendant!.name} · ${c.trip!.attendant!.phoneMasked}'),
            DetailRow('Driver',
                c.trip?.driver == null
                    ? '—'
                    : '${c.trip!.driver!.name} · ${c.trip!.driver!.phoneMasked}'),
            DetailRow(
              'Your stop',
              c.stop == null
                  ? '—'
                  : '${c.stop!.name}'
                      '${c.stop!.scheduledAt == null ? '' : ' · ${fmtTime(c.stop!.scheduledAt)}'}',
              last: true,
            ),
          ],
        ),
      ),

      const SizedBox(height: 16),

      // ⚠ The canvas draws "Call attendant" with a real number. We cannot dial
      // the crew: PART K9 means the app only ever receives a masked number, by
      // design. Until a masked-calling proxy exists, this routes to the school
      // office, which is the number a parent should be calling about a pickup
      // anyway.
      if (c.schoolPhone != null && c.schoolPhone!.isNotEmpty)
        OutlinedButton.icon(
          icon: const Icon(Icons.call_outlined, size: 19),
          onPressed: () => launchUrl(Uri.parse('tel:${c.schoolPhone}')),
          label: Text('Call ${c.school ?? 'the school'}'),
        ),
    ];
  }
}
