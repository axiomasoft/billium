<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Enums\RoundingMode;
use Billium\Contracts\Exceptions\ProblemException;

function refusal(callable $call): ProblemCode
{
    try {
        $call();
    } catch (ProblemException $exception) {
        return $exception->problem;
    }

    throw new LogicException('expected a ProblemException');
}

it('parses and formats the int64 bounds without wrapping', function (string $amount, string $currency, int $minor): void {
    $money = Money::parse($amount, $currency);

    expect($money->minor)->toBe($minor)
        ->and($money->amount())->toBe($amount)
        ->and($money->toWire())->toBe(['amount' => $amount, 'currency' => $currency]);
})->with([
    'rub max' => ['92233720368547758.07', 'RUB', PHP_INT_MAX],
    'rub min' => ['-92233720368547758.08', 'RUB', PHP_INT_MIN],
    'jpy zero' => ['0', 'JPY', 0],
    'kwd small negative' => ['-0.001', 'KWD', -1],
]);

it('refuses what is not a canonical amount', function (string $amount, ProblemCode $code): void {
    expect(refusal(fn () => Money::parse($amount, 'RUB')))->toBe($code);
})->with([
    'plus sign' => ['+1.00', ProblemCode::MoneyScaleInvalid],
    'leading zero' => ['01.00', ProblemCode::MoneyScaleInvalid],
    'one fraction digit' => ['1.0', ProblemCode::MoneyScaleInvalid],
    'negative zero' => ['-0.00', ProblemCode::MoneyScaleInvalid],
    'exponent' => ['1e2', ProblemCode::MoneyScaleInvalid],
    'trailing newline' => ["1.00\n", ProblemCode::MoneyScaleInvalid],
    'one over max' => ['92233720368547758.08', ProblemCode::ValidationInvalid],
]);

it('refuses a wire object that is not exactly amount and currency', function (mixed $wire): void {
    expect(refusal(fn () => Money::fromWire($wire)))->toBe(ProblemCode::MoneyScaleInvalid);
})->with([
    'number amount' => [(object) ['amount' => 1.5, 'currency' => 'RUB']],
    'integer amount' => [(object) ['amount' => 1, 'currency' => 'JPY']],
    'extra member' => [(object) ['amount' => '1.00', 'currency' => 'RUB', 'x' => 1]],
    'missing currency' => [(object) ['amount' => '1.00']],
    'lowercase currency' => [(object) ['amount' => '1.00', 'currency' => 'rub']],
    'unknown currency' => [(object) ['amount' => '1.00', 'currency' => 'ZZZ']],
    'list' => [['1.00', 'RUB']],
    'string' => ['1.00 RUB'],
]);

it('accepts the wire object as an associative array too', function (): void {
    expect(Money::fromWire(['amount' => '1.50', 'currency' => 'USD'])->minor)->toBe(150);
});

it('decodes a MoneyWire text and refuses a number on the amount', function (): void {
    $codec = new Codec;

    expect($codec->decodeMoney('{"amount":"1490.00","currency":"RUB"}')->minor)->toBe(149000)
        ->and(refusal(fn () => $codec->decodeMoney('{"amount":1490.00,"currency":"RUB"}')))->toBe(ProblemCode::MoneyScaleInvalid)
        ->and(refusal(fn () => $codec->decodeMoney('{"amount":')))->toBe(ProblemCode::ValidationInvalid);
});

it('allocates by largest remainder and always sums to the total', function (): void {
    $math = MoneyMathFactory::create();
    $total = Money::parse('-100.01', 'USD');

    foreach ([['1', '1', '1'], ['7', '0', '13', '5'], ['1', '1000000007'], ['0', '3']] as $ratios) {
        $parts = $math->allocate($total, $ratios);

        expect(array_sum(array_map(fn (Money $part): int => $part->minor, $parts)))->toBe($total->minor)
            ->and($parts)->toHaveCount(count($ratios));
    }
});

it('gives a zero ratio nothing, however the remainder falls', function (): void {
    $parts = MoneyMathFactory::create()->allocate(Money::parse('0.05', 'USD'), ['1', '0', '1', '1']);

    expect(array_map(fn (Money $part): string => $part->amount(), $parts))->toBe(['0.02', '0.00', '0.02', '0.01']);
});

it('refuses ratios that are not non-negative integer strings with one positive', function (array $ratios): void {
    expect(refusal(fn () => MoneyMathFactory::create()->allocate(Money::parse('1.00', 'USD'), $ratios)))->toBe(ProblemCode::ValidationInvalid);
})->with([
    'empty' => [[]],
    'all zero' => [['0', '0']],
    'integer not string' => [[1, 2]],
    'fraction' => [['1.5']],
    'negative' => [['-1', '2']],
    'keyed' => [['a' => '1']],
]);

it('adds in one currency and refuses a mismatch or an overflow', function (): void {
    $math = MoneyMathFactory::create();

    expect($math->add(Money::parse('0.10', 'EUR'), Money::parse('0.20', 'EUR'))->amount())->toBe('0.30')
        ->and(refusal(fn () => $math->add(Money::parse('1.00', 'EUR'), Money::parse('1.00', 'USD'))))->toBe(ProblemCode::MoneyCurrencyMismatch)
        ->and(refusal(fn () => $math->add(new Money(PHP_INT_MAX, Currency::of('EUR')), Money::parse('0.01', 'EUR'))))->toBe(ProblemCode::ValidationInvalid);
});

it('rounds half even and half up on both signs and many fraction digits', function (string $value, string $currency, RoundingMode $mode, string $expected): void {
    $rounded = MoneyMathFactory::create()->round($value, Currency::of($currency), $mode);

    expect($rounded->amount())->toBe($expected);
})->with([
    'half even down' => ['0.025', 'USD', RoundingMode::HalfEven, '0.02'],
    'half even up' => ['0.035', 'USD', RoundingMode::HalfEven, '0.04'],
    'half up negative' => ['-0.025', 'USD', RoundingMode::HalfUp, '-0.03'],
    'long fraction' => ['1.00499999999999999999', 'USD', RoundingMode::HalfUp, '1.00'],
    'negative to zero has no sign' => ['-0.001', 'USD', RoundingMode::HalfUp, '0.00'],
    'jpy' => ['2.5', 'JPY', RoundingMode::HalfEven, '2'],
    'padded' => ['5', 'KWD', RoundingMode::HalfUp, '5.000'],
]);

it('refuses a malformed value and a result beyond int64 when rounding', function (): void {
    $math = MoneyMathFactory::create();
    $usd = Currency::of('USD');

    expect(refusal(fn () => $math->round('1e2', $usd, RoundingMode::HalfUp)))->toBe(ProblemCode::ValidationInvalid)
        ->and(refusal(fn () => $math->round('+1', $usd, RoundingMode::HalfUp)))->toBe(ProblemCode::ValidationInvalid)
        ->and(refusal(fn () => $math->round('92233720368547758.08', $usd, RoundingMode::HalfUp)))->toBe(ProblemCode::ValidationInvalid);
});
