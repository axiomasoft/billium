import 'generated/problem_codes.g.dart';

/// Every refusal of decode and money operations: a registered problem code and a diagnostic detail.
final class ProblemException implements Exception {
  /// Creates the exception for [problem]; [detail] is diagnostic and never compared.
  const ProblemException(this.problem, [this.detail = '']);

  /// The registered code.
  final ProblemCode problem;

  /// What was wrong, for logs.
  final String detail;

  @override
  String toString() =>
      detail.isEmpty ? problem.value : '${problem.value}: $detail';
}
