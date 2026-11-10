<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use LogicException;
use stdClass;

final class Canonical
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            ksort($value, SORT_STRING);

            return (object) array_map(self::normalize(...), $value);
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::normalize(...), $value);
        }
        if (is_object($value) || is_float($value)) {
            throw new LogicException('Schedule canonical payload must be explicit JSON without floats');
        }

        return $value;
    }
}
