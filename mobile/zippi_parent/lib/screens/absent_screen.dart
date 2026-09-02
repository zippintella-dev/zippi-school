import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../models/family_card.dart';
import '../services/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Screen 8 of the canvas — "Mark absent".
///
/// Chip pickers rather than dropdowns, per the design: who, which day, which
/// trips, and an optional reason. The confirmation replaces the button in place
/// rather than throwing up a dialog.
///
/// ⚠ Idempotent by (child, date, direction) on the server — PART BF3 says never
/// throttle an action someone repeats under pressure, so a double tap is safe
/// by construction rather than by disabling the button.
class AbsentScreen extends StatefulWidget {
  final ApiClient api;
  final List<FamilyCard> cards;
  final int? initialChildId;

  const AbsentScreen({
    required this.api,
    required this.cards,
    this.initialChildId,
    super.key,
  });

  @override
  State<AbsentScreen> createState() => _AbsentScreenState();
}

class _AbsentScreenState extends State<AbsentScreen> {
  late FamilyCard _child;
  late DateTime _date;
  String _direction = 'Both';
  String? _reason;

  bool _busy = false;
  String? _error;
  String? _confirmed;

  @override
  void initState() {
    super.initState();

    _child = widget.cards.firstWhere(
      (c) => c.childId == widget.initialChildId,
      orElse: () => widget.cards.first,
    );

    // Default to today — the server rejects a past date with a readable
    // sentence, and "today" is what a parent marking at 7am means.
    _date = DateTime.tryParse(_child.serviceDate) ?? DateTime.now();
  }

  List<DateTime> get _dayOptions {
    final base = DateTime.tryParse(_child.serviceDate) ?? DateTime.now();
    return [base, base.add(const Duration(days: 1)), base.add(const Duration(days: 2))];
  }

  String _label(DateTime d) {
    final base = DateTime.tryParse(_child.serviceDate) ?? DateTime.now();
    final diff = d.difference(DateTime(base.year, base.month, base.day)).inDays;

    return switch (diff) {
      0 => 'Today · ${DateFormat('EEE d MMM').format(d)}',
      1 => 'Tomorrow · ${DateFormat('EEE d MMM').format(d)}',
      _ => DateFormat('EEE d MMM').format(d),
    };
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final message = await widget.api.markAbsent(
        _child.childId,
        serviceDate: DateFormat('yyyy-MM-dd').format(_date),
        direction: _direction,
        reasonCode: _reason,
      );

      if (mounted) setState(() => _confirmed = message);
    } on ApiException catch (e) {
      // PART P7 — the server's sentence, verbatim. It knows about holidays and
      // past dates; the app should not guess at either.
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _undo() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final message = await widget.api.undoAbsence(
        _child.childId,
        serviceDate: DateFormat('yyyy-MM-dd').format(_date),
      );

      if (mounted) setState(() => _confirmed = message);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            ScreenHeader(
              'Mark absent',
              subtitle: 'The bus will skip your stop that day',
              onBack: () => Navigator.of(context).pop(),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                children: [
                  if (widget.cards.length > 1) ...[
                    _label2('Who is staying home?'),
                    Row(
                      children: [
                        for (final c in widget.cards) ...[
                          ChoiceChipPill(
                            label: c.name.split(' ').first,
                            selected: c.childId == _child.childId,
                            big: true,
                            onTap: () => setState(() {
                              _child = c;
                              _confirmed = null;
                            }),
                          ),
                          if (c != widget.cards.last) const SizedBox(width: 10),
                        ],
                      ],
                    ),
                    const SizedBox(height: 18),
                  ],

                  _label2('Which day?'),
                  Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      for (final d in _dayOptions)
                        ChoiceChipPill(
                          label: _label(d),
                          selected: DateUtils.isSameDay(d, _date),
                          onTap: () => setState(() {
                            _date = d;
                            _confirmed = null;
                          }),
                        ),
                      ChoiceChipPill(
                        label: 'Pick a date',
                        selected: false,
                        onTap: _pickDate,
                      ),
                    ],
                  ),
                  const SizedBox(height: 18),

                  _label2('Which trips?'),
                  Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      for (final d in const [
                        ('Both', 'Both — not riding at all'),
                        ('Morning', 'Morning only'),
                        ('Afternoon', 'Afternoon only'),
                      ])
                        ChoiceChipPill(
                          label: d.$2,
                          selected: _direction == d.$1,
                          onTap: () => setState(() {
                            _direction = d.$1;
                            _confirmed = null;
                          }),
                        ),
                    ],
                  ),
                  const SizedBox(height: 18),

                  _label2('Reason (optional)'),
                  Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      for (final r in const [
                        (null, 'Prefer not to say'),
                        ('sick', 'Unwell'),
                        ('travel', 'Travelling'),
                        ('exam', 'Exam'),
                        ('activity', 'Activity'),
                        ('other', 'Other'),
                      ])
                        ChoiceChipPill(
                          label: r.$2,
                          selected: _reason == r.$1,
                          onTap: () => setState(() => _reason = r.$1),
                        ),
                    ],
                  ),
                  const SizedBox(height: 18),

                  NoteBanner(
                    "The bus won't wait at "
                    '${_child.stop?.name ?? 'your stop'} for a child marked absent.',
                  ),

                  if (_error != null) ...[
                    const SizedBox(height: 14),
                    NoteBanner(_error!, label: '', tone: ChipTone.coral),
                  ],

                  const SizedBox(height: 18),

                  if (_confirmed != null) ...[
                    LiveBanner(_confirmed!, tone: ChipTone.good),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: _busy ? null : _undo,
                      child: const Text('Undo'),
                    ),
                  ] else
                    FilledButton(
                      style: FilledButton.styleFrom(
                        backgroundColor: Z.coral,
                        foregroundColor: Z.onCoral,
                      ),
                      onPressed: _busy ? null : _submit,
                      child: _busy
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2, color: Z.onCoral),
                            )
                          : Text('Mark ${_child.name.split(' ').first} absent · '
                              '${DateFormat('EEE d MMM').format(_date)}'),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _label2(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8, left: 2),
        child: Text(text,
            style: Z.text(14, color: Z.ink, weight: FontWeight.w800)),
      );

  Future<void> _pickDate() async {
    final base = DateTime.tryParse(_child.serviceDate) ?? DateTime.now();

    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      // The server refuses a past date anyway; not offering one is kinder.
      firstDate: base,
      lastDate: base.add(const Duration(days: 60)),
    );

    if (picked != null && mounted) {
      setState(() {
        _date = picked;
        _confirmed = null;
      });
    }
  }
}
