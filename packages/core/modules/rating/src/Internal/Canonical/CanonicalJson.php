<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Canonical;

use BackedEnum;
use Billium\Rating\Data\Pricing\PriceVersion;
use DateTimeInterface;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class CanonicalJson
{
    public static function termsDigest(PriceVersion $price): string
    {
        return self::digest([
            'terms' => $price->terms,
            'quantityRules' => $price->quantityRules,
            'rounding' => ['mode' => $price->roundingMode->value, 'stage' => $price->roundingStage->value, 'policyVersion' => $price->roundingPolicyVersion],
            'commitment' => $price->commitment,
        ]);
    }

    public static function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::encode($value));
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new LogicException('Float is forbidden in canonical payloads');
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
            ksort($value, SORT_STRING);

            return (object) array_map(self::normalize(...), $value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }
            ksort($value, SORT_STRING);

            return (object) array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
