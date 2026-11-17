<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use Billium\Core\Enums\Runtime\FinancialMode;
use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Verified claims of a short-lived host process token (D62). The host authority issues and authenticates it outside the
 * copied Billium database; Billium never stores the token or its credentials, only compares these claims with the
 * adopted financial session under the session row share lock. The expected epoch/mode comes from here, never from
 * copied financial_session metadata.
 */
#[Api(since: '1.0')]
final readonly class FinancialAdmissionToken
{
    /** @param list<FinancialMode> $permittedModes Active and/or Recovery */
    public function __construct(
        public string $installationRef,
        public string $ownerSessionRef,
        public int $deploymentGeneration,
        public int $attachmentGeneration,
        public int $authorityEpoch,
        public array $permittedModes,
        public string $processRef,
        public DateTimeImmutable $expiresAt,
        public string $nonce,
    ) {
        if ($installationRef === '' || $ownerSessionRef === '' || $processRef === '' || $nonce === '') {
            throw new InvalidArgumentException('Admission token claims are nonempty');
        }
        if ($deploymentGeneration < 1 || $attachmentGeneration < 1 || $authorityEpoch < 1) {
            throw new InvalidArgumentException('Admission token generations and epoch start at 1');
        }
        if ($permittedModes === []) {
            throw new InvalidArgumentException('Admission token permits at least one financial mode');
        }
        foreach ($permittedModes as $mode) {
            if (! $mode->financial()) {
                throw new InvalidArgumentException('Admission token permits only Active or Recovery');
            }
        }
    }

    public function permits(FinancialMode $mode): bool
    {
        return in_array($mode, $this->permittedModes, true);
    }

    public function expired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
