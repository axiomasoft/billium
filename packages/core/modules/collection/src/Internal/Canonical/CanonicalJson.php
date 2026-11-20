<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Canonical;

use BackedEnum;
use Billium\Contracts\Contracts\Codec;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Packman\Attributes\Internal;

#[Internal]
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return (new Codec)->canonicalize(self::normalize($value));
    }

    /** @param array<array-key, mixed> $value */
    public static function encodeObject(array $value): string
    {
        return $value === [] ? '{}' : self::encode($value);
    }

    public static function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            if ($value instanceof BackedEnum) {
                return $value->value;
            }
            if ($value instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.u\Z');
            }
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = self::normalize($item);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
