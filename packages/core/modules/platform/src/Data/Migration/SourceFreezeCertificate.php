<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Internal\Migration\MigrationDigest;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** D58/D63: authenticated by the source host against its own authority journal. */
#[Api(since: '1.0')]
final readonly class SourceFreezeCertificate
{
    public const array MUTATORS = ['admin', 'api', 'background', 'collection', 'cron', 'direct', 'import', 'ui'];

    /** @param list<string> $closedMutators */
    public function __construct(
        public HandoffPlan $plan,
        public int $freezeGeneration,
        public string $sealReceiptRef,
        public string $sealReceiptDigest,
        public array $closedMutators,
        public string $signatureDigest,
    ) {
        if ($freezeGeneration < 1 || trim($sealReceiptRef) === '' || $closedMutators !== self::MUTATORS
            || ! MigrationDigest::valid($sealReceiptDigest) || ! MigrationDigest::valid($signatureDigest)) {
            throw new InvalidArgumentException('Incomplete source freeze certificate');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['planDigest' => $this->plan->digest(), 'freezeGeneration' => $this->freezeGeneration, 'sealReceiptRef' => $this->sealReceiptRef,
            'sealReceiptDigest' => $this->sealReceiptDigest, 'closedMutators' => $this->closedMutators, 'signatureDigest' => $this->signatureDigest];
    }

    public function digest(): string
    {
        return (new Codec)->digest($this->toArray());
    }
}
