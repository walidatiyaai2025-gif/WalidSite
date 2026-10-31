import 'package:flutter/material.dart';

/// Canonical v6 light-first design tokens from design/tokens.json.
/// No bundled commercial font assets; device fallbacks remain available.
abstract final class WaleedPalette {
  static const primary = Color(0xFF163B66);
  static const cta = Color(0xFF126DE1);
  static const accent = Color(0xFF00A999);
  static const canvas = Color(0xFFF5F9FC);
  static const ink = Color(0xFF142E4D);
  static const body = Color(0xFF315471);
  static const border = Color(0xFFD3E3ED);
}

abstract final class WaleedTheme {
  static ThemeData light({required bool arabic}) {
    final scheme = ColorScheme.fromSeed(
      seedColor: WaleedPalette.cta,
      brightness: Brightness.light,
    ).copyWith(
      primary: WaleedPalette.primary,
      secondary: WaleedPalette.accent,
      surface: Colors.white,
      onSurface: WaleedPalette.ink,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: scheme,
      scaffoldBackgroundColor: WaleedPalette.canvas,
      fontFamily: arabic ? 'Arial' : 'Arial',
      appBarTheme: const AppBarTheme(
        backgroundColor: WaleedPalette.canvas,
        foregroundColor: WaleedPalette.ink,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          side: const BorderSide(color: WaleedPalette.border),
          borderRadius: BorderRadius.circular(18),
        ),
      ),
    );
  }
}
