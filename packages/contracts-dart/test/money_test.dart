import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

ProblemCode refusal(void Function() call) {
  try {
    call();
  } on ProblemException catch (error) {
    return error.problem;
  }
  fail('expected a ProblemException');
}

void main() {
  group('Money', () {
    for (final (amount, currency, minor) in [
      ('92233720368547758.07', 'RUB', '9223372036854775807'),
      ('-92233720368547758.08', 'RUB', '-9223372036854775808'),
      ('0', 'JPY', '0'),
      ('-0.001', 'KWD', '-1'),
      ('90071992547409.93', 'RUB', '9007199254740993'),
    ]) {
      test('parses and formats $amount $currency without wrapping', () {
        final money = Money.parse(amount, currency);
        expect(money.minor, BigInt.parse(minor));
        expect(money.amount, amount);
        expect(money.toWire(), {'amount': amount, 'currency': currency});
      });
    }

    for (final (amount, code) in [
      ('+1.00', ProblemCode.moneyScaleInvalid),
      ('01.00', ProblemCode.moneyScaleInvalid),
      ('1.0', ProblemCode.moneyScaleInvalid),
      ('-0.00', ProblemCode.moneyScaleInvalid),
      ('1e2', ProblemCode.moneyScaleInvalid),
      ('1.00\n', ProblemCode.moneyScaleInvalid),
      ('92233720368547758.08', ProblemCode.validationInvalid),
    ]) {
      test('refuses the amount ${amount.trim()}', () {
        expect(refusal(() => Money.parse(amount, 'RUB')), code);
      });
    }

    for (final wire in <Object?>[
      {'amount': 1.5, 'currency': 'RUB'},
      {'amount': 1, 'currency': 'JPY'},
      {'amount': '1.00', 'currency': 'RUB', 'x': 1},
      {'amount': '1.00'},
      {'amount': '1.00', 'currency': 'rub'},
      {'amount': '1.00', 'currency': 'ZZZ'},
      ['1.00', 'RUB'],
      '1.00 RUB',
      null,
    ]) {
      test('refuses the wire value $wire', () {
        expect(
          refusal(() => Money.fromWire(wire)),
          ProblemCode.moneyScaleInvalid,
        );
      });
    }

    test('decodes a MoneyWire text and refuses a number on the amount', () {
      const codec = Codec();
      expect(
        codec.decodeMoney('{"amount":"1490.00","currency":"RUB"}').minor,
        BigInt.from(149000),
      );
      expect(
        refusal(() => codec.decodeMoney('{"amount":1490.00,"currency":"RUB"}')),
        ProblemCode.moneyScaleInvalid,
      );
      expect(
        refusal(() => codec.decodeMoney('{"amount":')),
        ProblemCode.validationInvalid,
      );
    });

    test('refuses a minor amount beyond int64', () {
      expect(
        refusal(
          () => Money(BigInt.parse('9223372036854775808'), Currency.of('RUB')),
        ),
        ProblemCode.validationInvalid,
      );
    });
  });

  group('MoneyMath', () {
    const math = MoneyMath();

    test('allocates by largest remainder and always sums to the total', () {
      final total = Money.parse('-100.01', 'USD');
      for (final ratios in [
        ['1', '1', '1'],
        ['7', '0', '13', '5'],
        ['1', '1000000007'],
        ['0', '3'],
        ['340282366920938463463374607431768211456', '1'],
      ]) {
        final parts = math.allocate(total, ratios);
        expect(
          parts.fold(BigInt.zero, (sum, part) => sum + part.minor),
          total.minor,
        );
        expect(parts, hasLength(ratios.length));
      }
    });

    test('gives a zero ratio nothing, however the remainder falls', () {
      final parts = math.allocate(Money.parse('0.05', 'USD'), [
        '1',
        '0',
        '1',
        '1',
      ]);
      expect(
        [for (final part in parts) part.amount],
        ['0.02', '0.00', '0.02', '0.01'],
      );
    });

    for (final ratios in <List<Object?>>[
      [],
      ['0', '0'],
      [1, 2],
      ['1.5'],
      ['-1', '2'],
      ['01'],
      [''],
    ]) {
      test('refuses the ratios $ratios', () {
        expect(
          refusal(() => math.allocate(Money.parse('1.00', 'USD'), ratios)),
          ProblemCode.validationInvalid,
        );
      });
    }

    test('adds in one currency and refuses a mismatch or an overflow', () {
      expect(
        math.add(Money.parse('0.10', 'EUR'), Money.parse('0.20', 'EUR')).amount,
        '0.30',
      );
      expect(
        refusal(
          () =>
              math.add(Money.parse('1.00', 'EUR'), Money.parse('1.00', 'USD')),
        ),
        ProblemCode.moneyCurrencyMismatch,
      );
      expect(
        refusal(
          () => math.add(
            Money.parse('92233720368547758.07', 'EUR'),
            Money.parse('0.01', 'EUR'),
          ),
        ),
        ProblemCode.validationInvalid,
      );
    });

    for (final (value, currency, mode, expected) in [
      ('0.025', 'USD', RoundingMode.halfEven, '0.02'),
      ('0.035', 'USD', RoundingMode.halfEven, '0.04'),
      ('-0.025', 'USD', RoundingMode.halfUp, '-0.03'),
      ('1.00499999999999999999', 'USD', RoundingMode.halfUp, '1.00'),
      ('-0.001', 'USD', RoundingMode.halfUp, '0.00'),
      ('2.5', 'JPY', RoundingMode.halfEven, '2'),
      ('5', 'KWD', RoundingMode.halfUp, '5.000'),
    ]) {
      test('rounds $value $currency ${mode.value} to $expected', () {
        expect(math.round(value, Currency.of(currency), mode).amount, expected);
      });
    }

    test(
      'refuses a malformed value and a result beyond int64 when rounding',
      () {
        final usd = Currency.of('USD');
        expect(
          refusal(() => math.round('1e2', usd, RoundingMode.halfUp)),
          ProblemCode.validationInvalid,
        );
        expect(
          refusal(() => math.round('+1', usd, RoundingMode.halfUp)),
          ProblemCode.validationInvalid,
        );
        expect(
          refusal(
            () => math.round('92233720368547758.08', usd, RoundingMode.halfUp),
          ),
          ProblemCode.validationInvalid,
        );
      },
    );
  });
}
