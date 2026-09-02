import 'package:flutter/material.dart';

/// Zippi Parent design system.
///
/// Ported from the Claude Design canvas "Zippi Parent App Redesign"
/// (`Zippi Parent.dc.html`). Every value below is lifted from that document —
/// if you are changing a colour or a radius, change it there first so the
/// canvas and the app do not drift.
///
/// The palette is warm on purpose: a cream page rather than a grey one, with
/// turquoise for anything live and coral for anything the parent must act on.
class Z {
  /* ---------------- brand ---------------- */

  /// Primary action. Buttons, live indicators, focus rings.
  static const turquoise = Color(0xFF40E0D0);
  static const turquoisePressed = Color(0xFF2BC9B8);

  /// Text/icon colour ON turquoise. Dark, not white — turquoise is too light
  /// to carry white text at accessible contrast.
  static const onTurquoise = Color(0xFF123F3A);

  /// The wordmark and any teal-on-cream text.
  static const teal = Color(0xFF0E7C72);
  static const tealSoft = Color(0xFFE0FAF7);
  static const tealSoftBorder = Color(0xFFA9F0E8);

  /// Secondary action, and "this concerns YOUR child right now".
  static const coral = Color(0xFFFF8070);
  static const coralPressed = Color(0xFFFF6A57);
  static const onCoral = Color(0xFF4A1710);
  static const coralText = Color(0xFFE2503C);
  static const coralSoft = Color(0xFFFFE9E2);

  /* ---------------- surfaces ---------------- */

  /// The page. Cream, never grey.
  static const bg = Color(0xFFFFF9F2);
  static const surface = Color(0xFFFFFFFF);

  /// Card outline — warm, so it reads as a soft edge rather than a rule.
  static const cardBorder = Color(0xFFF0E4D6);

  /// Divider inside a card.
  static const divider = Color(0xFFF5EDE1);

  /* ---------------- text ---------------- */

  static const ink = Color(0xFF15212B);
  static const muted = Color(0xFF64748B);
  static const faint = Color(0xFF94A3B8);

  /* ---------------- status ---------------- */

  static const green = Color(0xFF2E7D32);
  static const greenBg = Color(0xFFE9F5EA);
  static const greenBorder = Color(0xFFC9E6CB);

  static const amber = Color(0xFFB26A00);
  static const amberBg = Color(0xFFFFF4E0);
  static const amberSoftBg = Color(0xFFFFF6E6);
  static const amberBorder = Color(0xFFF3DFB8);

  static const blue = Color(0xFF1565C0);
  static const blueBg = Color(0xFFE8F1FB);

  /* ---------------- map ---------------- */

  static const mapLand = Color(0xFFEAF3E6);
  static const mapBlock = Color(0xFFDBEAD2);
  static const mapRoad = Color(0xFFFFFFFF);

  /* ---------------- shape ---------------- */

  static const rCard = 24.0;
  static const rField = 16.0;
  static const rOtp = 14.0;
  static const rPill = 999.0;

  /// Buttons, inputs and chips are all fixed heights in the design.
  static const hButton = 56.0;
  static const hField = 56.0;
  static const hChip = 44.0;
  static const hNav = 64.0;

  /// Minimum tap targets. 44 is the Apple HIG floor; anything
  /// safety-critical gets 56.
  static const tapMin = 44.0;
  static const tapSafety = 56.0;

  /* ---------------- type ---------------- */

  /// Headings, the wordmark, buttons, and the handover code.
  static const display = 'Quicksand';

  /// Everything else.
  static const body = 'Mulish';

  static TextStyle head(double size, {Color color = ink, double? height}) =>
      TextStyle(
        fontFamily: display,
        fontWeight: FontWeight.w700,
        fontSize: size,
        color: color,
        height: height,
      );

  static TextStyle text(double size,
          {Color color = ink, FontWeight weight = FontWeight.w400}) =>
      TextStyle(
        fontFamily: body,
        fontWeight: weight,
        fontSize: size,
        color: color,
      );

  /* ---------------- theme ---------------- */

  static ThemeData theme() {
    final base = ThemeData(
      useMaterial3: true,
      fontFamily: body,
      colorScheme: ColorScheme.fromSeed(
        seedColor: turquoise,
        primary: turquoise,
        onPrimary: onTurquoise,
        surface: surface,
      ),
      scaffoldBackgroundColor: bg,
    );

    return base.copyWith(
      appBarTheme: const AppBarTheme(
        backgroundColor: bg,
        foregroundColor: ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          fontFamily: display,
          color: ink,
          fontSize: 20,
          fontWeight: FontWeight.w700,
        ),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(rCard),
          side: const BorderSide(color: cardBorder, width: 1.5),
        ),
      ),
      // Pill button, dark ink on turquoise.
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: turquoise,
          foregroundColor: onTurquoise,
          disabledBackgroundColor: cardBorder,
          disabledForegroundColor: faint,
          minimumSize: const Size.fromHeight(hButton),
          shape: const StadiumBorder(),
          textStyle: const TextStyle(
            fontFamily: display,
            fontSize: 16,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: teal,
          backgroundColor: surface,
          minimumSize: const Size.fromHeight(hButton),
          side: const BorderSide(color: turquoise, width: 2),
          shape: const StadiumBorder(),
          textStyle: const TextStyle(
            fontFamily: display,
            fontSize: 16,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        hintStyle: text(16, color: faint),
        border: _fieldBorder(cardBorder, 1.5),
        enabledBorder: _fieldBorder(cardBorder, 1.5),
        focusedBorder: _fieldBorder(turquoise, 2),
        errorBorder: _fieldBorder(coralText, 1.5),
        focusedErrorBorder: _fieldBorder(coralText, 2),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: ink,
        contentTextStyle: text(14, color: Colors.white),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(rField)),
      ),
    );
  }

  static OutlineInputBorder _fieldBorder(Color color, double width) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(rField),
        borderSide: BorderSide(color: color, width: width),
      );
}
