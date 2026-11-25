<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;

#[Internal]
final readonly class HttpLimits
{
    /**
     * @param  int<1, 9007199254740991>  $maxBodyBytes
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
        if (! is_int($bytes) || $bytes < 1 || $bytes > 9007199254740991) {
            throw new InvalidConfiguration(['billium-server.max_body_bytes: must be an integer between 1 and 9007199254740991']);
        }
        if (! is_int($depth) || $depth < 1 || $depth > 511) {
            throw new InvalidConfiguration(['billium-server.max_depth: must be an integer between 1 and 511']);
        }

        return new self($bytes, $depth);
    }
}
