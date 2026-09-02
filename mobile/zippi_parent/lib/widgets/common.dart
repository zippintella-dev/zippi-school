import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../models/family_card.dart';
import '../theme.dart';

/// The wordmark. Lowercase, Quicksand, teal — as drawn in the canvas.
class ZippiWordmark extends StatelessWidget {
  final double size;

  const ZippiWordmark({this.size = 22, super.key});

  @override
  Widget build(BuildContext context) {
    return Text(
      'zippi',
      style: Z.head(size, color: Z.teal).copyWith(letterSpacing: 0.5),
    );
  }
}

/// The bus mark used on the login screen — a rounded turquoise tile.
class ZippiMark extends StatelessWidget {
  final double size;

  const ZippiMark({this.size = 72, super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: Z.turquoise,
        borderRadius: BorderRadius.circular(size / 3),
      ),
      child: Icon(Icons.directions_bus_rounded,
          size: size * 0.55, color: Z.bg),
    );
  }
}

/// Circular initial avatar. Teal for the child currently in focus, coral
/// otherwise — the canvas alternates them so siblings are distinguishable at a
/// glance without reading the name.
class InitialAvatar extends StatelessWidget {
  final String name;
  final double size;
  final bool coral;

  const InitialAvatar(this.name, {this.size = 52, this.coral = false, super.key});

  @override
  Widget build(BuildContext context) {
    final letter = name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase();

    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: coral ? Z.coralSoft : Z.tealSoft,
        shape: BoxShape.circle,
      ),
      child: Text(letter,
          style: Z.head(size * 0.42, color: coral ? Z.coralText : Z.teal)),
    );
  }
}

/// Status chip with a leading dot.
///
/// ⚠ Colour AND words, always. A parent glancing at this in direct sunlight, or
/// a colour-blind parent, must get the same information as anyone else. The dot
/// is decoration; the label carries the meaning.
class StatusChip extends StatelessWidget {
  final String label;
  final ChipTone tone;
  final bool dot;

  const StatusChip(this.label, {this.tone = ChipTone.neutral, this.dot = true, super.key});

  /// Maps a card's server-decided state onto a tone.
  factory StatusChip.forCard(FamilyCard c) {
    final tone = switch (true) {
      _ when c.absent => ChipTone.neutral,
      _ when c.status == 'not_at_stop' => ChipTone.coral,
      _ when c.status == 'returned_to_school' => ChipTone.coral,
      _ when c.showLiveMap => ChipTone.live,
      _ when const [
            'arrived_at_school',
            'alighted_to_guardian',
            'alighted_self_release',
          ].contains(c.status) =>
        ChipTone.good,
      _ => ChipTone.neutral,
    };

    return StatusChip(c.absent ? 'Marked absent' : c.statusLabel, tone: tone);
  }

  @override
  Widget build(BuildContext context) {
    final (bg, fg, dotColor) = switch (tone) {
      ChipTone.live => (Z.tealSoft, Z.teal, Z.turquoise),
      ChipTone.good => (Z.greenBg, Z.green, Z.green),
      ChipTone.warn => (Z.amberBg, Z.amber, Z.amber),
      ChipTone.coral => (Z.coralSoft, Z.coralText, Z.coral),
      ChipTone.morning => (Z.amberSoftBg, Z.amber, Z.amber),
      ChipTone.afternoon => (Z.blueBg, Z.blue, Z.blue),
      ChipTone.neutral => (Z.divider, Z.muted, Z.faint),
    };

    return Container(
      padding: EdgeInsets.fromLTRB(dot ? 12 : 13, 7, 14, 7),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(Z.rPill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (dot) ...[
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(color: dotColor, shape: BoxShape.circle),
            ),
            const SizedBox(width: 7),
          ],
          Text(label,
              style: Z.text(13, color: fg, weight: FontWeight.w800)),
        ],
      ),
    );
  }
}

enum ChipTone { live, good, warn, coral, neutral, morning, afternoon }

/// A section tag like "MORNING · TO SCHOOL".
class SectionTag extends StatelessWidget {
  final String label;
  final ChipTone tone;

  const SectionTag(this.label, {required this.tone, super.key});

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (tone) {
      ChipTone.morning => (Z.amberSoftBg, Z.amber),
      ChipTone.afternoon => (Z.blueBg, Z.blue),
      ChipTone.good => (Z.greenBg, Z.green),
      _ => (Z.tealSoft, Z.teal),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 6),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(Z.rPill),
      ),
      child: Text(
        label.toUpperCase(),
        style: Z.text(12, color: fg, weight: FontWeight.w800)
            .copyWith(letterSpacing: 1),
      ),
    );
  }
}

/// Selectable pill used for filters and the absence picker.
class ChoiceChipPill extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;
  final bool big;

  const ChoiceChipPill({
    required this.label,
    required this.selected,
    required this.onTap,
    this.big = false,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final child = Container(
      height: big ? Z.hField : Z.hChip,
      padding: EdgeInsets.symmetric(horizontal: big ? 12 : 18),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: selected ? Z.tealSoft : Z.surface,
        borderRadius: BorderRadius.circular(big ? Z.rField : Z.rPill),
        border: Border.all(
          color: selected ? Z.turquoise : Z.cardBorder,
          width: selected ? 2 : 1.5,
        ),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: Z.text(big ? 15 : 14,
            color: selected ? Z.teal : Z.muted,
            weight: selected ? FontWeight.w800 : FontWeight.w700),
      ),
    );

    final tappable = InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(big ? Z.rField : Z.rPill),
      child: child,
    );

    return big ? Expanded(child: tappable) : tappable;
  }
}

/// The standard white card: 24px radius, 1.5px warm border, no shadow.
class ZCard extends StatelessWidget {
  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? borderColor;
  final double borderWidth;

  const ZCard({
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.onTap,
    this.borderColor,
    this.borderWidth = 1.5,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Z.surface,
        borderRadius: BorderRadius.circular(Z.rCard),
        border: Border.all(
            color: borderColor ?? Z.cardBorder, width: borderWidth),
      ),
      clipBehavior: Clip.antiAlias,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
  }
}

/// Label/value row inside a card, with the hairline divider from the canvas.
class DetailRow extends StatelessWidget {
  final String label;
  final String value;
  final bool last;
  final Color? valueColor;

  const DetailRow(this.label, this.value,
      {this.last = false, this.valueColor, super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 11),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: Z.divider)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: Z.text(14, color: Z.muted)),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: Z.text(14,
                  color: valueColor ?? Z.ink, weight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

/// A callout — the amber "Note" block from the canvas.
class NoteBanner extends StatelessWidget {
  final String text;
  final String label;
  final ChipTone tone;

  const NoteBanner(this.text,
      {this.label = 'Note', this.tone = ChipTone.warn, super.key});

  @override
  Widget build(BuildContext context) {
    final (bg, border, fg) = switch (tone) {
      ChipTone.good => (Z.greenBg, Z.greenBorder, Z.green),
      ChipTone.live => (Z.tealSoft, Z.tealSoftBorder, Z.teal),
      _ => (Z.amberBg, Z.amberBorder, Z.amber),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(Z.rField),
        border: Border.all(color: border, width: 1.5),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (label.isNotEmpty) ...[
            Text(label, style: Z.text(14, color: fg, weight: FontWeight.w800)),
            const SizedBox(width: 10),
          ],
          Expanded(child: Text(text, style: Z.text(14, color: fg))),
        ],
      ),
    );
  }
}

/// The live/status strip with a leading dot — used above the handover code and
/// on the tracking sheet.
class LiveBanner extends StatelessWidget {
  final String text;
  final ChipTone tone;

  const LiveBanner(this.text, {this.tone = ChipTone.live, super.key});

  @override
  Widget build(BuildContext context) {
    final (bg, border, fg, dot) = switch (tone) {
      ChipTone.warn => (Z.amberBg, Z.amberBorder, Z.amber, Z.amber),
      ChipTone.good => (Z.greenBg, Z.greenBorder, Z.green, Z.green),
      ChipTone.coral => (Z.coralSoft, Z.coral, Z.coralText, Z.coral),
      _ => (Z.tealSoft, Z.tealSoftBorder, Z.teal, Z.turquoise),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(Z.rField),
        border: Border.all(color: border, width: 1.5),
      ),
      child: Row(
        children: [
          Container(
            width: 10,
            height: 10,
            decoration: BoxDecoration(color: dot, shape: BoxShape.circle),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text,
                style: Z.text(15, color: fg, weight: FontWeight.w800)),
          ),
        ],
      ),
    );
  }
}

/// PART A7 — the day's collection code.
///
/// ⚠ Deliberately the largest thing on its screen. It is read aloud through a
/// bus window, at a kerb, in traffic noise — legibility beats elegance. The
/// canvas sets it at 84px inside a coral-bordered card; keep it there.
///
/// The semanticsLabel spells the digits so a screen reader says "4 7 2 9"
/// rather than "four thousand seven hundred twenty-nine".
class HandoverCodeCard extends StatelessWidget {
  final String code;

  const HandoverCodeCard(this.code, {super.key});

  @override
  Widget build(BuildContext context) {
    return ZCard(
      borderColor: Z.coral,
      borderWidth: 2,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 24),
      child: Column(
        children: [
          Text(
            'HANDOVER CODE',
            style: Z.text(13, color: Z.coralText, weight: FontWeight.w800)
                .copyWith(letterSpacing: 2),
          ),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              code,
              semanticsLabel: 'Handover code ${code.split('').join(' ')}',
              style: Z.head(84, height: 1.1).copyWith(
                letterSpacing: 14,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Show or read this to the bus attendant at the stop. '
            'It changes every day.',
            textAlign: TextAlign.center,
            style: Z.text(14, color: Z.muted),
          ),
        ],
      ),
    );
  }
}

/// A timeline row: fixed-width tabular time, then the event.
class TimelineRow extends StatelessWidget {
  final String time;
  final String text;
  final bool bold;
  final bool dimmed;
  final bool last;
  final Widget? trailing;

  const TimelineRow({
    required this.time,
    required this.text,
    this.bold = false,
    this.dimmed = false,
    this.last = false,
    this.trailing,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final color = dimmed ? Z.faint : Z.ink;

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: Z.divider)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 74,
            child: Text(
              time,
              style: Z.text(14, color: dimmed ? Z.faint : Z.muted).copyWith(
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ),
          Expanded(
            child: Text(
              text,
              style: Z.text(14,
                  color: color,
                  weight: bold ? FontWeight.w700 : FontWeight.w400),
            ),
          ),
          if (trailing != null) ...[const SizedBox(width: 8), trailing!],
        ],
      ),
    );
  }
}

/// Screen title block used on the pushed screens (back chevron + title + sub).
class ScreenHeader extends StatelessWidget {
  final String title;
  final String? subtitle;
  final VoidCallback? onBack;
  final Widget? trailing;

  const ScreenHeader(this.title, {this.subtitle, this.onBack, this.trailing, super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 4, 20, 10),
      child: Row(
        children: [
          if (onBack != null)
            IconButton(
              onPressed: onBack,
              icon: const Icon(Icons.chevron_left_rounded, size: 30),
              color: Z.ink,
              // 44dp minimum tap target.
              constraints: const BoxConstraints(
                  minWidth: Z.tapMin, minHeight: Z.tapMin),
            )
          else
            const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Z.head(20)),
                if (subtitle != null)
                  Text(subtitle!, style: Z.text(13, color: Z.muted)),
              ],
            ),
          ),
          if (trailing != null) trailing!,
        ],
      ),
    );
  }
}

/// An honest failure state. Never a bare spinner that hangs.
class ErrorState extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;

  const ErrorState(this.message, {required this.onRetry, super.key});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off_rounded, size: 44, color: Z.faint),
            const SizedBox(height: 14),
            Text(message,
                textAlign: TextAlign.center, style: Z.text(15, color: Z.muted)),
            const SizedBox(height: 20),
            SizedBox(
              width: 200,
              child: OutlinedButton(
                  onPressed: onRetry, child: const Text('Try again')),
            ),
          ],
        ),
      ),
    );
  }
}

/// Empty state with a friendly line.
class EmptyState extends StatelessWidget {
  final String title;
  final String body;

  const EmptyState(this.title, this.body, {super.key});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(title, style: Z.head(20), textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text(body,
                textAlign: TextAlign.center,
                style: Z.text(15, color: Z.muted).copyWith(height: 1.6)),
          ],
        ),
      ),
    );
  }
}

/// Bottom navigation — Home · Activity · You, per the canvas.
class ZBottomNav extends StatelessWidget {
  final int index;
  final ValueChanged<int> onTap;

  const ZBottomNav({required this.index, required this.onTap, super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Z.surface,
        border: Border(top: BorderSide(color: Z.cardBorder, width: 1.5)),
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            _tab(0, Icons.home_rounded, 'Home'),
            _tab(1, Icons.access_time_rounded, 'Activity'),
            _tab(2, Icons.person_rounded, 'You'),
          ],
        ),
      ),
    );
  }

  Widget _tab(int i, IconData icon, String label) {
    final on = i == index;

    return Expanded(
      child: InkWell(
        onTap: () => onTap(i),
        child: SizedBox(
          // 64dp — safety-critical navigation gets more than the 44 minimum.
          height: Z.hNav,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, size: 22, color: on ? Z.teal : Z.faint),
              const SizedBox(height: 3),
              Text(label,
                  style: Z.text(12,
                      color: on ? Z.teal : Z.faint,
                      weight: on ? FontWeight.w800 : FontWeight.w700)),
            ],
          ),
        ),
      ),
    );
  }
}

/// Formats a time the way the canvas does: "3:42 pm".
String fmtTime(DateTime? t) =>
    t == null ? '—' : DateFormat('h:mm a').format(t).toLowerCase();

String fmtDay(String isoDate) {
  final d = DateTime.tryParse(isoDate);
  return d == null ? isoDate : DateFormat('EEE d MMM').format(d);
}
