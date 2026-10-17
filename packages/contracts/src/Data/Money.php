<?php

declare(strict_types=1);

namespace Billium\Contracts\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use stdClass;

/**
 * A sum of money in minor units of one currency; the wire form is a string amount in major units (D29).
 */
#[Api]
final readonly class Money
{
    private const string MAX = '9223372036854775807';

    private const string MIN_DIGITS = '9223372036854775808';

    public function __construct(public int $minor, public Currency $currency) {}

    /**
     * Parses a MoneyWire object (stdClass or an associative array).
     *
     * @throws ProblemException billium.money.scale_invalid for a form violation, billium.validation.invalid beyond int64
     */
    public static function fromWire(mixed $wire): self
    {
        $members = $wire instanceof stdClass ? get_object_vars($wire) : (is_array($wire) ? $wire : null);
        if ($members === null || array_is_list($members) && $members !== []) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'MoneyWire must be an object');
        }
        $keys = array_map(strval(...), array_keys($members));
        sort($keys);
        if ($keys !== ['amount', 'currency']) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'MoneyWire has exactly amount and currency');
        }
        $amount = $members['amount'];
        $code = $members['currency'];
        if (! is_string($amount)) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'amount must be a JSON string');
        }
        if (! is_string($code)) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'currency must be a string');
        }

        return self::parse($amount, $code);
    }

    /**
     * @throws ProblemException
     */
    public static function parse(string $amount, string $currency): self
    {
        $currency = Currency::of($currency);
        $pattern = $currency->scale === 0
            ? '/^-?(0|[1-9][0-9]*)$/D'
            : '/^-?(0|[1-9][0-9]*)\.[0-9]{'.$currency->scale.'}$/D';
        if (preg_match($pattern, $amount) !== 1) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, "amount '{$amount}' does not match the scale of {$currency->code}");
        }
        $negative = $amount[0] === '-';
        $digits = ltrim(str_replace(['-', '.'], '', $amount), '0');
        if ($negative && $digits === '') {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'negative zero is not allowed');
        }
        $limit = $negative ? self::MIN_DIGITS : self::MAX;
        if (strlen($digits) > strlen($limit) || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'amount is outside the int64 range of minor units');
        }

        return new self($digits === '' ? 0 : (int) ($negative ? '-'.$digits : $digits), $currency);
    }

    /**
     * The amount in major units as the wire string.
     */
    public function amount(): string
    {
        $digits = ltrim((string) $this->minor, '-');
        $scale = $this->currency->scale;
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
        }

        return ($this->minor < 0 ? '-' : '').$digits;
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function toWire(): array
    {
        return ['amount' => $this->amount(), 'currency' => $this->currency->code];
    }
}
