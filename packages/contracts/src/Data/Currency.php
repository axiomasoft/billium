<?php

declare(strict_types=1);

namespace Billium\Contracts\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Contracts\Internal\CurrencyTable;
use Packman\Attributes\Api;

/**
 * An ISO 4217 currency and the number of fraction digits of its minor unit.
 */
#[Api(since: '1.0')]
final readonly class Currency
{
    /**
     * @param  non-empty-string  $code
     * @param  int<0, max>  $scale
     */
    public function __construct(public string $code, public int $scale) {}

    /**
     * @throws ProblemException billium.money.scale_invalid for a code outside the revision's table
     */
    public static function of(string $code): self
    {
        return CurrencyTable::find($code)
            ?? throw new ProblemException(ProblemCode::MoneyScaleInvalid, "unknown currency '{$code}'");
    }
}
