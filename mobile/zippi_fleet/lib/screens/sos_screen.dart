import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../models/sos.dart';
import '../services/fleet_scope.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/hold_to_fire.dart';

/// Screen 15 — SOS. Both roles.
///
/// ⚠ THE ALARM IS THE HOLD, NOT A TAP. Two seconds, with a haptic when it
/// fires. See [HoldToFire] for why.
///
/// ⚠ AFTER IT FIRES, CHILD RECORDS LOCK. No boarding, no handover, no "not at
/// stop" is accepted until ops resolve or release it. The minutes after an
/// emergency are exactly when a frightened person taps at a phone, and a
/// panicked mis-tap sequence in a custody record is worse than a gap in one.
/// `FleetStore._guardSos()` enforces it; this screen only explains it.
class SosScreen extends StatefulWidget {
  const SosScreen({super.key});

  @override
  State<SosScreen> createState() => _SosScreenState();
}

class _SosScreenState extends State<SosScreen> {
  bool _drill = false;

  /// Set by the hold, cleared when a type is picked. The alarm is not sent
  /// until the type is chosen — but the hold is what commits to sending it.
  bool _picking = false;

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final alert = store.sos;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_drill || (alert?.isDrill ?? false))
              Container(
                width: double.infinity,
                color: Z.amber,
                padding: const EdgeInsets.symmetric(vertical: 10),
                alignment: Alignment.center,
                child: Text('THIS IS A DRILL · NO HELP IS DISPATCHED',
                    style: Z
                        .text(14, color: Z.amberBg, weight: FontWeight.w800)
                        .copyWith(letterSpacing: 1.5)),
              ),
            ScreenHeader('Emergency',
                onBack: () => Navigator.of(context).pop()),
            if (kDebugMode && alert == null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
                child: StateChips(
                  labels: const ['Real', 'Drill'],
                  selected: _drill ? 1 : 0,
                  onSelect: (i) => setState(() => _drill = i == 1),
                ),
              ),
            Expanded(
              child: alert != null
                  ? _fired(context, alert)
                  : _picking
                      ? _typePicker(context)
                      : _idle(),
            ),
          ],
        ),
      ),
    );
  }

  /* ---------------- idle ---------------- */

  Widget _idle() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            HoldToFire(
              drill: _drill,
              onFired: () => setState(() => _picking = true),
            ),
            const SizedBox(height: 24),
            Text(
              'Press and hold for two seconds. A tap does nothing — that is '
              'deliberate. Your phone buzzes when it fires.',
              textAlign: TextAlign.center,
              style: Z.text(14, color: Z.muted).copyWith(height: 1.6),
            ),
          ],
        ),
      ),
    );
  }

  /* ---------------- type ---------------- */

  Widget _typePicker(BuildContext context) {
    final store = FleetScope.of(context);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 0, 4, 12),
          child: Text('What is happening?',
              style: Z.text(15, color: Z.ink, weight: FontWeight.w800)),
        ),
        GridView.count(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          crossAxisCount: 2,
          crossAxisSpacing: 10,
          mainAxisSpacing: 10,
          childAspectRatio: 2.7,
          children: [
            for (final type in SosType.values)
              ZCard(
                radius: Z.rRow,
                padding: EdgeInsets.zero,
                onTap: () {
                  store.fireSos(type, drill: _drill);
                  setState(() => _picking = false);
                },
                child: Center(
                  child: Text(type.label,
                      style:
                          Z.text(16, color: Z.ink, weight: FontWeight.w800)),
                ),
              ),
          ],
        ),
        const SizedBox(height: 14),
        Text('The alert fires the moment you pick one.',
            textAlign: TextAlign.center, style: Z.text(13, color: Z.muted)),
      ],
    );
  }

  /* ---------------- fired ---------------- */

  Widget _fired(BuildContext context, SosAlert alert) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
      children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: Z.redBg,
            borderRadius: BorderRadius.circular(Z.rCard),
            border: Border.all(color: Z.red, width: 2),
          ),
          child: Column(
            children: [
              Text(
                  '${alert.isDrill ? 'Drill' : 'SOS'} sent · '
                  '${alert.type.label}',
                  textAlign: TextAlign.center,
                  style: Z.head(24, color: Z.red)),
              const SizedBox(height: 6),
              Text('Fired ${fmtTime(alert.firedAt)} with your live location.',
                  textAlign: TextAlign.center,
                  style: Z.text(14, color: Z.ink)),
            ],
          ),
        ),
        const SizedBox(height: 12),
        ZCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Alerted(
                  'Control room alerted', 'calling you back now', Z.green),
              const SizedBox(height: 12),
              _Alerted('School alerted', 'transport office responding', Z.green),
              const SizedBox(height: 12),
              _Alerted('Guardians',
                  'notified by ops if this affects them', Z.amber),
            ],
          ),
        ),
        const SizedBox(height: 12),
        const ZBanner(
          'Child records are locked',
          body: 'No boarding or handover changes are accepted until ops '
              'resolve this. It prevents a panicked mis-tap sequence.',
          tone: Tone.warn,
          icon: Icons.lock_outline_rounded,
        ),
        const SizedBox(height: 12),
        // ⚠ NO LOCAL RELEASE. The person holding the phone is the person who
        // has just had a shock; ops release the lock, and there is deliberately
        // no button here that says otherwise.
        Container(
          height: Z.hButton,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: Z.surface,
            borderRadius: BorderRadius.circular(Z.rPill),
            border: Border.all(color: Z.cardBorder, width: 1.5),
          ),
          child: Text('Waiting for ops · they release the lock',
              style: Z.text(15, color: Z.muted, weight: FontWeight.w800)),
        ),
        if (kDebugMode) ...[
          const SizedBox(height: 10),
          TextButton(
            onPressed: () => FleetScope.of(context).releaseSos(),
            child: Text('Debug: simulate the ops release',
                style: Z.text(13, color: Z.faint, weight: FontWeight.w700)),
          ),
        ],
      ],
    );
  }
}

class _Alerted extends StatelessWidget {
  final String title;
  final String detail;
  final Color dot;

  const _Alerted(this.title, this.detail, this.dot);

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 10,
          height: 10,
          margin: const EdgeInsets.only(top: 6),
          decoration: BoxDecoration(color: dot, shape: BoxShape.circle),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text.rich(
            TextSpan(
              text: title,
              style: Z.text(14, color: Z.ink, weight: FontWeight.w800),
              children: [
                TextSpan(
                    text: ' — $detail',
                    style: Z.text(14, color: Z.ink)),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
