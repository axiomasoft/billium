<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use Billium\Core\Data\Modules\PublicEventDeclaration;
use Billium\Core\Enums\Modules\BootstrapMode;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * One billium_event_contract row as P3.4 stores it: key (contractRevision, envelopeSchema, eventType) plus the
 * owner and the immutable contract. A `historical` row stays readable for replay after its module is disabled.
 */
#[Api(since: '1.0')]
final readonly class EventContractRegistration
{
    public const string REGISTERED = 'registered';

    public const string HISTORICAL = 'historical';

    public function __construct(
        public string $ownerModuleId,
        public string $contractRevision,
        public string $envelopeSchema,
        public string $eventType,
        public string $payloadSchemaRef,
        public string $payloadSchemaDigest,
        public BootstrapMode $bootstrapMode,
        public ?string $snapshotSourceKey,
        public string $retentionPolicyRef,
        public string $status = self::REGISTERED,
    ) {
        if (! in_array($status, [self::REGISTERED, self::HISTORICAL], true)) {
            throw new InvalidArgumentException("module.event_contract_conflict: unknown registration status {$status}");
        }
    }

    public static function from(string $ownerModuleId, PublicEventDeclaration $declaration): self
    {
        return new self(
            $ownerModuleId,
            $declaration->eventContractRevision,
            $declaration->envelopeSchema,
            $declaration->eventType,
            $declaration->payloadSchemaRef,
            $declaration->payloadSchemaDigest,
            $declaration->bootstrapMode,
            $declaration->snapshotSourceKey,
            $declaration->retentionPolicyRef,
        );
    }

    public function key(): string
    {
        return "{$this->contractRevision}|{$this->envelopeSchema}|{$this->eventType}";
    }

    /** Same owner and contract; status is not part of the contract. */
    public function sameContract(self $other): bool
    {
        return $this->ownerModuleId === $other->ownerModuleId
            && $this->payloadSchemaRef === $other->payloadSchemaRef
            && $this->payloadSchemaDigest === $other->payloadSchemaDigest
            && $this->bootstrapMode === $other->bootstrapMode
            && $this->snapshotSourceKey === $other->snapshotSourceKey
            && $this->retentionPolicyRef === $other->retentionPolicyRef;
    }
}
