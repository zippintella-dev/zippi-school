import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';
import '../models/trip.dart';
import '../services/fleet_scope.dart';
import '../services/fleet_api.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/common.dart';
import '../widgets/numeric_keypad.dart';
import '../widgets/offline_banner.dart';
import '../widgets/sos_fab.dart';
import '../widgets/stop_missing.dart';
import 'escalation_screen.dart';

/// Screen 10 — Drop stop, per child. Afternoon. The core safety screen.
///
/// ⚠ INVARIANT #1 IS THIS SCREEN. A child is never released without a verified
/// receiver. Exactly three ways out — a handover code, an authorized face, or a
/// consented self-release — and one way to say it did not happen, which starts
/// the escalation ladder and keeps the child on the bus.
///
/// ⚠ THERE IS NO SKIP AND NO "LEAVE CHILD", at any point, for any role, in any
/// build. If nobody can be verified, the only remaining action is *Return to
/// school*. Absence is made conspicuous rather than dismissible: an unresolved
/// child blocks the depart button, blocks trip completion, and is named in
/// both refusals.
enum _Mode { menu, code, faces }

class DropStopScreen extends StatefulWidget {
  final String stopId;

  const DropStopScreen({required this.stopId, super.key});

  @override
  State<DropStopScreen> createState() => _DropStopScreenState();
}

class _DropStopScreenState extends State<DropStopScreen> {
  String? _selectedId;
  _Mode _mode = _Mode.menu;

  String _code = '';
  int _attempts = 0;
  bool _codeLocked = false;

  /// ⚠ The keypad's refusal is rendered INLINE, not in the blocking sheet.
  /// A wrong digit at a kerb with a parent standing there needs "3 attempts
  /// left" under the boxes, not a sheet to dismiss before trying again. Every
  /// OTHER refusal on this screen still gets the sheet.
  String? _codeError;

  /// One code in flight at a time. Without this a fast fourth tap sends two
  /// attempts and burns two of the five.
  bool _submitting = false;

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;

    // ⚠ See AtStopScreen — a trip with children but no stops must not crash.
    final stop = session.stopById(widget.stopId);

    if (stop == null) return const StopMissingScreen();

    // Everyone who gets off here, whatever state they are now in — a handed
    // child stays on the list so the crew can see the stop is finished.
    final atStop = session.childrenAt(widget.stopId);
    final pending = store.dropListAt(widget.stopId);

    final selected = session.childById(_selectedId ?? '') ??
        (pending.isNotEmpty ? pending.first : (atStop.isEmpty ? null : atStop.first));

    if (selected == null) {
      return Scaffold(
        body: SafeArea(
          child: Column(
            children: [
              ScreenHeader('${stop.name} · drop',
                  onBack: () => Navigator.of(context).pop()),
              const Expanded(
                child: EmptyState(
                  icon: Icons.check_circle_outline_rounded,
                  title: 'Nobody gets off here',
                  body: 'No child on this trip is assigned to this stop.',
                ),
              ),
            ],
          ),
        ),
      );
    }

    final allDone = pending.isEmpty;

    return Scaffold(
      body: SafeArea(
        child: Stack(
          children: [
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (store.offline) OfflineBanner(queued: store.queuedEvents),
                ScreenHeader(
                  '${stop.name} · drop',
                  subtitle: '${fmtTime(stop.reachedAt ?? DateTime.now())} · '
                      '${atStop.length} '
                      '${atStop.length == 1 ? 'child gets' : 'children get'} '
                      'off here',
                  onBack: () => Navigator.of(context).pop(),
                ),
                if (atStop.length > 1)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                    child: _ChildSwitcher(
                      children: atStop,
                      selectedId: selected.id,
                      onSelect: (id) => setState(() {
                        _selectedId = id;
                        _mode = _Mode.menu;
                        _code = '';
                      }),
                    ),
                  ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                  child: _ChildCard(child: selected),
                ),
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 100),
                    children: [
                      if (selected.state != ChildState.boarded)
                        ..._resolved(context, selected, allDone, stop)
                      else
                        ...switch (_mode) {
                          _Mode.menu => _menu(context, store, selected),
                          _Mode.code => _codePad(context, store, selected),
                          _Mode.faces => _faces(context, store, selected),
                        },
                    ],
                  ),
                ),
              ],
            ),
            const Positioned(right: 16, bottom: 20, child: SosFab()),
          ],
        ),
      ),
    );
  }

  /* ---------------- resolved ---------------- */

  List<Widget> _resolved(
      BuildContext context, TripChild child, bool allDone, TripStop stop) {
    final store = FleetScope.of(context);
    final h = child.handover;

    return [
      if (h != null)
        ZBanner(
          'Handed over · ${fmtTime(h.at)}',
          body: '${h.method.label} · ${h.receiver}',
          tone: Tone.good,
          icon: Icons.check_rounded,
        )
      else
        ZBanner(
          child.state.label,
          body: child.note,
          tone: child.state == ChildState.returnedToSchool
              ? Tone.warn
              : Tone.neutral,
        ),
      const SizedBox(height: 12),
      if (allDone && !stop.departed)
        FilledButton(
          onPressed: () async {
            if (await runGuarded(context, () => store.departStop(stop.id))) {
              if (context.mounted) Navigator.of(context).pop();
            }
          },
          child: const Text('Depart stop'),
        )
      else if (!allDone)
        Text(
          'Still to resolve at this stop: '
          '${store.dropListAt(stop.id).map((c) => c.firstName).join(', ')}.',
          textAlign: TextAlign.center,
          style: Z.text(13, color: Z.muted),
        ),
    ];
  }

  /* ---------------- the three ways, and the fourth that is not one --------- */

  List<Widget> _menu(
      BuildContext context, FleetStore store, TripChild child) {
    return [
      _Option(
        leading: Container(
          width: Z.tapMin,
          height: Z.tapMin,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: Z.tealSoft,
            borderRadius: BorderRadius.circular(14),
          ),
          child: Text('123', style: Z.head(16, color: Z.teal)),
        ),
        title: 'Verify handover code',
        subtitle: 'The 4-digit code from the Parent app',
        onTap: () => setState(() {
          _mode = _Mode.code;
          _code = '';
        }),
      ),
      const SizedBox(height: 10),
      _Option(
        leading: Container(
          width: Z.tapMin,
          height: Z.tapMin,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: Z.coralSoft,
            borderRadius: BorderRadius.circular(14),
          ),
          child: const Icon(Icons.people_alt_rounded,
              size: 22, color: Z.coralText),
        ),
        title: 'Pick authorized person',
        subtitle: 'Faces this family has approved',
        // ⚠ This exists because the grandmother who collects every day should
        // not have to be handed a code four times a week. Friction that is not
        // worth it gets worked around — the code gets written on a note in the
        // bus, and then the check is worth nothing.
        onTap: child.authorizedPeople.isEmpty
            ? null
            : () => setState(() => _mode = _Mode.faces),
        disabledNote: child.authorizedPeople.isEmpty
            ? 'No approved faces on file for this family'
            : null,
      ),
      // ⚠ NOT RENDERED AT ALL when the child has no consent — not rendered and
      // disabled. A greyed control invites a long press and a phone call to
      // ops asking for it to be turned on; an absent one does not exist to be
      // argued with.
      if (child.maySelfRelease) ...[
        const SizedBox(height: 10),
        _Option(
          leading: Container(
            width: Z.tapMin,
            height: Z.tapMin,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Z.greenBg,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Icon(Icons.directions_walk_rounded,
                size: 22, color: Z.green),
          ),
          title: 'Self-release',
          subtitle: 'Signed consent on file · meets minimum grade',
          onTap: () => _release(
            context,
            store,
            child,
            HandoverMethod.selfRelease,
            receiverLabel: 'Signed consent on file · ${child.className}',
            useSheet: true,
          ),
        ),
      ] else ...[
        const SizedBox(height: 10),
        Text(
          'Self-release is not shown — ${child.firstName} has no consent on '
          'file.',
          textAlign: TextAlign.center,
          style: Z.text(12, color: Z.faint),
        ),
      ],
      const SizedBox(height: 20),
      _Option(
        leading: Container(
          width: Z.tapMin,
          height: Z.tapMin,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: Z.coralSoft,
            borderRadius: BorderRadius.circular(14),
          ),
          child: const Icon(Icons.error_outline_rounded,
              size: 22, color: Z.coralText),
        ),
        title: 'No one is here',
        titleColor: Z.coralText,
        subtitle: 'Starts the escalation ladder. The child stays on the bus.',
        borderColor: Z.coral,
        onTap: () async {
          await runGuarded(context, () => store.beginEscalation(child.id));

          if (!context.mounted) return;

          Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => EscalationScreen(childId: child.id),
            ),
          );
        },
      ),
      const SizedBox(height: 12),
      Text(
        'There is no skip and no "leave child". If nobody can be verified, the '
        'bus returns to school.',
        textAlign: TextAlign.center,
        style: Z.text(12, color: Z.faint).copyWith(height: 1.5),
      ),
    ];
  }

  /* ---------------- the code ---------------- */

  List<Widget> _codePad(
      BuildContext context, FleetStore store, TripChild child) {
    final left = Config.handoverAttempts - _attempts;

    return [
      const SizedBox(height: 4),
      CodeBoxes(length: 4, filled: _code.length, locked: _codeLocked),
      const SizedBox(height: 12),
      Text(
        _codeLocked
            ? 'Locked — ${Config.handoverAttempts} wrong attempts. Use another '
                'option, or call ops.'
            // ⚠ The SERVER's sentence when there is one — it knows how many
            // attempts are really left, and this device does not.
            : _codeError ??
                (_attempts > 0
                    ? 'Wrong code · $left ${left == 1 ? 'attempt' : 'attempts'} left'
                    : 'Ask the person for the 4-digit code from their Parent app'),
        textAlign: TextAlign.center,
        style: Z.text(13,
            color: _codeLocked
                ? Z.red
                : _attempts > 0
                    ? Z.coralText
                    : Z.muted,
            weight: FontWeight.w800),
      ),
      const SizedBox(height: 14),
      NumericKeypad(
        enabled: !_codeLocked,
        onClear: () => setState(() => _code = ''),
        onBackspace: () => setState(() =>
            _code = _code.isEmpty ? _code : _code.substring(0, _code.length - 1)),
        onDigit: (d) async {
          if (_codeLocked || _submitting) return;

          final next = _code + d;

          if (next.length < 4) {
            setState(() {
              _code = next;
              _codeError = null;
            });
            return;
          }

          setState(() {
            _code = next;
            _submitting = true;
          });

          // ⚠ THE CODE IS SENT, NOT COMPARED HERE. The server holds a hash and
          // counts the attempts; a plaintext comparison in an app is a
          // formality anyone with the APK skips.
          final error = await _release(
            context,
            store,
            child,
            HandoverMethod.code,
            code: next,
            receiverLabel: 'Guardian at the stop',
          );

          if (!mounted) return;

          if (error == null) return;

          setState(() {
            _submitting = false;
            _code = '';
            _attempts++;
            _codeError = error;
            // ⚠ Five wrong attempts locks the keypad for THIS CHILD, not the
            // screen. The other two verification routes stay open, because a
            // locked keypad must never become a reason to let a child off
            // unverified.
            _codeLocked = _attempts >= Config.handoverAttempts;
          });

          HapticFeedback.heavyImpact();
        },
      ),
      const SizedBox(height: 12),
      TextButton(
        onPressed: () => setState(() {
          _mode = _Mode.menu;
          _code = '';
        }),
        child: Text('← Other options',
            style: Z.text(14, color: Z.muted, weight: FontWeight.w800)),
      ),
    ];
  }

  /* ---------------- the faces ---------------- */

  List<Widget> _faces(
      BuildContext context, FleetStore store, TripChild child) {
    return [
      const SizedBox(height: 4),
      Text('Who is collecting? Tap their face.',
          style: Z.text(14, color: Z.muted)),
      const SizedBox(height: 12),
      GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          crossAxisSpacing: 10,
          mainAxisSpacing: 10,
          childAspectRatio: 1.05,
        ),
        itemCount: child.authorizedPeople.length,
        itemBuilder: (context, i) {
          final person = child.authorizedPeople[i];

          return ZCard(
            radius: Z.rRow,
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 14),
            onTap: () => _release(
              context,
              store,
              child,
              HandoverMethod.authorizedPerson,
              authorizedReceiverId: person.id,
              receiverLabel: '${person.name} · ${person.relationship}',
              useSheet: true,
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  width: 64,
                  height: 64,
                  alignment: Alignment.center,
                  decoration: const BoxDecoration(
                      color: Z.tealSoft, shape: BoxShape.circle),
                  child: Text(person.name[0].toUpperCase(),
                      style: Z.head(24, color: Z.teal)),
                ),
                const SizedBox(height: 8),
                Text(person.name,
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Z.text(14, color: Z.ink, weight: FontWeight.w800)),
                Text(person.relationship,
                    style: Z.text(12, color: Z.muted)),
              ],
            ),
          );
        },
      ),
      const SizedBox(height: 12),
      TextButton(
        onPressed: () => setState(() => _mode = _Mode.menu),
        child: Text('← Other options',
            style: Z.text(14, color: Z.muted, weight: FontWeight.w800)),
      ),
    ];
  }

  /// Releases a child, or returns the refusal to render.
  ///
  /// ⚠ Returns the message rather than showing it, because WHERE a refusal
  /// belongs depends on which of the three routes was used: the keypad shows
  /// it inline under the boxes so the next attempt is one tap away, and the
  /// face grid and self-release show the blocking sheet.
  Future<String?> _release(
    BuildContext context,
    FleetStore store,
    TripChild child,
    HandoverMethod method, {
    String? code,
    int? authorizedReceiverId,
    String? receiverLabel,
    bool useSheet = false,
  }) async {
    try {
      await store.recordHandover(
        childId: child.id,
        method: method,
        code: code,
        authorizedReceiverId: authorizedReceiverId,
        receiverLabel: receiverLabel,
      );

      HapticFeedback.mediumImpact();

      if (mounted) {
        setState(() {
          _mode = _Mode.menu;
          _code = '';
          _attempts = 0;
          _codeLocked = false;
          _codeError = null;
          _submitting = false;
        });
      }

      return null;
    } on SafetyViolation catch (e) {
      if (useSheet && context.mounted) showBlocked(context, e);
      return e.message;
    } on FleetTransportException catch (e) {
      // ⚠ NOT a refusal. Nobody said this child may not be released — the
      // request did not arrive. Saying otherwise would teach the crew that
      // refusals are noise.
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }

      if (mounted) setState(() => _submitting = false);
      return e.message;
    }
  }
}

/// Which child at this stop is being resolved.
class _ChildSwitcher extends StatelessWidget {
  final List<TripChild> children;
  final String selectedId;
  final ValueChanged<String> onSelect;

  const _ChildSwitcher({
    required this.children,
    required this.selectedId,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: Z.hChip,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: children.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final child = children[i];
          final on = child.id == selectedId;
          final done = child.state != ChildState.boarded;

          return InkWell(
            onTap: () => onSelect(child.id),
            borderRadius: BorderRadius.circular(Z.rPill),
            child: Container(
              height: Z.hChip,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: on ? Z.tealSoft : Z.surface,
                borderRadius: BorderRadius.circular(Z.rPill),
                border: Border.all(
                    color: on ? Z.turquoise : Z.cardBorder,
                    width: on ? 2 : 1.5),
              ),
              child: Row(
                children: [
                  if (done) ...[
                    const Icon(Icons.check_rounded, size: 16, color: Z.green),
                    const SizedBox(width: 5),
                  ],
                  Text(child.firstName,
                      style: Z.text(14,
                          color: on ? Z.teal : Z.muted,
                          weight: FontWeight.w800)),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _ChildCard extends StatelessWidget {
  final TripChild child;

  const _ChildCard({required this.child});

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
      child: Row(
        children: [
          ChildAvatar(child, size: 64),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(child.name, style: Z.head(22)),
                const SizedBox(height: 2),
                Text('${child.className} · gets off at this stop',
                    style: Z.text(14, color: Z.muted)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Option extends StatelessWidget {
  final Widget leading;
  final String title;
  final String subtitle;
  final VoidCallback? onTap;
  final Color? titleColor;
  final Color? borderColor;
  final String? disabledNote;

  const _Option({
    required this.leading,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.titleColor,
    this.borderColor,
    this.disabledNote,
  });

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: onTap == null ? 0.55 : 1,
      child: ZCard(
        radius: Z.rRow,
        onTap: onTap,
        borderColor: borderColor ?? Z.cardBorder,
        borderWidth: borderColor != null ? 2 : 1.5,
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: Z.tapSafety - 28),
          child: Row(
            children: [
              leading,
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(title,
                        style: Z.text(15,
                            color: titleColor ?? Z.ink,
                            weight: FontWeight.w800)),
                    const SizedBox(height: 2),
                    Text(disabledNote ?? subtitle,
                        style: Z.text(13, color: Z.muted)),
                  ],
                ),
              ),
              if (onTap != null)
                const Icon(Icons.chevron_right_rounded,
                    size: 22, color: Z.faint),
            ],
          ),
        ),
      ),
    );
  }
}
