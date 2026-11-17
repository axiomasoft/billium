<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProviderFact
{
    /** @var list<string> */
    public array $evidenceRefs;

    /**
     * @param  array<mixed>  $evidenceRefs
     */
    public function __construct(
        public string $factRef,
        public string $operationRef,
        public string $providerIdentityScope,
        public string $factIdentity,
        public string $materialDigest,
        public ProviderEvidenceMode $mode,
        public MaterialSnapshot $snapshot,
        array $evidenceRefs,
    ) {
        if ($factRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'factRef must not be empty');
        }
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($providerIdentityScope === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerIdentityScope must not be empty');
        }
        if ($factIdentity === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'factIdentity must not be empty');
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
