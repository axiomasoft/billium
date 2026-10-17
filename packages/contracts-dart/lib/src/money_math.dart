import 'generated/problem_codes.g.dart';
import 'money.dart';
import 'problem_exception.dart';
import 'rounding_mode.dart';

/// Money arithmetic without floats: [BigInt] intermediates and int64 results (BC-MW-09, BC-MW-10).
abstract interface class MoneyMath {
  /// The package implementation.
  const factory MoneyMath() = _BigIntMoneyMath;

  /// Adds two sums of one currency.
  ///
  /// Throws `billium.money.currency_mismatch`, or `billium.validation.invalid` beyond int64.
  Money add(Money a, Money b);

  /// Largest-remainder allocation: the parts sum to the total, in the order of [ratios] (BC-MW-15, BC-MW-16).
  ///
  /// [ratios] are non-negative integer strings with at least one positive; anything else throws
  /// `billium.validation.invalid`.
  List<Money> allocate(Money total, List<Object?> ratios);

  /// Rounds a decimal string with any number of fraction digits to the scale of [currency] (BC-MW-17).
  Money round(String value, Currency currency, RoundingMode mode);
}

final class _BigIntMoneyMath implements MoneyMath {
  const _BigIntMoneyMath();

  @override
  Money add(Money a, Money b) {
    if (a.currency != b.currency) {
      throw ProblemException(
        ProblemCode.moneyCurrencyMismatch,
        '${a.currency.code} and ${b.currency.code} cannot be added',
      );
    }
    return Money(a.minor + b.minor, a.currency);
  }

  @override
  List<Money> allocate(Money total, List<Object?> ratios) {
    final weights = _parseRatios(ratios);
    final sum = weights.fold(BigInt.zero, (a, b) => a + b);
    final amount = total.minor.abs();
    final bases = <BigInt>[];
    final remainders = <BigInt>[];
    for (final weight in weights) {
      final scaled = amount * weight;
      bases.add(scaled ~/ sum);
      remainders.add(scaled % sum);
    }
    var left = amount - bases.fold(BigInt.zero, (a, b) => a + b);
    final order = List<int>.generate(weights.length, (i) => i)
      ..sort((a, b) {
        final byRemainder = remainders[b].compareTo(remainders[a]);
        return byRemainder != 0 ? byRemainder : a.compareTo(b);
      });
    for (final index in order) {
      if (left == BigInt.zero) break;
      if (weights[index] == BigInt.zero) continue;
      bases[index] += BigInt.one;
      left -= BigInt.one;
    }
    return [
      for (final part in bases)
        Money(total.minor.isNegative ? -part : part, total.currency),
    ];
  }

  @override
  Money round(String value, Currency currency, RoundingMode mode) {
    final match = RegExp(r'^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$')
        .firstMatch(value);
    if (match == null) {
      throw ProblemException(
        ProblemCode.validationInvalid,
        "'$value' is not a decimal string",
      );
    }
    final fraction = match.group(3) ?? '';
    final unscaled = BigInt.parse('${match.group(2)}$fraction');
    final shift = fraction.length - currency.scale;
    var rounded = unscaled;
    if (shift < 0) {
      rounded = unscaled * BigInt.from(10).pow(-shift);
    } else if (shift > 0) {
      final divisor = BigInt.from(10).pow(shift);
      rounded = unscaled ~/ divisor;
      final twice = (unscaled % divisor) * BigInt.two;
      final half = twice.compareTo(divisor);
      final up =
          half > 0 ||
          half == 0 && (mode == RoundingMode.halfUp || rounded.isOdd);
      if (up) rounded += BigInt.one;
    }
    return Money(match.group(1) == '-' ? -rounded : rounded, currency);
  }

  List<BigInt> _parseRatios(List<Object?> ratios) {
    if (ratios.isEmpty) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'ratios must be a non-empty list',
      );
    }
    final pattern = RegExp(r'^(0|[1-9][0-9]*)$');
    final weights = <BigInt>[];
    for (final ratio in ratios) {
      if (ratio is! String || !pattern.hasMatch(ratio)) {
        throw const ProblemException(
          ProblemCode.validationInvalid,
          'a ratio must be a non-negative integer string',
        );
      }
      weights.add(BigInt.parse(ratio));
    }
    if (weights.every((weight) => weight == BigInt.zero)) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'at least one ratio must be positive',
      );
    }
    return weights;
  }
}
