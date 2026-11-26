<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class MerchantRevision
{
    /** @var list<string> */
    public array $currencyCodes;

    /**
     * @param  array<mixed>  $currencyCodes
     */
    public function __construct(
        public string $revisionRef,
        public ExecutionScope $scope,
        public string $merchantAccountRef,
        public string $providerId,
        public string $driverId,
        public string $driverContractVersion,
        public string $capabilitySnapshotRef,
        public string $capabilityDigest,
        public SecretRef $credentialRef,
        public string $credentialVersionRef,
        array $currencyCodes,
        public string $digest,
        public DateTimeImmutable $createdAt,
        public MerchantRevisionPolicy $policy,
    ) {
        if ($revisionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'revisionRef must not be empty');
        }
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if ($providerId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerId must not be empty');
        }
        if ($driverId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverId must not be empty');
        }
        if ($driverContractVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverContractVersion must not be empty');
        }
        if ($capabilitySnapshotRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'capabilitySnapshotRef must not be empty');
        }
        if ($capabilityDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'capabilityDigest must not be empty');
        }
        if ($credentialVersionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'credentialVersionRef must not be empty');
        }
        if (! array_is_list($currencyCodes)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'currencyCodes must be a list');
        }
        $currencyCodes = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'currencyCodes has an invalid element');
            }

            return $item;
        }, $currencyCodes);
        $this->currencyCodes = $currencyCodes;
        if ($digest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'digest must not be empty');
        }
    }
}
