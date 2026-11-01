/// Shared, deterministic in-memory search for already-available offline guidance.
/// Search never performs network I/O, crosses locale fields or generates answers.
abstract final class OfflineGuideTextSearch {
  static final RegExp _arabicMarks = RegExp(
    r'[\u064B-\u065F\u0670\u06D6-\u06ED\u0640]',
  );
  static final RegExp _alefVariants = RegExp(r'[\u0622\u0623\u0625\u0671]');
  static final RegExp _whitespace = RegExp(r'\s+');

  /// Tolerate written Arabic without tashkil/tatweel and common Alef/Ya
  /// variants; preserve all actual text and the locale isolation policy.
  static String normalize(String value) => value
      .toLowerCase()
      .replaceAll(_arabicMarks, '')
      .replaceAll(_alefVariants, 'ا')
      .replaceAll('ى', 'ي')
      .replaceAll(_whitespace, ' ')
      .trim();

  /// Every keyword must occur in at least one VISIBLE-LANGUAGE field.
  /// Different words may be found in the title and separate steps, while
  /// longer phrases remain supported through their component keywords.
  static bool matches(String query, Iterable<String> visibleFields) {
    final cleaned = normalize(query);
    if (cleaned.isEmpty) return true;

    final keywords = cleaned.split(' ');
    final fields = visibleFields.map(normalize).toList(growable: false);
    return keywords.every(
      (keyword) => fields.any((field) => field.contains(keyword)),
    );
  }
}
