<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\BootstrapMode;
use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/**
 * One public event stream a module declares. Platform registers it explicitly at install/upgrade; the
 * strict billium.event.v1 enum is not widened: a new public type needs a negotiated envelope/catalog revision.
 */
#[Api(since: '1.0')]
final readonly class PublicEventDeclaration
{
    /** BC-EVT-03: the frozen seven types of billium.event.v1. */
    public const array STRICT_V1_TYPES = [
        'payment.outcome.v1',
        'obligation.created.v1',
        'obligation.adjusted.v1',
        'invoice.issued.v1',
        'credit_note.issued.v1',
        'allocation.applied.v1',
        'discrepancy.recorded.v1',
    ];

    public function __construct(
        public string $eventType,
        public string $envelopeSchema,
        public string $eventContractRevision,
        public string $payloadSchemaRef,
        public string $payloadSchemaDigest,
        public BootstrapMode $bootstrapMode,
        public ?string $snapshotSourceKey,
        public string $retentionPolicyRef,
    ) {
        $context = "publicEvent {$eventType}";
        if (preg_match('/^[a-z][a-z0-9_.]*\.v[0-9]+$/', $eventType) !== 1) {
            throw Shape::fail($context, 'invalid eventType');
        }
        if (preg_match('/^billium\.event\.v([0-9]+)$/', $envelopeSchema, $envelope) !== 1) {
            throw Shape::fail($context, 'envelopeSchema must be billium.event.vN');
        }
        if (preg_match('/^events-[0-9]{4}-[0-9]{2}-v([0-9]+)$/', $eventContractRevision, $revision) !== 1 || $revision[1] !== $envelope[1]) {
            throw Shape::fail($context, 'eventContractRevision must match the envelope version');
        }
        if ($envelopeSchema === 'billium.event.v1' && ! in_array($eventType, self::STRICT_V1_TYPES, true)) {
            throw Shape::fail($context, 'billium.event.v1 is strict; a new public type needs a negotiated revision');
        }
        if ($payloadSchemaRef === '') {
            throw Shape::fail($context, 'payloadSchemaRef is required');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $payloadSchemaDigest) !== 1) {
            throw Shape::fail($context, 'payloadSchemaDigest must be sha256:<hex>');
        }
        if ($bootstrapMode === BootstrapMode::Snapshot) {
            if ($snapshotSourceKey === null || preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $snapshotSourceKey) !== 1) {
                throw Shape::fail($context, 'a snapshot stream needs a SnapshotSource key <module>.<source>');
            }
        } elseif ($snapshotSourceKey !== null) {
            throw Shape::fail($context, 'a replay_only/non_bootstrappable stream declares no SnapshotSource');
        }
        if ($retentionPolicyRef === '') {
            throw Shape::fail($context, 'retentionPolicyRef is required');
        }
    }

    /** Registry key: (contract revision, envelope schema, event type). */
    public function key(): string
    {
        return "{$this->eventContractRevision}|{$this->envelopeSchema}|{$this->eventType}";
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, [
            'eventType', 'envelopeSchema', 'eventContractRevision', 'payloadSchemaRef', 'payloadSchemaDigest',
            'bootstrapMode', 'retentionPolicyRef',
        ], $context, ['snapshotSourceKey']);
        $mode = BootstrapMode::tryFrom(Shape::string($data, 'bootstrapMode', $context))
            ?? throw Shape::fail($context, 'unknown bootstrapMode');

        return new self(
            eventType: Shape::string($data, 'eventType', $context),
            envelopeSchema: Shape::string($data, 'envelopeSchema', $context),
            eventContractRevision: Shape::string($data, 'eventContractRevision', $context),
            payloadSchemaRef: Shape::string($data, 'payloadSchemaRef', $context),
            payloadSchemaDigest: Shape::string($data, 'payloadSchemaDigest', $context),
            bootstrapMode: $mode,
            snapshotSourceKey: Shape::optionalString($data, 'snapshotSourceKey', $context),
            retentionPolicyRef: Shape::string($data, 'retentionPolicyRef', $context),
        );
    }
}
