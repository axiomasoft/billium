<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/** References select immutable host-verified dossiers; their presence alone grants no permission to send. */
#[Api(since: '1.0')]
final readonly class ExecutionOptions
{
    public function __construct(public ?string $releaseEvidenceRef = null, public ?string $retentionPolicyRef = null, public bool $outgoingEnabled = false) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }
        $validator->rejectUnknownKeys($raw, ['release_evidence_ref', 'retention_policy_ref', 'outgoing_enabled'], $path);
        $enabled = $raw['outgoing_enabled'] ?? false;
        if (! is_bool($enabled)) {
            $validator->add($path.'.outgoing_enabled', 'must be a boolean');
            $enabled = false;
        }
        $read = static function (string $key) use ($raw, $path, $validator): ?string {
            $value = $raw[$key] ?? null;
            if ($value !== null && (! is_string($value) || trim($value) === '' || strlen($value) > 200 || str_contains($value, "\0"))) {
                $validator->add($path.'.'.$key, 'must be a bounded nonempty reference or null');

                return null;
            }

            return $value;
        };

        return new self($read('release_evidence_ref'), $read('retention_policy_ref'), $enabled);
    }
}
