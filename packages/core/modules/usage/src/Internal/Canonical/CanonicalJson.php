<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Canonical;

use LogicException;
use stdClass;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new LogicException('float is forbidden in canonical payloads');
        }
        if ($value instanceof stdClass) {
            $values = get_object_vars($value);
            ksort($values, SORT_STRING);

            return (object) array_map(self::normalize(...), $values);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }
            ksort($value, SORT_STRING);

            return (object) array_map(self::normalize(...), $value);
        }
        if (is_object($value)) {
            throw new LogicException('Canonical payloads require explicit JSON data');
        }

        return $value;
    }
}
