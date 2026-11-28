<?php

declare(strict_types=1);

namespace Billium\ClientConformance;

/**
 * One encoded command. Retries reuse these bytes and this key.
 */
final readonly class PreparedCommand
{
    /**
     * @param  array<string, mixed>  $value  decoded object kept only to read identity fields
     */
    public function __construct(
        public string $bytes,
        public string $digest,
        public ?string $commandKey,
        public ?string $operationRef,
        public string $schema,
        public ?int $routingEpoch,
        public string $path,
    ) {}

    /** @return array{bytes: string, digest: string, commandKey: ?string, operationRef: ?string, schema: string, routingEpoch: ?int} */
    public function export(): array
    {
        return [
            'bytes' => $this->bytes,
            'digest' => $this->digest,
            'commandKey' => $this->commandKey,
            'operationRef' => $this->operationRef,
            'schema' => $this->schema,
            'routingEpoch' => $this->routingEpoch,
        ];
    }
}
