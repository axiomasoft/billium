import 'generated/problem_codes.g.dart';
import 'problem_exception.dart';

/// Wire validation only: no execution, fencing or recovery authority (BC-CAP-02/03).
void validatePaymentWire(Map<String, Object?> value) {
  final schema = value['schema'];
  if ((schema == 'payment.capture.v1' ||
          schema == 'payment.void_authorization.v1') &&
      value['operationRef'] == value['originalOperationRef']) {
    throw const ProblemException(
      ProblemCode.validationInvalid,
      'Child operation must differ from its root',
    );
  }
  if (schema != 'payment.driver_lookup.v1' ||
      value['status'] != 'certified_absent') {
    return;
  }
  final certificate = value['absenceCertificate']! as Map;
  for (final key in [
    'merchantAccountRef',
    'environment',
    'selectorKind',
    'selectorValue',
  ]) {
    if (value[key] != certificate[key]) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'Absence certificate scope mismatch',
      );
    }
  }
  final from = DateTime.parse(certificate['dispatchFrom'] as String);
  final through = DateTime.parse(certificate['dispatchThrough'] as String);
  if (from.isAfter(through) ||
      DateTime.parse(certificate['retainedFrom'] as String).isAfter(from) ||
      DateTime.parse(certificate['providerCompleteThrough'] as String)
          .isBefore(through)) {
    throw const ProblemException(
      ProblemCode.validationInvalid,
      'Absence certificate has incomplete coverage',
    );
  }
}
