<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\EffectKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class MaterialSnapshot
{
    /** @var list<string> */
    public array $evidenceRefs;

    /**
     * @param  array<mixed>  $evidenceRefs
     */
    public function __construct(
        public string $operationRef,
        public EffectKind $kind,
        public string $merchantAccountRef,
        public string $currency,
        public Money $authorizedTotal,
        public Money $capturedTotal,
        public Money $refundedTotal,
        public Money $voidedTotal,
        public string $normalizedStatus,
        public ?string $providerRevision,
        public ?string $stableEffectId,
        public string $materialDigest,
        array $evidenceRefs,
        public DateTimeImmutable $observedAt,
    ) {
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if ($currency === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'currency must not be empty');
        }
        if ($normalizedStatus === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'normalizedStatus must not be empty');
        }
        if ($providerRevision === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerRevision must be null or nonempty');
        }
        if ($stableEffectId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'stableEffectId must be null or nonempty');
        }
        if ($materialDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'materialDigest must not be empty');
        }
        if (! array_is_list($evidenceRefs)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRefs must be a list');
        }
        $evidenceRefs = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRefs has an invalid element');
            }

            return $item;
        }, $evidenceRefs);
        $this->evidenceRefs = $evidenceRefs;
    }
}
