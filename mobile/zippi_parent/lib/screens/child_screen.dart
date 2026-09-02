import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../models/family_card.dart';
import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'absent_screen.dart';
import 'journey_screen.dart';
import 'pickup_screen.dart';
import 'tracking_screen.dart';

/// Screen 6 of the canvas — the child's day.
///
/// Today split into MORNING · TO SCHOOL and AFTERNOON · TO HOME, with the
/// actions the parent can take from here: track the bus, show the handover
/// code, mark absent, call the school.
///
/// ⚠ Never cache this screen. A parent looking at a stale "on the bus" from
/// twenty minutes ago is worse off than one seeing an honest error, because
/// this answers "where is my child right now".
class ChildScreen extends StatefulWidget {
  final ApiClient api;
  final int childId;

  const ChildScreen({required this.api, required this.childId, super.key});

  @override
  State<ChildScreen> createState() => _ChildScreenState();
}

class _ChildScreenState extends State<ChildScreen> with WidgetsBindingObserver {
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

  /// PART F6 / L14 — fetch immediately on resume, never wait for the tick.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _load(silent: true);
  }

  Future<void> _load({bool silent = false}) async {
    try {
      final card = await widget.api.child(widget.childId);
      if (!mounted) return;

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
              c == null ? 'Loading…' : "${c.name}'s day",
              subtitle: c == null
                  ? null
                  : '${DateFormat('EEE d MMM').format(DateTime.now())}'
                      '${c.trip?.route.isNotEmpty ?? false ? ' · ${c.trip!.route}' : ''}',
              onBack: () => Navigator.of(context).pop(),
              trailing: c == null
                  ? null
                  : IconButton(
                      tooltip: 'Journey history',
                      icon: const Icon(Icons.history_rounded, color: Z.teal),
                      onPressed: () =>
                          Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => JourneyScreen(
                          api: widget.api,
                          childId: c.childId,
                          childName: c.name,
                        ),
                      )),
                    ),
            ),
            Expanded(
              child: c == null
                  ? (_error != null
                      ? ErrorState(_error!, onRetry: _load)
                      : const Center(
                          child: CircularProgressIndicator(color: Z.turquoise)))
                  : RefreshIndicator(
                      onRefresh: _load,
                      color: Z.teal,
                      child: ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                        children: _content(c),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _content(FamilyCard c) {
    final morning = c.timeline.where((e) => e.direction == 'Morning').toList();
    final afternoon =
        c.timeline.where((e) => e.direction == 'Afternoon').toList();

    return [
      if (c.absent)
        Padding(
          padding: const EdgeInsets.only(bottom: 16),
          child: NoteBanner(
            '${c.name} is marked absent today'
            '${c.absentDirections.isEmpty ? '' : ' (${c.absentDirections.join(' & ')})'}. '
            'The bus will not wait at the stop.',
          ),
        ),

      // Live strip — only while the server says the trip is running for us.
      if (c.showLiveMap) ...[
        LiveBanner(
          c.stop?.scheduledAt == null
              ? 'On the bus · live'
              : 'On the bus · ${c.stop!.name} at ${fmtTime(c.stop!.scheduledAt)}',
        ),
        const SizedBox(height: 12),
      ],

      _dayCard(
        'Morning · to school',
        ChipTone.morning,
        morning,
        c,
        emptyLine: c.isMorning || morning.isNotEmpty
            ? 'Nothing recorded yet'
            : 'No morning trip today',
      ),
      const SizedBox(height: 16),
      _dayCard(
        'Afternoon · to home',
        ChipTone.afternoon,
        afternoon,
        c,
        emptyLine: 'Nothing recorded yet',
      ),

      const SizedBox(height: 16),
      _actions(c),

      const SizedBox(height: 16),
      Center(
        child: Text(
          'Times are recorded by the bus attendant, on the bus.',
          textAlign: TextAlign.center,
          style: Z.text(13, color: Z.faint),
        ),
      ),
    ];
  }

  Widget _dayCard(
    String tag,
    ChipTone tone,
    List<TimelineEvent> events,
    FamilyCard c, {
    required String emptyLine,
  }) {
    return ZCard(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionTag(tag, tone: tone),
          const SizedBox(height: 8),
          if (events.isEmpty)
            TimelineRow(time: '—', text: emptyLine, dimmed: true, last: true)
          else
            for (var i = 0; i < events.length; i++)
              TimelineRow(
                time: fmtTime(events[i].at),
                text: events[i].text,
                bold: true,
                last: i == events.length - 1,
              ),
        ],
      ),
    );
  }

  Widget _actions(FamilyCard c) {
    return Column(
      children: [
        if (c.showLiveMap) ...[
          FilledButton(
            onPressed: () => _push(TrackingScreen(
              api: widget.api,
              childId: c.childId,
            )),
            child: const Text('Track the bus'),
          ),
          const SizedBox(height: 10),
        ],
        if (c.showHandoverCode) ...[
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Z.coral,
              foregroundColor: Z.onCoral,
            ),
            onPressed: () => _push(PickupScreen(
              api: widget.api,
              childId: c.childId,
            )),
            child: const Text('Show handover code'),
          ),
          const SizedBox(height: 10),
        ],
        OutlinedButton(
          style: OutlinedButton.styleFrom(
            foregroundColor: c.absent ? Z.teal : Z.coralText,
            side: BorderSide(
                color: c.absent ? Z.turquoise : Z.coral, width: 2),
          ),
          onPressed: () async {
            await _push(AbsentScreen(
              api: widget.api,
              cards: [c],
              initialChildId: c.childId,
            ));
            _load(silent: true);
          },
          child: Text(c.absent ? 'Change absence' : 'Mark absent'),
        ),
        if (c.schoolPhone != null && c.schoolPhone!.isNotEmpty) ...[
          const SizedBox(height: 10),
          // ⚠ PART K9 — the SCHOOL's office number, not the crew's. The API
          // only ever sends a masked crew number, so there is nothing here to
          // dial even if we wanted to. A masked-calling proxy is the real
          // answer when crew contact is needed; see the requirements doc.
          OutlinedButton.icon(
            icon: const Icon(Icons.call_outlined, size: 19),
            onPressed: () => launchUrl(Uri.parse('tel:${c.schoolPhone}')),
            label: Text('Call ${c.school ?? 'school'}'),
          ),
        ],
      ],
    );
  }

  Future<void> _push(Widget screen) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
}
