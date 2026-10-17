import 'generated/problem_codes.g.dart';

/// A decoded problem object (RFC 9457) whose registry members match the code's entry.
final class Problem {
  /// Creates a problem.
  const Problem({
    required this.code,
    required this.title,
    this.detail,
    this.type,
    this.status,
    this.instance,
  });

  /// The registered code.
  final ProblemCode code;

  /// The short summary.
  final String title;

  /// The occurrence-specific explanation.
  final String? detail;

  /// The problem type URI.
  final String? type;

  /// The HTTP status.
  final int? status;

  /// The occurrence URI.
  final String? instance;
}
