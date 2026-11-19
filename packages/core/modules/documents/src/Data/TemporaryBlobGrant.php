<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class TemporaryBlobGrant
{
    public function __construct(
        public string $blobRef,
        public string $temporaryUrl,
        public DateTimeImmutable $expiresAt,
    ) {}
}
