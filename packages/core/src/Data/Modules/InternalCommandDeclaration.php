<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/**
 * An internal command on the separate command-outbox lane (D63). It is not a billium.event envelope and never
 * enters the public feed, event catalog or SnapshotSource.
 */
#[Api(since: '1.0')]
final readonly class InternalCommandDeclaration
{
    public function __construct(
        public string $commandType,
        public string $commandSchema,
        public string $payloadSchemaRef,
        public string $payloadSchemaDigest,
    ) {
        $context = "internalCommand {$commandType}";
        if (preg_match('/^[a-z][a-z0-9_.]*\.v[0-9]+$/', $commandType) !== 1) {
            throw Shape::fail($context, 'invalid commandType');
        }
        if (preg_match('/^billium\.command\.v[0-9]+$/', $commandSchema) !== 1) {
            throw Shape::fail($context, 'commandSchema must be billium.command.vN, never an event envelope');
        }
        if ($payloadSchemaRef === '' || preg_match('/^sha256:[0-9a-f]{64}$/', $payloadSchemaDigest) !== 1) {
            throw Shape::fail($context, 'payload schema ref and sha256 digest are required');
        }
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['commandType', 'commandSchema', 'payloadSchemaRef', 'payloadSchemaDigest'], $context);

        return new self(
            Shape::string($data, 'commandType', $context),
            Shape::string($data, 'commandSchema', $context),
            Shape::string($data, 'payloadSchemaRef', $context),
            Shape::string($data, 'payloadSchemaDigest', $context),
        );
    }
}
