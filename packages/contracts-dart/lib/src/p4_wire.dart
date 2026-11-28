import 'capabilities_negotiator.dart';
import 'generated/problem_codes.g.dart';
import 'generated/spec_data.g.dart' as spec_data;
import 'money.dart';
import 'problem_exception.dart';

// BC-ENV-33..46: cross-field wire validation, without financial/calendar calculation.
const p4Schemas = {
  'usage.metric.v1',
  'usage.fact.v2',
  'usage.receipt.v2',
  'usage.aggregate.v1',
  'usage.quantity.v1',
  'rating.price.v1',
  'period.commercial.v1',
  'rating.basis.v1',
  'occurrence.ensure.v2',
  'obligation.prepare.v2',
  'occurrence.no_charge.v1',
};

const _tokenQuantitySchemas = {
  'usage.quantity.v1',
  'usage.aggregate.v1',
  'rating.basis.v1',
};

void validateP4Wire(
  Map<String, Object?> value, {
  String specRevision = spec_data.specRevision,
}) {
  _walk(value);
  final schema = value['schema'];
  if (specRevision == '2026-10') {
    _require(value['unit'] != 'token', 'token unit is unsupported in 2026-10');
    final strategy = value['strategy'];
    if (strategy is Map &&
        strategy['id'] == 'per_unit' &&
        strategy['version'] == 2) {
      _require(false, 'per_unit@2 is unsupported in 2026-10');
    }
  } else {
    const negotiator = CapabilitiesNegotiator();
    if (value['unit'] == 'token') {
      if (schema == 'usage.fact.v2') {
        final factValue = value['value'];
        if (factValue is Map && factValue['kind'] == 'decimal') {
          final quantity = factValue['quantity'];
          _require(
            quantity is String,
            'BC-ENV-45: token quantity must be string',
          );
          negotiator.validateTokenQuantity('token', quantity! as String);
        }
      } else if (_tokenQuantitySchemas.contains(schema)) {
        final quantity = value['quantity'];
        _require(
          quantity is String,
          'BC-ENV-45: token quantity must be string',
        );
        negotiator.validateTokenQuantity('token', quantity! as String);
      }
    }
    if (schema == 'rating.price.v1' && value['strategy'] is Map) {
      final strategy = value['strategy']! as Map;
      if (strategy['id'] == 'per_unit' && strategy['version'] == 2) {
        final conversion = strategy['quantityConversion'];
        _require(
          conversion is Map,
          'BC-ENV-46: per_unit@2 requires quantityConversion',
        );
        final convMap = conversion! as Map;
        final numerator = convMap['numerator'];
        final denominator = convMap['denominator'];
        final quantityScale = convMap['quantityScale'];
        _require(
          numerator is String && denominator is String && quantityScale is int,
          'BC-ENV-46: invalid quantityConversion parameters',
        );
        negotiator.validatePerUnitV2Conversion(
          numerator: numerator! as String,
          denominator: denominator! as String,
          quantityScale: quantityScale! as int,
        );
      }
    }
  }
  if (value['schema'] == 'period.commercial.v1') {
    _instant('${value['localStart']}Z');
    _instant('${value['localEnd']}Z');
  }
  if (value['schema'] == 'usage.aggregate.v1') {
    final completeness = value['completeness']! as Map;
    if (completeness['status'] == 'final') {
      _require(
        _instant(
              completeness['watermark'] as String,
            ).compareTo(_instant((value['window']! as Map)['end'] as String)) >=
            0,
        'final watermark precedes window end',
      );
    }
  }
  if (value['schema'] == 'usage.fact.v2' && value['correction'] is Map) {
    _require(
      ((value['correction']! as Map)['supersedesRevision'] as int) <
          (value['revision']! as int),
      'correction must supersede an earlier revision',
    );
  }
  final period = value['period'];
  if (period is Map) {
    _require(
      value['sellerRef'] == period['sellerRef'],
      'period seller mismatch',
    );
    if (value.containsKey('componentRef')) {
      _require(
        value['componentRef'] == period['componentRef'],
        'period component mismatch',
      );
      final interval = value['interval']! as Map;
      final other = period['interval']! as Map;
      _require(
        interval['start'] == other['start'] && interval['end'] == other['end'],
        'period interval mismatch',
      );
    }
    final attribution = value['attribution'];
    if (attribution is Map) {
      _require(
        attribution['componentRef'] == period['componentRef'] &&
            attribution['agreementRef'] == period['agreementRef'] &&
            attribution['agreementRevision'] == period['agreementRevision'],
        'period attribution mismatch',
      );
    }
  }
  final basis = value['ratedBasis'];
  if (basis is Map) {
    _require(value['sellerRef'] == basis['sellerRef'], 'basis seller mismatch');
  }
  if (value['schema'] == 'rating.basis.v1' &&
      (value['quantityEvidence']! as Map)['kind'] == 'fixed') {
    _require(value['quantity'] == '1', 'fixed quantity must be one');
  }
  if (value['schema'] == 'rating.basis.v1' && value['correction'] is Map) {
    final correction = value['correction']! as Map;
    _require(
      correction['originalRatedBasisRef'] != value['ratedBasisRef'] &&
          correction['originalPeriodRef'] ==
              (value['period']! as Map)['periodRef'],
      'correction must link the original basis/period',
    );
    final currency = (value['amount']! as Map)['currency'];
    _require(
      (correction['previouslyBookedAmount'] as Map)['currency'] == currency &&
          (correction['recomputedAmount'] as Map)['currency'] == currency,
      'correction currency mismatch',
    );
  }
}

void _walk(Object? value) {
  if (value is List) {
    for (final item in value) {
      _walk(item);
    }
    return;
  }
  if (value is! Map) return;
  if (value.containsKey('start') && value.containsKey('end')) {
    _require(
      _instant(value['start'] as String)
              .compareTo(_instant(value['end'] as String)) <
          0,
      'interval must be nonempty [start,end)',
    );
  }
  if (value.containsKey('currency')) Currency.of(value['currency'] as String);
  for (final name in ['eventAt', 'receivedAt', 'effectiveAt', 'watermark']) {
    if (value.containsKey(name)) _instant(value[name] as String);
  }
  if (value.containsKey('minimumMinor')) {
    _require(
      _compare(
                value['minimumMinor'] as String,
                value['maximumMinor'] as String,
              ) <=
              0 &&
          _compare(value['maximumMinor'] as String, '9223372036854775807') <= 0,
      'invalid monetary bounds',
    );
  }
  final tiers = value['tiers'];
  if (tiers is List) {
    String? previous;
    for (final tier in tiers.cast<Map<String, Object?>>()) {
      final threshold = tier['upTo'] as String;
      _require(
        previous == null || _compareDecimal(previous, threshold) < 0,
        'tiers must strictly ascend',
      );
      _require(
        _compareDecimal('0', threshold) < 0,
        'tier threshold must be positive',
      );
      previous = threshold;
    }
  }
  final grouping = value['grouping'];
  if (grouping is List) {
    final names = grouping
        .cast<Map<String, Object?>>()
        .map((item) => item['name'])
        .toList();
    _require(
      names.toSet().length == names.length,
      'duplicate grouping dimension',
    );
  }
  for (final item in value.values) {
    _walk(item);
  }
}

String _instant(String value) {
  final match = RegExp(
    r'^(\d{4})-(\d{2})-(\d{2})T([0-2]\d):([0-5]\d):([0-5]\d)(?:\.(\d{1,9}))?Z$',
  ).firstMatch(value);
  _require(match != null, 'invalid UTC timestamp');
  final year = int.parse(match![1]!);
  final month = int.parse(match[2]!);
  final day = int.parse(match[3]!);
  final parsed = DateTime.utc(year, month, day);
  _require(
    year > 0 &&
        parsed.year == year &&
        parsed.month == month &&
        parsed.day == day &&
        int.parse(match[4]!) < 24,
    'invalid UTC date',
  );
  return '${value.substring(0, 19)}.${(match[7] ?? '').padRight(9, '0')}';
}

int _compare(String a, String b) =>
    a.length != b.length ? a.length.compareTo(b.length) : a.compareTo(b);

int _compareDecimal(String a, String b) {
  final ap = a.split('.');
  final bp = b.split('.');
  final integral = _compare(ap[0], bp[0]);
  if (integral != 0) return integral;
  return (ap.length == 2 ? ap[1] : '')
      .padRight(9, '0')
      .compareTo((bp.length == 2 ? bp[1] : '').padRight(9, '0'));
}

void _require(bool condition, String detail) {
  if (!condition) throw ProblemException(ProblemCode.validationInvalid, detail);
}
