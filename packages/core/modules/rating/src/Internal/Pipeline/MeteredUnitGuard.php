<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Pipeline;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Enums\StrategyId;
use Packman\Attributes\Internal;

#[Internal]
final class MeteredUnitGuard
{
    public const string FIXED_KIND = 'fixed_occurrence/1';

    public const string APPROVED_QUANTITY_KIND = 'approved_quantity/1';

    /**
     * Enforces unit equality across pure quote, prepare, booking recomputation, and correction.
     *
     * @throws ProblemException billium.validation.invalid (receipt policy: none)
     */
    public static function assertMatches(FrozenAggregate $aggregate, PriceVersion $price): void
    {
        $kind = $aggregate->snapshot['kind'] ?? null;

        if ($kind === self::FIXED_KIND) {
            if ($price->strategyId !== StrategyId::Fixed) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'billium.rating.fixed_price_required',
                );
            }

            return;
        }

        $unit = self::resolveAndVerifyFrozenUnit($aggregate);
        if ($unit === null) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.rating.unit_unverified',
            );
        }

        if ($unit !== $price->unit) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                sprintf(
                    'billium.rating.unit_mismatch: aggregate unit "%s" differs from price unit "%s"',
                    $unit,
                    $price->unit,
                ),
            );
        }

        if (is_array($aggregate->quantitySnapshot)) {
            $qsUnit = $aggregate->quantitySnapshot['unit'] ?? null;
            if (! is_string($qsUnit) || $qsUnit !== $price->unit) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'billium.rating.unit_mismatch: quantitySnapshot unit differs from price unit',
                );
            }
        }
    }

    /**
     * Resolves verified unit from FrozenAggregate, embedded snapshot payload, and (for approved_quantity/1)
     * quantitySnapshot WITHOUT mutating $aggregate->unit (preserving pre-F1 InputsDigest bytes!).
     */
    public static function resolveAndVerifyFrozenUnit(FrozenAggregate $aggregate): ?string
    {
        $candidates = [];
        if (is_string($aggregate->unit) && $aggregate->unit !== '') {
            $candidates['explicit'] = $aggregate->unit;
        }
        if (is_string($aggregate->snapshot['unit'] ?? null) && $aggregate->snapshot['unit'] !== '') {
            $candidates['snapshot'] = $aggregate->snapshot['unit'];
        }
        if (($aggregate->snapshot['kind'] ?? null) === self::APPROVED_QUANTITY_KIND
            && is_array($aggregate->quantitySnapshot)
            && is_string($aggregate->quantitySnapshot['unit'] ?? null)
            && $aggregate->quantitySnapshot['unit'] !== '') {
            $candidates['quantitySnapshot'] = $aggregate->quantitySnapshot['unit'];
        }

        if ($candidates === []) {
            return null;
        }

        $unique = array_values(array_unique(array_values($candidates)));
        if (count($unique) > 1) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.rating.unit_representation_mismatch: '.json_encode($candidates, JSON_THROW_ON_ERROR),
            );
        }

        return $unique[0];
    }
}
