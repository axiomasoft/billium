import 'generated/currencies.g.dart';
import 'generated/problem_codes.g.dart';
import 'problem_exception.dart';

final _int64Max = BigInt.parse('9223372036854775807');
final _int64Min = BigInt.parse('-9223372036854775808');

/// An ISO 4217 currency and the number of fraction digits of its minor unit.
final class Currency {
  const Currency._(this.code, this.scale);

  /// The currency [code] of the spec revision.
  ///
  /// Throws [ProblemException] `billium.money.scale_invalid` for a code outside the revision's table.
  factory Currency.of(String code) {
    final scale = currencyScales[code];
    if (scale == null) {
      throw ProblemException(
        ProblemCode.moneyScaleInvalid,
        "unknown currency '$code'",
      );
    }
    return Currency._(code, scale);
  }

  /// The three-letter code.
  final String code;

  /// The number of fraction digits of the minor unit.
  final int scale;

  @override
  bool operator ==(Object other) =>
      other is Currency && other.code == code && other.scale == scale;

  @override
  int get hashCode => Object.hash(code, scale);

  @override
  String toString() => code;
}

/// A sum of money in minor units of one currency; the wire form is a string amount in major units.
///
/// The minor amount is a [BigInt] inside the int64 range: no `int` or `double` carries money.
final class Money {
  /// Creates a sum; throws [ProblemException] `billium.validation.invalid` outside int64.
  Money(this.minor, this.currency) {
    if (minor > _int64Max || minor < _int64Min) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'the amount is outside the int64 range of minor units',
      );
    }
  }

  /// Parses a MoneyWire object.
  ///
  /// Throws [ProblemException] `billium.money.scale_invalid` for a form violation and
  /// `billium.validation.invalid` beyond int64.
  factory Money.fromWire(Object? wire) {
    if (wire is! Map) {
      throw const ProblemException(
        ProblemCode.moneyScaleInvalid,
        'MoneyWire must be an object',
      );
    }
    if (wire.length != 2 ||
        !wire.containsKey('amount') ||
        !wire.containsKey('currency')) {
      throw const ProblemException(
        ProblemCode.moneyScaleInvalid,
        'MoneyWire has exactly amount and currency',
      );
    }
    final amount = wire['amount'];
    final currency = wire['currency'];
    if (amount is! String) {
      throw const ProblemException(
        ProblemCode.moneyScaleInvalid,
        'amount must be a JSON string',
      );
    }
    if (currency is! String) {
      throw const ProblemException(
        ProblemCode.moneyScaleInvalid,
        'currency must be a string',
      );
    }
    return Money.parse(amount, currency);
  }

  /// Parses an [amount] string in the scale of [currency].
  factory Money.parse(String amount, String currency) {
    final unit = Currency.of(currency);
    final pattern = unit.scale == 0
        ? RegExp(r'^-?(0|[1-9][0-9]*)$')
        : RegExp('^-?(0|[1-9][0-9]*)\\.[0-9]{${unit.scale}}\$');
    if (!pattern.hasMatch(amount)) {
      throw ProblemException(
        ProblemCode.moneyScaleInvalid,
        "amount '$amount' does not match the scale of ${unit.code}",
      );
    }
    final negative = amount.startsWith('-');
    final digits = amount.replaceAll('-', '').replaceAll('.', '');
    final minor = BigInt.parse(digits);
    if (negative && minor == BigInt.zero) {
      throw const ProblemException(
        ProblemCode.moneyScaleInvalid,
        'negative zero is not allowed',
      );
    }
    return Money(negative ? -minor : minor, unit);
  }

  /// The amount in minor units.
  final BigInt minor;

  /// The currency.
  final Currency currency;

  /// The amount in major units as the wire string.
  String get amount {
    var digits = minor.abs().toString();
    final scale = currency.scale;
    if (scale > 0) {
      digits = digits.padLeft(scale + 1, '0');
      digits =
          '${digits.substring(0, digits.length - scale)}.${digits.substring(digits.length - scale)}';
    }
    return minor.isNegative ? '-$digits' : digits;
  }

  /// The MoneyWire object.
  Map<String, String> toWire() => {'amount': amount, 'currency': currency.code};

  @override
  bool operator ==(Object other) =>
      other is Money && other.minor == minor && other.currency == currency;

  @override
  int get hashCode => Object.hash(minor, currency);

  @override
  String toString() => '$amount ${currency.code}';
}
