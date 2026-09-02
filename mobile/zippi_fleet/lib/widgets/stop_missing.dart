import 'package:flutter/material.dart';

import 'common.dart';

/// Shown instead of a stop screen when the trip carries children but the
/// server sent no stop for them.
///
/// ⚠ THIS REPLACES A CRASH. `stopById(...)!` used to throw "Null check
/// operator used on a null value" and hand an attendant a red Flutter error
/// screen mid-route. The cause is a trip whose `route_schedule` is empty, so
/// `/api/fleet/trips/{trip}` returns `children` with `stops: []` — seeded or
/// hand-authored rows that never went through the solver.
///
/// A crew member can do nothing with a stack trace. What they can do is go
/// back, pick the right trip, and tell the office — so that is what this says.
/// It deliberately does NOT offer any child-marking action: without a stop
/// there is no verified place to mark one at, and guessing is exactly the
/// class of mistake the stop list exists to prevent.
class StopMissingScreen extends StatelessWidget {
  const StopMissingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Align(
                alignment: Alignment.centerLeft,
                child: IconButton(
                  icon: const Icon(Icons.arrow_back_ios_new_rounded),
                  onPressed: () => Navigator.of(context).maybePop(),
                ),
              ),
              const SizedBox(height: 8),
              const ZBanner(
                'This stop is not on the trip',
                body: 'The office sent this trip without its stop list, so '
                    'there is nowhere to mark a child here. Go back and check '
                    'you opened the right run — one bus makes more than one '
                    'trip on the same route. If this is the right one, call '
                    'the office and do not mark anybody from this screen.',
                tone: Tone.danger,
                emphatic: true,
              ),
              const Spacer(),
              FilledButton(
                onPressed: () => Navigator.of(context).maybePop(),
                style: FilledButton.styleFrom(
                  minimumSize: const Size.fromHeight(56),
                ),
                child: const Text('Back to the trip'),
              ),
              const SizedBox(height: 16),
            ],
          ),
        ),
      ),
    );
  }
}
