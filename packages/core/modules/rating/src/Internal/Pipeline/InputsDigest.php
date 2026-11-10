<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Pipeline;

use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Internal\Canonical\CanonicalJson;

final class InputsDigest
{
    public static function compute(FrozenRatingInputs $inputs): string
    {
        $payload = get_object_vars($inputs);
        unset($payload['frontierExpectedDigest'], $payload['frontierExpectedRowVersion']);

        return CanonicalJson::digest($payload);
    }
}
