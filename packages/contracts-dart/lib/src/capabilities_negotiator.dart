import 'generated/problem_codes.g.dart';
import 'problem_exception.dart';

/// Outcome of preflight capability check before proposing a paid metered overage offer.
final class CapabilityCompatibility {
  const CapabilityCompatibility({
    required this.compatible,
    required this.reasons,
  });

  final bool compatible;
  final List<String> reasons;
}

/// Pure negotiator inspecting `billium.capabilities.v2` (or downgraded `v1`) prior to sending commands.
final class CapabilitiesNegotiator {
  const CapabilitiesNegotiator();

  static final RegExp _integerTokenPattern = RegExp(
    r'^(0|[1-9][0-9]{0,23})(\.0{1,9})?$',
  );

  /// Verifies remote server capabilities before offering paid `token` overage (BC-ENV-47).
  /// Requires ALL 4 booking/posting modules: usage, rating, schedule, ledger.
  CapabilityCompatibility checkTokenOverage({
    required Map<String, Object?> capabilitiesPayload,
    String requiredRevision = '2026-12',
    String? expectedSpecHash,
  }) {
    final reasons = <String>[];
    final schema = capabilitiesPayload['schema'];

    if (schema != 'billium.capabilities.v2') {
      reasons.add('server_capabilities_v2_unavailable:$schema');
      return CapabilityCompatibility(compatible: false, reasons: reasons);
    }

    final revisions = capabilitiesPayload['supportedSpecRevisions'];
    var revisionMatched = false;
    if (revisions is List) {
      for (final entry in revisions) {
        if (entry is Map && entry['revision'] == requiredRevision) {
          if (expectedSpecHash == null ||
              entry['specHash'] == expectedSpecHash) {
            revisionMatched = true;
            break;
          }
        }
      }
    }
    if (!revisionMatched) {
      reasons.add('spec_revision_unsupported:$requiredRevision');
    }

    final units = capabilitiesPayload['supportedUnits'];
    if (units is! List || !units.contains('token')) {
      reasons.add('unit_unsupported:token');
    }

    final ingress = capabilitiesPayload['usageIngressSchemas'];
    if (ingress is! List || !ingress.contains('usage.fact.v2')) {
      reasons.add('usage_ingress_unsupported:usage.fact.v2');
    }

    final strategies = capabilitiesPayload['ratingStrategyVersions'];
    if (strategies is! List || !strategies.contains('per_unit@2')) {
      reasons.add('rating_strategy_unsupported:per_unit@2');
    }

    final enabledModules = capabilitiesPayload['enabledModules'];
    final moduleNames = <String>{};
    if (enabledModules is List) {
      for (final m in enabledModules) {
        if (m is Map && m['module'] is String) {
          moduleNames.add(m['module'] as String);
        }
      }
    }
    // schedule is mandatory for booking; ledger is mandatory for obligation posting.
    for (final requiredModule in const [
      'usage',
      'rating',
      'schedule',
      'ledger',
    ]) {
      if (!moduleNames.contains(requiredModule)) {
        reasons.add('module_disabled:$requiredModule');
      }
    }

    return CapabilityCompatibility(
      compatible: reasons.isEmpty,
      reasons: List.unmodifiable(reasons),
    );
  }

  /// Validates BC-ENV-45: when unit is `token`, quantity must be a non-negative integer.
  void validateTokenQuantity(String unit, String quantity) {
    if (unit == 'token' && !_integerTokenPattern.hasMatch(quantity)) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'BC-ENV-45: token quantity must be a non-negative integer',
      );
    }
  }

  /// Validates BC-ENV-46: `per_unit@2` conversion `(numerator * 10^quantityScale) % denominator == 0`.
  void validatePerUnitV2Conversion({
    required String numerator,
    required String denominator,
    required int quantityScale,
  }) {
    final num = BigInt.tryParse(numerator);
    final den = BigInt.tryParse(denominator);
    if (num == null ||
        den == null ||
        num <= BigInt.zero ||
        den <= BigInt.zero ||
        quantityScale < 0 ||
        quantityScale > 12) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'BC-ENV-46: invalid quantityConversion parameters',
      );
    }
    final scaledUnit = num * BigInt.from(10).pow(quantityScale);
    if (scaledUnit % den != BigInt.zero) {
      throw ProblemException(
        ProblemCode.validationInvalid,
        'BC-ENV-46: quantityConversion is not exact at quantityScale=$quantityScale',
      );
    }
  }
}
