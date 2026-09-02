import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../models/trip.dart';
import '../theme.dart';

/// The semantic tones. Every one of them ships a word as well as a colour —
/// see [StatusPill] and [ZBanner], neither of which will render colour alone.
enum Tone { live, good, warn, danger, coral, blue, neutral }

/// (background, border, foreground, dot)
({Color bg, Color border, Color fg, Color dot}) toneOf(Tone tone) =>
    switch (tone) {
      Tone.live => (
          bg: Z.tealSoft,
          border: Z.tealSoftBorder,
          fg: Z.teal,
          dot: Z.turquoise
        ),
      Tone.good =>
        (bg: Z.greenBg, border: Z.greenBorder, fg: Z.green, dot: Z.green),
      Tone.warn =>
        (bg: Z.amberBg, border: Z.amberBorder, fg: Z.amber, dot: Z.amber),
      Tone.danger => (bg: Z.redBg, border: Z.red, fg: Z.red, dot: Z.red),
      Tone.coral =>
        (bg: Z.coralSoft, border: Z.coral, fg: Z.coralText, dot: Z.coral),
      Tone.blue =>
        (bg: Z.blueBg, border: Z.blueBorder, fg: Z.blue, dot: Z.blue),
      Tone.neutral =>
        (bg: Z.bg, border: Z.cardBorder, fg: Z.muted, dot: Z.faint),
    };

/// "zippi fleet" — the wordmark, teal + ink.
class FleetWordmark extends StatelessWidget {
  final double size;

  const FleetWordmark({this.size = 30, super.key});

  @override
  Widget build(BuildContext context) {
    return Text.rich(
      TextSpan(
        text: 'zippi',
        style: Z.head(size, color: Z.teal).copyWith(letterSpacing: 0.5),
        children: [
          TextSpan(text: ' fleet', style: Z.head(size, color: Z.ink)),
        ],
      ),
    );
  }
}

/// The rounded turquoise bus tile from the login screen.
class FleetMark extends StatelessWidget {
  final double size;

  const FleetMark({this.size = 72, super.key});

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

/// A child's face.
///
/// ⚠ A PHOTO IS THE POINT. Names on a 56dp row in a bumpy bus are read wrong;
/// faces are not. Where the school has uploaded one it is shown, and the
/// initial tile below is the fallback for a school that has not — not the
/// design.
///
/// The tone comes from the roster, so a child is the same colour on every
/// screen and two siblings at one stop are never the same colour on any.
class ChildAvatar extends StatelessWidget {
  final TripChild child;
  final double size;

  /// Dimmed for a child who is not travelling, so the eye skips them.
  final bool dimmed;

  const ChildAvatar(this.child, {this.size = 56, this.dimmed = false, super.key});

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (child.tone) {
      AvatarTone.teal => (Z.tealSoft, Z.teal),
      AvatarTone.coral => (Z.coralSoft, Z.coralText),
      AvatarTone.amber => (Z.amberBg, Z.amber),
    };

    final photo = child.photoUrl;

    return Opacity(
      opacity: dimmed ? 0.55 : 1,
      child: Container(
        width: size,
        height: size,
        alignment: Alignment.center,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
        child: photo == null
            ? Text(child.initial,
                style: Z.head(size * 0.4, color: fg))
            : Image.network(
                photo,
                width: size,
                height: size,
                fit: BoxFit.cover,
                // A broken photo must not become a blank circle — an unlabelled
                // face is worse than an initial.
                errorBuilder: (_, __, ___) =>
                    Text(child.initial, style: Z.head(size * 0.4, color: fg)),
              ),
      ),
    );
  }
}

/// Status pill with an optional leading dot.
///
/// ⚠ Colour AND words, always. Direct sunlight through a bus window flattens
/// hue; the label carries the meaning and the dot is decoration.
class StatusPill extends StatelessWidget {
  final String label;
  final Tone tone;
  final bool dot;
  final bool outlined;

  const StatusPill(
    this.label, {
    this.tone = Tone.neutral,
    this.dot = false,
    this.outlined = false,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final t = toneOf(tone);

    return Container(
      padding: EdgeInsets.fromLTRB(dot ? 12 : 14, 7, 14, 7),
      decoration: BoxDecoration(
        color: t.bg,
        borderRadius: BorderRadius.circular(Z.rPill),
        border: outlined ? Border.all(color: t.border, width: 1.5) : null,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (dot) ...[
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(color: t.dot, shape: BoxShape.circle),
            ),
            const SizedBox(width: 7),
          ],
          // Flexible so a long label ("Arrived at school · 7:34 AM") shrinks
          // the pill rather than running off the edge of a header row.
          Flexible(
            child: Text(label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Z.text(13, color: t.fg, weight: FontWeight.w800)),
          ),
        ],
      ),
    );
  }
}

/// The standard card: 24px radius, 1.5px warm border, no shadow.
class ZCard extends StatelessWidget {
  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? borderColor;
  final double borderWidth;
  final Color? color;
  final double radius;

  const ZCard({
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.onTap,
    this.borderColor,
    this.borderWidth = 1.5,
    this.color,
    this.radius = Z.rCard,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: color ?? Z.surface,
        borderRadius: BorderRadius.circular(radius),
        border:
            Border.all(color: borderColor ?? Z.cardBorder, width: borderWidth),
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

/// A callout: an icon-free block that says what happened, or why something is
/// blocked, in a sentence.
class ZBanner extends StatelessWidget {
  final String title;
  final String? body;
  final Tone tone;
  final IconData? icon;
  final Widget? action;

  /// Heavier border for a refusal — this is the one the eye must land on.
  final bool emphatic;

  const ZBanner(
    this.title, {
    this.body,
    this.tone = Tone.good,
    this.icon,
    this.action,
    this.emphatic = false,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final t = toneOf(tone);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
      decoration: BoxDecoration(
        color: t.bg,
        borderRadius: BorderRadius.circular(Z.rRow),
        border: Border.all(color: t.border, width: emphatic ? 2 : 1.5),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (icon != null) ...[
                Container(
                  width: Z.tapMin,
                  height: Z.tapMin,
                  decoration: BoxDecoration(color: t.fg, shape: BoxShape.circle),
                  child: Icon(icon, size: 22, color: Colors.white),
                ),
                const SizedBox(width: 12),
              ],
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title,
                        style: Z.text(emphatic ? 17 : 15,
                            color: t.fg, weight: FontWeight.w800)),
                    if (body != null) ...[
                      const SizedBox(height: 4),
                      Text(body!,
                          style: Z.text(14, color: emphatic ? Z.ink : Z.muted)
                              .copyWith(height: 1.5)),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (action != null) ...[const SizedBox(height: 12), action!],
        ],
      ),
    );
  }
}

/// Label/value row inside a card.
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
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: Z.divider)),
      ),
      child: Row(
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

/// Back chevron + title + subtitle, with an optional trailing widget.
class ScreenHeader extends StatelessWidget {
  final String title;
  final String? subtitle;
  final VoidCallback? onBack;
  final Widget? trailing;

  const ScreenHeader(this.title,
      {this.subtitle, this.onBack, this.trailing, super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 6, 16, 8),
      child: Row(
        children: [
          if (onBack != null)
            IconButton(
              onPressed: onBack,
              icon: const Icon(Icons.chevron_left_rounded, size: 30),
              color: Z.ink,
              constraints: const BoxConstraints(
                  minWidth: Z.tapMin, minHeight: Z.tapMin),
            )
          else
            const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Z.head(20), maxLines: 1,
                    overflow: TextOverflow.ellipsis),
                if (subtitle != null)
                  Text(subtitle!,
                      style: Z.text(13, color: Z.muted),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          if (trailing != null) ...[const SizedBox(width: 10), trailing!],
        ],
      ),
    );
  }
}

/// The glanceable "18 of 22 on board" header.
///
/// ⚠ Deliberately the largest thing on the stop list. It is the one number an
/// attendant checks between stops, standing, one-handed.
class CountHeader extends StatelessWidget {
  final int count;
  final int total;
  final String caption;

  const CountHeader(
      {required this.count,
      required this.total,
      this.caption = 'on board',
      super.key});

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 14),
      // ⚠ Scaled down rather than wrapped or clipped. This number is the one
      // thing an attendant checks between stops, at a glance, standing. It has
      // to stay on one line whatever the total is.
      child: FittedBox(
        fit: BoxFit.scaleDown,
        alignment: Alignment.centerLeft,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: [
            Text('$count of $total',
                semanticsLabel: '$count of $total children $caption',
                style: Z.number(40)),
            const SizedBox(width: 10),
            Text(caption,
                style: Z.text(14, color: Z.muted, weight: FontWeight.w700)),
          ],
        ),
      ),
    );
  }
}

/// A full-width pill action drawn in coral — "this needs you now".
class CoralButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final double height;

  const CoralButton(this.label,
      {required this.onPressed, this.icon, this.height = Z.hButton, super.key});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: height,
      width: double.infinity,
      child: FilledButton(
        onPressed: onPressed,
        style: FilledButton.styleFrom(
          backgroundColor: Z.coral,
          foregroundColor: Z.onCoral,
          disabledBackgroundColor: Z.divider,
          disabledForegroundColor: Z.faint,
          shape: const StadiumBorder(),
          textStyle: const TextStyle(
              fontFamily: Z.display, fontSize: 16, fontWeight: FontWeight.w700),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (icon != null) ...[Icon(icon, size: 20), const SizedBox(width: 8)],
            Flexible(
                child: Text(label,
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis)),
          ],
        ),
      ),
    );
  }
}

/// The demo/state switcher shown under a few screens.
///
/// ⚠ Development scaffolding, not a production control. The canvas exposes the
/// same chips so every listed state can be seen without driving a bus; they are
/// gated on [kDebugMode] at the call sites so a release build never ships an
/// attendant a button that fakes a geo-fence.
class StateChips extends StatelessWidget {
  final List<String> labels;
  final int selected;
  final ValueChanged<int> onSelect;

  const StateChips(
      {required this.labels,
      required this.selected,
      required this.onSelect,
      super.key});

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: List.generate(labels.length, (i) {
        final on = i == selected;

        return InkWell(
          onTap: () => onSelect(i),
          borderRadius: BorderRadius.circular(Z.rPill),
          child: Container(
            height: 40,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: on ? Z.tealSoft : Z.surface,
              borderRadius: BorderRadius.circular(Z.rPill),
              border: Border.all(
                  color: on ? Z.turquoise : Z.cardBorder, width: on ? 2 : 1.5),
            ),
            child: Text(labels[i],
                style: Z.text(13,
                    color: on ? Z.teal : Z.muted, weight: FontWeight.w800)),
          ),
        );
      }),
    );
  }
}

/// Empty state — an icon tile, a sentence, and a way out.
class EmptyState extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;
  final Widget? action;

  const EmptyState({
    required this.icon,
    required this.title,
    required this.body,
    this.action,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 40),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 88,
              height: 88,
              decoration: BoxDecoration(
                color: Z.surface,
                borderRadius: BorderRadius.circular(28),
                border: Border.all(color: Z.cardBorder, width: 1.5),
              ),
              child: Icon(icon, size: 42, color: Z.divider),
            ),
            const SizedBox(height: 16),
            Text(title, style: Z.head(20), textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text(body,
                textAlign: TextAlign.center,
                style: Z.text(14, color: Z.muted).copyWith(height: 1.5)),
            if (action != null) ...[const SizedBox(height: 18), action!],
          ],
        ),
      ),
    );
  }
}

/// An honest failure. Never a bare spinner that hangs.
///
/// ⚠ NO CALL SITE YET, deliberately. The brief asks for an error state on every
/// list, and there will be one the moment a screen fetches — but nothing
/// fetches while the app runs on in-memory demo state, and inventing a failure
/// to display would be worse than admitting there is none. This is the shape
/// the API work should reach for, beside [EmptyState].
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
              width: 220,
              child:
                  OutlinedButton(onPressed: onRetry, child: const Text('Try again')),
            ),
          ],
        ),
      ),
    );
  }
}

/* ---------------- formatting ---------------- */

/// "7:11 AM" — the way every time in this app is written.
String fmtTime(DateTime? t) => t == null ? '—' : DateFormat('h:mm a').format(t);

/// "1:47" — a countdown. Always mm:ss, never "107 seconds".
String fmtCountdown(Duration d) {
  final s = d.inSeconds < 0 ? 0 : d.inSeconds;
  return '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';
}

/// "Fri 22 Aug".
String fmtDay(DateTime d) => DateFormat('EEE d MMM').format(d);
