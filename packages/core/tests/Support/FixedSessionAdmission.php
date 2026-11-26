<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Enums\Runtime\FinancialMode;
use DateTimeImmutable;

/**
 * Mutable token selector only. Construction/set never issues or authenticates authority evidence.
 * Positive fixtures explicitly request independent simulated host issuance before entering a financial root.
 */
final class FixedSessionAdmission implements FinancialSessionAdmission
{
    public function __construct(private ?FinancialAdmissionToken $token = null) {}

    /** @param list<FinancialMode> $modes */
    public static function token(
        string $installationRef = 'inst-test',
        string $ownerSessionRef = 'owner-1',
        int $deploymentGeneration = 1,
        int $attachmentGeneration = 1,
        int $authorityEpoch = 1,
        array $modes = [FinancialMode::Active],
        string $processRef = 'process-1',
        string $expiresIn = '+1 hour',
    ): FinancialAdmissionToken {
        return new FinancialAdmissionToken(
            $installationRef, $ownerSessionRef, $deploymentGeneration, $attachmentGeneration, $authorityEpoch, $modes,
            $processRef, new DateTimeImmutable($expiresIn), bin2hex(random_bytes(16)),
        );
    }

    /** @param array<string, mixed> $claims */
    public static function fromArray(array $claims): FinancialAdmissionToken
    {
        $string = static fn (string $key, string $default): string => is_string($claims[$key] ?? null) ? $claims[$key] : $default;
        $int = static fn (string $key): int => is_int($claims[$key] ?? null) ? $claims[$key] : 1;
        $modes = is_array($claims['modes'] ?? null) ? $claims['modes'] : ['active'];

        return self::token(
            $string('installationRef', 'inst-test'), $string('ownerSessionRef', 'owner-1'), $int('deploymentGeneration'),
            $int('attachmentGeneration'), $int('authorityEpoch'),
            array_values(array_map(static fn (mixed $mode): FinancialMode => FinancialMode::from(is_string($mode) ? $mode : 'active'), $modes)),
            $string('processRef', 'process-1'), $string('expiresIn', '+1 hour'),
        );
    }

    public function currentToken(string $installationRef): ?FinancialAdmissionToken
    {
        return $this->token;
    }

    public function set(?FinancialAdmissionToken $token): void
    {
        $this->token = $token;
    }
}
