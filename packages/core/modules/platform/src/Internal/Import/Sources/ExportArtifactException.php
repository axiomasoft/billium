<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import\Sources;

use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Thrown when an external export artifact or stream fails fail-closed integrity, boundary or sanitization checks.
 */
#[Internal]
final class ExportArtifactException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
