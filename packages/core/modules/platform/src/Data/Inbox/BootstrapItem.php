<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Billium\Contracts\Attributes\Api;
use InvalidArgumentException;
use stdClass;

/** One immutable snapshot item of a staged bootstrap: an operation at one revision with its material digest. */
#[Api]
final readonly class BootstrapItem
{
    public function __construct(
        public string $operationRef,
        public int $revision,
        public string $materialDigest,
        public stdClass $payload,
        public ?int $id = null,
        public ?int $sessionId = null,
    ) {
        if ($operationRef === '' || strlen($operationRef) > 200 || $revision < 0 || preg_match('/^sha256:[0-9a-f]{64}$/', $materialDigest) !== 1) {
            throw new InvalidArgumentException('Bootstrap item needs an operation, a revision and a sha256 material digest');
        }
    }
}
