/// Rounding modes of `money.round` by name (BC-MW-18).
enum RoundingMode {
  /// Half goes to the even neighbour.
  halfEven('HALF_EVEN'),

  /// Half goes away from zero.
  halfUp('HALF_UP');

  const RoundingMode(this.value);

  /// The wire name.
  final String value;

  /// The mode for a wire name, or null for an unknown name.
  static RoundingMode? tryParse(String value) {
    for (final mode in values) {
      if (mode.value == value) return mode;
    }
    return null;
  }
}
