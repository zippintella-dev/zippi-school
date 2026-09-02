import 'package:flutter/material.dart';

import '../theme.dart';

/// The offline strip.
///
/// ⚠ CALM, NOT ALARMING, AND NEVER A BLOCK. The route passes through dead
/// zones; that is a known fact about the road, not a fault. The app keeps
/// working — handover codes verify against the day's cached hash and every
/// event queues — so this says what is true and what will happen, and gets out
/// of the way.
///
/// An attendant who reads "no connection" as "stop working" stops boarding
/// children, and a bus standing at a kerb because of a signal bar is a worse
/// outcome than a sync that lands four minutes late.
class OfflineBanner extends StatelessWidget {
  final int queued;

  const OfflineBanner({this.queued = 0, super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: Z.amberBg,
      padding: const EdgeInsets.fromLTRB(20, 10, 20, 10),
      child: Row(
        children: [
          const Icon(Icons.cloud_off_rounded, size: 18, color: Z.amber),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              queued == 0
                  ? 'No signal here. Carry on — everything saves and syncs later.'
                  : 'No signal here. Carry on — $queued '
                      '${queued == 1 ? 'update' : 'updates'} will sync when you are back.',
              style: Z.text(13, color: Z.amber, weight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}
