<?php

declare(strict_types=1);

namespace Billium\Platform\Data;

use Billium\Contracts\Attributes\Api;

/**
 * Adopted financial session of one epoch (D54/D62). Copied metadata only: it never admits anything by itself; the host
 * process token decides what the current process may do.
 */
#[Api]
final readonly class FinancialSessionView
{
    public function __construct(
        public string $installationRef,
        public int $epoch,
        public string $ownerSessionRef,
        public int $deploymentGeneration,
        public int $attachmentGeneration,
        public int $authorityEpoch,
        public string $state,
        public ?string $activationCertificateRef,
        public ?string $sealedAt,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'installationRef' => $this->installationRef, 'epoch' => $this->epoch, 'ownerSessionRef' => $this->ownerSessionRef,
            'deploymentGeneration' => $this->deploymentGeneration, 'attachmentGeneration' => $this->attachmentGeneration,
            'authorityEpoch' => $this->authorityEpoch, 'state' => $this->state,
            'activationCertificateRef' => $this->activationCertificateRef, 'sealedAt' => $this->sealedAt,
        ];
    }
}
