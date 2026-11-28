<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Pipeline;

use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Packman\Attributes\Internal;

#[Internal]
final class InputsDigest
{
    public static function compute(FrozenRatingInputs $inputs): string
    {
        $payload = get_object_vars($inputs);
        $payload['aggregates'] = array_map(static function (FrozenAggregate $aggregate): array {
            $fields = get_object_vars($aggregate);
            if ($fields['quantitySnapshot'] === null) {
                unset($fields['quantitySnapshot']);
            }
            if ($fields['unit'] === null) {
                unset($fields['unit']);
            }

            return $fields;
        }, $inputs->aggregates);
        unset($payload['frontierExpectedDigest'], $payload['frontierExpectedRowVersion']);
        if ($payload['proration'] === null) {
            unset($payload['proration']);
        }
        if ($payload['correctionEvidence'] === null) {
            unset($payload['correctionEvidence']);
        }

        return CanonicalJson::digest($payload);
    }
}
