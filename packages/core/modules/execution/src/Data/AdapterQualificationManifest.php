<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\QualificationMode;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Pinned adapter evidence and unresolved gaps. Simulated evidence does not qualify a live provider or production egress. */
#[Api(since: '1.0')]
final readonly class AdapterQualificationManifest
{
    /** @var list<string> */
    public array $officialSources;

    /** @var list<string> */
    public array $sandboxEvidenceRefs;

    /** @var list<string> */
    public array $unresolvedGaps;

    /**
     * @param  array<mixed>  $officialSources
     * @param  array<mixed>  $sandboxEvidenceRefs
     * @param  array<mixed>  $unresolvedGaps
     */
    public function __construct(
        public string $manifestRef,
        public string $driverId,
        public string $driverContractVersion,
        public string $providerApiVersion,
        public string $capabilityDigest,
        public string $merchantAccountRef,
        public string $environment,
        array $officialSources,
        public DateTimeImmutable $checkedAt,
        public string $credentialRotationBehavior,
        array $sandboxEvidenceRefs,
        array $unresolvedGaps,
        public QualificationMode $mode,
        public string $qualificationEvidenceRef,
    ) {
        if ($manifestRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'manifestRef must not be empty');
        }
        if ($driverId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverId must not be empty');
        }
        if ($driverContractVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverContractVersion must not be empty');
        }
        if ($providerApiVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerApiVersion must not be empty');
        }
        if ($capabilityDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'capabilityDigest must not be empty');
        }
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if ($environment === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'environment must not be empty');
        }
        if (! array_is_list($officialSources)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'officialSources must be a list');
        }
        $officialSources = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'officialSources has an invalid element');
            }

            return $item;
        }, $officialSources);
        $this->officialSources = $officialSources;
        if ($credentialRotationBehavior === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'credentialRotationBehavior must not be empty');
        }
        if (! array_is_list($sandboxEvidenceRefs)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sandboxEvidenceRefs must be a list');
        }
        $sandboxEvidenceRefs = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'sandboxEvidenceRefs has an invalid element');
            }

            return $item;
        }, $sandboxEvidenceRefs);
        $this->sandboxEvidenceRefs = $sandboxEvidenceRefs;
        if (! array_is_list($unresolvedGaps)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unresolvedGaps must be a list');
        }
        $unresolvedGaps = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'unresolvedGaps has an invalid element');
            }

            return $item;
        }, $unresolvedGaps);
        $this->unresolvedGaps = $unresolvedGaps;
        if ($qualificationEvidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'qualificationEvidenceRef must not be empty');
        }
    }
}
