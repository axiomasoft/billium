<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;

#[Internal]
final readonly class HttpLimits
{
    /** A body is copied into memory and decoded; single envelopes stay far below 1 MiB, so a larger limit only widens memory exposure. */
    public const int MAX_BODY_BYTES_CEILING = 16_777_216;

    /**
     * @param  int<1, 16777216>  $maxBodyBytes
     * @param  int<1, 511>  $maxDepth
     */
    private function __construct(public int $maxBodyBytes, public int $maxDepth) {}

    public static function fromArray(mixed $config): self
    {
        if (! is_array($config)) {
            throw new InvalidConfiguration(['billium-server: must be an object']);
        }
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['max_body_bytes', 'max_depth'], 'billium-server');
        $validator->failIfAny();

        $bytes = $config['max_body_bytes'] ?? null;
        $depth = $config['max_depth'] ?? null;
        if (! is_int($bytes) || $bytes < 1 || $bytes > self::MAX_BODY_BYTES_CEILING) {
            throw new InvalidConfiguration(['billium-server.max_body_bytes: must be an integer between 1 and 16777216']);
        }
        if (! is_int($depth) || $depth < 1 || $depth > 511) {
            throw new InvalidConfiguration(['billium-server.max_depth: must be an integer between 1 and 511']);
        }

        return new self($bytes, $depth);
    }
}
