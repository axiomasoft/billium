<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable claim shape only. Authentication belongs to the host evidence SPI, never to this constructor. */
#[Api(since: '1.0')]
final readonly class RecoveryCoverage
{
    /**
     * @param  list<AccountCoverage>  $perAccountWatermarks
     * @param  list<string>  $projectionValidationReceipts
     * @param  list<string>  $downstreamValidationReceipts
     * @param  list<UnresolvedDisposition>  $unresolved
     * @param  list<NamespaceDisposition>  $namespaceStates
     */
    public function __construct(
        public string $backupLineageRef,
        public DateTimeImmutable $recoveryHorizon,
        public array $perAccountWatermarks,
        public string $dispatchManifestDigest,
        public string $sourceJournalDigest,
        public string $tombstoneDigest,
        public array $projectionValidationReceipts,
        public array $downstreamValidationReceipts,
        public array $unresolved,
        public array $namespaceStates,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
    }
}
