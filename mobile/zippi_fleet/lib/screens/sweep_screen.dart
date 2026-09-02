import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../services/fleet_scope.dart';
import '../services/fleet_store.dart';
import '../theme.dart';
import '../widgets/blocked_sheet.dart';
import '../widgets/common.dart';
import 'complete_screen.dart';

/// Screen 12 — Vehicle sweep. Blocking.
///
/// ⚠ INVARIANT #3. The bus is physically swept before the trip closes:
/// timestamped, geo-stamped, photo-backed. It cannot be skipped and it cannot
/// be pre-tapped before the bus reaches the school.
///
/// The failure this exists to prevent is a child asleep in a back seat and a
/// bus parked for the day. It has happened, in more than one country, and it is
/// why "tick to confirm" is not enough: a tick is a habit. Walking to the back
/// of the bus to photograph the aisle is not, and the geo-fence is what forces
/// the walk to happen at the school rather than at the second-to-last stop.
class SweepScreen extends StatefulWidget {
  const SweepScreen({super.key});

  @override
  State<SweepScreen> createState() => _SweepScreenState();
}

class _SweepScreenState extends State<SweepScreen> {
  String? _photoPath;
  bool _capturing = false;
  bool _locating = false;

  Future<void> _capture() async {
    setState(() => _capturing = true);

    try {
      // ⚠ CAMERA ONLY, NEVER THE GALLERY. A photo picked from the gallery is a
      // photo of last Tuesday's empty aisle.
      final shot = await ImagePicker().pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: CameraDevice.rear,
        imageQuality: 70,
      );

      if (shot != null && mounted) setState(() => _photoPath = shot.path);
    } on Exception catch (e) {
      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('The camera did not open: $e')),
      );
    } finally {
      if (mounted) setState(() => _capturing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final store = FleetScope.of(context);
    final session = store.session!;
    final done = session.sweptAt != null;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ScreenHeader('Sweep the bus',
                onBack: () => Navigator.of(context).pop()),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
              child: Text.rich(
                TextSpan(
                  text: 'Walk to the ',
                  children: [
                    TextSpan(
                        text: 'back of the bus',
                        style:
                            Z.text(15, color: Z.ink, weight: FontWeight.w800)),
                    const TextSpan(text: '. Confirm '),
                    TextSpan(
                        text: 'every seat is empty',
                        style:
                            Z.text(15, color: Z.ink, weight: FontWeight.w800)),
                    const TextSpan(text: ' — look under the seats too.'),
                  ],
                ),
                style: Z.text(15, color: Z.ink).copyWith(height: 1.6),
              ),
            ),
            if (kDebugMode && !done)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
                child: StateChips(
                  labels: const ['Before the school gate', 'At the school gate'],
                  selected: session.atSchoolGate ? 1 : 0,
                  onSelect: (i) => store.setAtSchoolGate(i == 1),
                ),
              ),
            Expanded(
              child: done
                  ? _done(context, store)
                  : session.atSchoolGate
                      ? _ready(context, store)
                      : _blocked(session.metresFromSchool),
            ),
          ],
        ),
      ),
    );
  }

  /* ---------------- blocked ---------------- */

  Widget _blocked(double metres) {
    return EmptyState(
      icon: Icons.lock_outline_rounded,
      title: 'Sweep is locked',
      body: 'It unlocks when the bus reaches the school gate. You are '
          '${(metres / 1000).toStringAsFixed(1)} km away. It cannot be ticked '
          'early — that is the point.',
    );
  }

  /* ---------------- ready ---------------- */

  /// ⚠ A REAL FIX, OR NONE. Invariant #3's geo-fence is re-checked on the
  /// server against the school gate, and the position it checks is this one.
  ///
  /// Substituting the school's own published coordinates — which are sitting
  /// right there in `session.stops` — would make the check pass from anywhere
  /// and quietly delete the invariant. If there is no fix, nothing is sent and
  /// the server refuses, which is the correct outcome: a sweep that cannot be
  /// placed is not evidence.
  Future<void> _confirm(FleetStore store) async {
    setState(() => _locating = true);

    final fix = await store.location.current();

    if (!mounted) return;
    setState(() => _locating = false);

    await runGuarded(
      context,
      () => store.confirmSweep(
        photoPath: _photoPath!,
        lat: fix?.latitude,
        lng: fix?.longitude,
      ),
    );
  }

  Widget _ready(BuildContext context, FleetStore store) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
      children: [
        Row(
          children: [
            const Flexible(
                child: StatusPill('At school gate ✓', tone: Tone.good)),
            const SizedBox(width: 10),
            Flexible(
              child: Text('geo + time stamped',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Z.text(13, color: Z.muted)),
            ),
          ],
        ),
        const SizedBox(height: 12),
        _PhotoSlot(
          path: _photoPath,
          busy: _capturing,
          onTap: _capture,
        ),
        const SizedBox(height: 14),
        FilledButton(
          onPressed: _photoPath == null || store.busy || _locating
              ? null
              : () => _confirm(store),
          child: Text(_locating
              ? 'Finding where you are…'
              : _photoPath == null
                  ? 'Take the photo first'
                  : 'Every seat is empty — confirm'),
        ),
        const SizedBox(height: 10),
        Text('The photo, place and time go into the trip record.',
            textAlign: TextAlign.center, style: Z.text(12, color: Z.faint)),
      ],
    );
  }

  /* ---------------- done ---------------- */

  Widget _done(BuildContext context, FleetStore store) {
    final session = store.session!;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
      children: [
        ZBanner(
          'Bus swept · ${fmtTime(session.sweptAt)}',
          body: 'Photo saved · ${session.trip.schoolName} gate · in the trip '
              'record',
          tone: Tone.good,
          icon: Icons.check_rounded,
        ),
        const SizedBox(height: 12),
        if (session.sweepPhotoPath != null)
          ClipRRect(
            borderRadius: BorderRadius.circular(Z.rRow),
            child: Image.file(
              File(session.sweepPhotoPath!),
              height: 220,
              width: double.infinity,
              fit: BoxFit.cover,
              errorBuilder: (_, __, ___) => const SizedBox.shrink(),
            ),
          ),
        const SizedBox(height: 14),
        FilledButton(
          onPressed: () => Navigator.of(context).pushReplacement(
            MaterialPageRoute(builder: (_) => const CompleteScreen()),
          ),
          child: const Text('Complete the trip →'),
        ),
      ],
    );
  }
}

class _PhotoSlot extends StatelessWidget {
  final String? path;
  final bool busy;
  final VoidCallback onTap;

  const _PhotoSlot({required this.path, required this.busy, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: busy ? null : onTap,
      borderRadius: BorderRadius.circular(Z.rRow),
      child: Container(
        height: 300,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: Z.surface,
          borderRadius: BorderRadius.circular(Z.rRow),
          border: Border.all(color: Z.cardBorder, width: 1.5),
        ),
        child: path == null
            ? Center(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 28),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (busy)
                        const CircularProgressIndicator(
                            strokeWidth: 2, color: Z.turquoise)
                      else
                        const Icon(Icons.photo_camera_rounded,
                            size: 40, color: Z.faint),
                      const SizedBox(height: 12),
                      Text(
                        'Photograph the empty aisle, from the back',
                        textAlign: TextAlign.center,
                        style: Z.text(14, color: Z.muted, weight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
              )
            : Stack(
                fit: StackFit.expand,
                children: [
                  Image.file(File(path!), fit: BoxFit.cover),
                  Positioned(
                    right: 10,
                    bottom: 10,
                    child: StatusPill('Retake', tone: Tone.neutral),
                  ),
                ],
              ),
      ),
    );
  }
}
