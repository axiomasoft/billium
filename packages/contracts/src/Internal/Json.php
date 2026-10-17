<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use JsonException;

/**
 * The only JSON entry point of the package: objects stay stdClass so that "{}" and "[]" and numeric keys survive.
 */
final class Json
{
    /**
     * @throws ProblemException billium.validation.invalid on a syntax error
     */
    public static function decode(string $text): mixed
    {
        try {
            return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid JSON: '.$exception->getMessage());
        }
    }
}
