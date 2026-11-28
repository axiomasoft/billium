<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Data\Currency;
use Packman\Attributes\Internal;

/**
 * The ISO 4217 currencies of the spec revision with their scale, read from the package resources.
 */
#[Internal]
final class CurrencyTable
{
    /** @var array<string, int<0, max>>|null */
    private static ?array $scales = null;

    public static function find(string $code): ?Currency
    {
        if ($code === '') {
            return null;
        }
        $scale = self::scales()[$code] ?? null;

        return $scale === null ? null : new Currency($code, $scale);
    }

    /**
     * @return array<string, int<0, max>>
     */
    private static function scales(): array
    {
        if (self::$scales === null) {
            /** @var array<string, int<0, max>> $scales */
            $scales = require dirname(__DIR__, 2).'/resources/currencies.php';
            self::$scales = $scales;
        }

        return self::$scales;
    }
}
